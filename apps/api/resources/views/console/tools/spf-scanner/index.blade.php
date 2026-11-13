@extends('layouts.admin-shell')

@section('title', 'SPF Scanner')

@push('styles')
    <style>
        .spf-tabs {
            display: inline-flex;
            gap: 8px;
            padding: 4px;
            background: rgba(139, 92, 246, 0.06);
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.12));
        }
        .spf-tab-btn {
            background: transparent;
            border: none;
            color: var(--command-os-text-muted, #64748b);
            font-size: 0.8125rem;
            font-weight: 700;
            padding: 8px 18px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
            letter-spacing: 0.05em;
        }
        .spf-tab-btn.active {
            background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%);
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(139, 92, 246, 0.35);
        }
        html[data-command-theme='dark'] .spf-tabs {
            background: rgba(15, 23, 42, 0.6);
            border-color: rgba(139, 92, 246, 0.22);
        }
        .spf-activity-card {
            margin-top: 1.25rem;
            padding: 1rem;
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.16));
            border-radius: 14px;
            background: var(--command-os-surface, rgba(255, 255, 255, 0.72));
        }
        .spf-activity-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 14px;
        }
        .spf-worker-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 230px), 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }
        .spf-worker-card {
            min-width: 0;
            padding: 12px;
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.14));
            border-radius: 12px;
            background: var(--command-os-surface-raised, rgba(255, 255, 255, 0.82));
        }
        .spf-worker-card[data-state='failed'] { border-color: rgba(239, 68, 68, .45); }
        .spf-worker-card[data-state='resolving'] { border-color: rgba(139, 92, 246, .4); }
        .spf-worker-title { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 7px; }
        .spf-worker-domain { overflow-wrap: anywhere; font-family: var(--command-os-font-mono, ui-monospace, monospace); font-size: .75rem; }
        .spf-activity-log {
            max-height: 280px;
            overflow: auto;
            padding: 4px 12px;
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.14));
            border-radius: 12px;
            background: rgba(15, 23, 42, .035);
        }
        .spf-activity-event {
            display: grid;
            grid-template-columns: minmax(105px, 145px) minmax(0, 1fr);
            gap: 8px 14px;
            padding: 9px 0;
            border-bottom: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.11));
            font-size: .78rem;
        }
        .spf-activity-event:last-child { border-bottom: 0; }
        .spf-activity-event time { color: var(--command-os-text-muted, #64748b); font-variant-numeric: tabular-nums; }
        .spf-activity-event[data-level='warning'] strong { color: #b45309; }
        .spf-activity-event[data-level='error'] strong { color: #dc2626; }
        @media (max-width: 600px) {
            .spf-activity-card { padding: .75rem; }
            .spf-activity-event { grid-template-columns: 1fr; gap: 3px; }
            .spf-activity-heading > * { width: 100%; }
        }
        @media (prefers-reduced-motion: reduce) {
            .spf-tabs *, .spf-activity-card * { scroll-behavior: auto !important; transition-duration: .01ms !important; }
        }
    </style>
@endpush

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-search"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // DNS RECORD RECONNAISSANCE</div>
                    <h1 class="command-os-report-hero-title">SPF SCANNER</h1>
                    <p class="command-os-report-hero-desc">Scan large domain lists in the background, then compare saved SPF records against up to 100 IPv4 or IPv6 addresses.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta d-flex align-items-center flex-wrap" style="gap: 8px;">
                    <span class="command-mono-badge"><i class="fas fa-tree mr-1"></i> Roots: {{ number_format($stats['roots']) }}</span>
                    <span class="command-mono-badge"><i class="fas fa-server mr-1"></i> Host entries: {{ number_format($stats['hosts']) }}</span>
                    <span class="command-mono-badge"><i class="fas fa-network-wired mr-1"></i> CIDR entries: {{ number_format($stats['cidrs']) }}</span>
                    <a class="btn-command-action" href="{{ route('console.tools.spf.domains', $workspace->id) }}">
                        <i class="fas fa-globe mr-1"></i> Browse Domains
                    </a>
                </div>
            </div>
        </header>

        <!-- 2. Form Surface Card -->
        <div class="command-os-table-card p-4">
            @if (session('status')) <div class="alert alert-success" role="status" style="border-radius: 10px;">{{ session('status') }}</div> @endif
            @if ($errors->any()) <div class="alert alert-danger" role="alert" style="border-radius: 10px;">{{ $errors->first() }}</div> @endif

            <div class="spf-tabs" role="tablist" aria-label="SPF Scanner tools">
                <button class="spf-tab-btn active" id="spf-search-tab" type="button" role="tab" aria-selected="true" aria-controls="spf-search-panel" data-spf-tab="search">
                    <i class="fas fa-search mr-1"></i> SEARCH BY IP
                </button>
                <button class="spf-tab-btn" id="spf-upload-tab" type="button" role="tab" aria-selected="false" aria-controls="spf-upload-panel" data-spf-tab="upload">
                    <i class="fas fa-file-upload mr-1"></i> SCAN DOMAINS
                </button>
                <button class="spf-tab-btn" id="spf-manual-tab" type="button" role="tab" aria-selected="false" aria-controls="spf-manual-panel" data-spf-tab="manual">
                    <i class="fas fa-terminal mr-1"></i> LIVE DNS LOOKUP
                </button>
            </div>

            <div class="tab-content">
                <!-- 1. Search Panel -->
                <section class="tab-pane active" id="spf-search-panel" role="tabpanel" aria-labelledby="spf-search-tab" data-spf-panel="search">
                    <div class="row align-items-end mb-3">
                        <div class="col-lg-9 mb-3 mb-lg-0">
                            <label class="command-form-label" for="spf-ips"><i class="fas fa-network-wired mr-1 text-purple"></i> IP Addresses:</label>
                            <textarea class="command-form-control" id="spf-ips" rows="3" placeholder="One IPv4 or IPv6 address per line (up to 100)..." style="font-family: monospace;"></textarea>
                            <small class="form-text text-muted mt-1">Comparisons run in the background. The table and CSV read the same saved results. A match is SPF network or mechanism evidence, not a complete SPF pass evaluation. Dynamic DNS checks are capped at {{ number_format(max(1, (int) config('spf.max_search_dns_checks', 10000))) }} unique mechanisms per run.</small>
                        </div>
                        <div class="col-lg-3">
                            <button type="button" class="btn-command-primary w-100" id="spf-search" style="height: 40px;">
                                <i class="fas fa-search mr-1"></i> SEARCH ROOTS
                            </button>
                        </div>
                    </div>
                    <div class="d-flex align-items-center justify-content-between mb-2" style="gap:12px;">
                        <div id="spf-search-status" class="small text-muted" role="status" aria-live="polite"></div>
                        <button type="button" id="spf-search-cancel" class="btn-command-danger" hidden>Cancel comparison</button>
                    </div>
                    <div class="table-responsive">
                        <table id="spf-search-results" class="table table-sm table-striped table-hover command-os-table w-100">
                            <thead><tr><th>Matched IP</th><th>Root</th><th>Include</th><th>CIDR</th><th>SPF</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </section>

                <!-- 2. Upload & Scan Panel -->
                <section class="tab-pane d-none" id="spf-upload-panel" role="tabpanel" aria-labelledby="spf-upload-tab" data-spf-panel="upload">
                    <form method="POST" enctype="multipart/form-data" action="{{ route('console.tools.spf.upload', $workspace->id) }}" class="p-3 mb-4" style="background: rgba(139, 92, 246, 0.04); border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.15)); border-radius: 12px;">
                        @csrf
                        <div class="row align-items-end">
                            <div class="col-12 col-md-6 col-xl-4 mb-3">
                                <label class="command-form-label" for="spf-file"><i class="fas fa-file-alt mr-1 text-purple"></i> Domain list (.txt):</label>
                                <input class="command-form-control p-1" type="file" id="spf-file" name="file" accept=".txt,text/plain" required style="height: 38px;" aria-describedby="spf-upload-help">
                            </div>
                            <div class="col-6 col-md-3 col-xl-2 mb-3">
                                <label class="command-form-label" for="spf-batch-size">Write Batch:</label>
                                <input class="command-form-control" id="spf-batch-size" name="batch_size" type="number" value="25" min="1" max="1000" required style="height: 38px;">
                            </div>
                            <div class="col-6 col-md-3 col-xl-2 mb-3">
                                <label class="command-form-label" for="spf-workers">DNS Workers (1-{{ $maxWorkers }}):</label>
                                <input class="command-form-control" id="spf-workers" name="workers" type="number" value="{{ $maxWorkers }}" min="1" max="{{ $maxWorkers }}" style="height: 38px;">
                            </div>
                            <div class="col-12 col-md-6 col-xl-2 mb-3">
                                <label class="command-form-label" for="spf-partition-count">File processing:</label>
                                <select class="command-form-control" id="spf-partition-count" name="partition_count" style="height: 38px;">
                                    <option value="1">Single file</option>
                                    <option value="4">4 equal parts</option>
                                    <option value="5">5 equal parts</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6 col-xl-2 mb-3">
                                <div class="custom-control custom-switch mb-2 mt-1">
                                    <input class="custom-control-input" type="checkbox" name="skip_existing" id="spf-skip-existing" value="1" checked>
                                    <label class="custom-control-label" for="spf-skip-existing">Skip scanned</label>
                                </div>
                                <button class="btn-command-primary w-100" type="submit" style="height: 38px;" aria-label="Upload domain file and start SPF scan">
                                    <i class="fas fa-upload mr-1"></i> UPLOAD &amp; SCAN
                                </button>
                            </div>
                        </div>
                        <small class="form-text text-muted mt-1" id="spf-upload-help">Up to {{ number_format((int) config('spf.max_domains_per_scan', 10000000)) }} valid domains per file · max {{ number_format($maxUploadBytes / 1024 / 1024) }} MB. Optional 4/5-part mode writes balanced temporary files and keeps the same per-scan limit of {{ $maxWorkers }} DNS workers. Split mode temporarily needs space for one extra copy. File counting happens in the background; worker ranges avoid rereading the full single-file upload.</small>
                    </form>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <h4 class="font-w700 mb-0" style="font-size: 0.875rem; text-transform: uppercase; color: var(--command-os-text, #1e293b);">Active &amp; Past Scans</h4>
                        <small id="spf-scan-updated" class="text-muted" aria-live="polite">Loading…</small>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover command-os-table w-100">
                            <thead><tr><th>ID</th><th>Status</th><th>Progress</th><th>SPF Found</th><th>Workers</th><th>Started</th><th>Action</th></tr></thead>
                            <tbody id="spf-scans"><tr><td colspan="7" class="text-muted">Loading scans…</td></tr></tbody>
                        </table>
                    </div>
                    <section class="spf-activity-card" aria-labelledby="spf-activity-title">
                        <div class="spf-activity-heading">
                            <div>
                                <h4 id="spf-activity-title" class="font-w700 mb-1" style="font-size: .9rem; text-transform: uppercase; color: var(--command-os-text, #1e293b);">
                                    <i class="fas fa-stream mr-1 text-purple" aria-hidden="true"></i> Live scan activity
                                </h4>
                                <small id="spf-activity-updated" class="text-muted">Waiting for scan activity…</small>
                            </div>
                            <label class="mb-0" style="min-width:min(100%, 300px);">
                                <span class="sr-only">Choose scan activity</span>
                                <select id="spf-activity-scan" class="command-form-control" aria-label="Choose scan activity">
                                    <option value="">No scans available</option>
                                </select>
                            </label>
                        </div>
                        <div id="spf-worker-grid" class="spf-worker-grid">
                            <div class="text-muted small">Worker details appear when a scan starts.</div>
                        </div>
                        <div id="spf-activity-log" class="spf-activity-log" role="log" aria-live="polite" aria-relevant="additions text">
                            <div class="text-muted small py-2">Scan events appear here as workers start, resolve DNS, and complete batches.</div>
                        </div>
                    </section>
                </section>

                <!-- 3. Manual Live Panel -->
                <section class="tab-pane d-none" id="spf-manual-panel" role="tabpanel" aria-labelledby="spf-manual-tab" data-spf-panel="manual">
                    <div class="row align-items-end mb-3">
                        <div class="col-lg-8 mb-3 mb-lg-0">
                            <label class="command-form-label" for="spf-manual-domains"><i class="fas fa-globe mr-1 text-purple"></i> Domains (Max 50):</label>
                            <textarea class="command-form-control" id="spf-manual-domains" rows="4" placeholder="One domain per line. Live DNS lookup; results are not saved." style="font-family: monospace;"></textarea>
                        </div>
                        <div class="col-lg-2 mb-2 mb-lg-0">
                            <button type="button" class="btn-command-primary w-100" id="spf-manual-run" style="height: 40px;">
                                <i class="fas fa-check mr-1"></i> CHECK DNS
                            </button>
                        </div>
                        <div class="col-lg-2">
                            <button type="button" class="btn-command-action w-100" id="spf-volume-run" style="height: 40px;">
                                <i class="fas fa-chart-line mr-1"></i> VOLUME
                            </button>
                        </div>
                    </div>
                    <div id="spf-manual-status" class="small text-muted mb-2" role="status" aria-live="polite"></div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-hover command-os-table">
                            <thead><tr><th>Root</th><th>Domain</th><th>Status</th><th>SPF</th></tr></thead>
                            <tbody id="spf-manual-results"><tr><td colspan="4" class="text-muted">Results appear here.</td></tr></tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <!-- Modal Dialog for SPF Record Details -->
    <dialog id="spf-record-dialog" class="command-modal-dialog p-0 border-0 rounded" aria-labelledby="spf-record-title" style="width: min(760px, 94vw); border-radius: 16px;">
        <div class="p-4">
            <div class="d-flex align-items-center justify-content-between pb-2 mb-3 border-bottom">
                <h3 id="spf-record-title" class="mb-0" style="font-size: 1rem; font-weight: 700; color: var(--command-os-text, #1e293b); text-transform: uppercase;">
                    <i class="fas fa-shield-alt mr-1 text-purple"></i> SPF RECORD DETAILS
                </h3>
                <button type="button" class="btn-command-action" data-spf-close aria-label="Close" style="padding: 4px 10px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <pre id="spf-record-content" class="text-monospace text-wrap p-3" style="background: rgba(139, 92, 246, 0.05); border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.15)); border-radius: 8px; font-size: 0.8125rem; max-height: 400px; overflow-y: auto;"></pre>
        </div>
    </dialog>
    <dialog id="spf-feedback-dialog" class="command-modal-dialog p-0 border-0 rounded" aria-labelledby="spf-feedback-title" style="width: min(480px, 94vw); border-radius: 16px;">
        <div class="p-4">
            <h3 id="spf-feedback-title" class="mb-2" style="font-size: 1rem; font-weight: 700; color: var(--command-os-text, #1e293b);">SPF Scanner</h3>
            <p id="spf-feedback-message" class="mb-4" style="color: var(--command-os-text-muted, #64748b);"></p>
            <div class="d-flex justify-content-end" style="gap: 8px;">
                <button id="spf-feedback-cancel" type="button" class="btn-command-action">Cancel</button>
                <button id="spf-feedback-confirm" type="button" class="btn-command-primary">Continue</button>
            </div>
        </div>
    </dialog>
@endsection

@push('scripts')
<script>
    (() => {
        const base = @json(url('/app/workspaces/'.$workspace->id.'/tools/spf-scanner'));
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const uniqueLines = (value) => [...new Set(String(value || '').split(/[\s,;]+/).map((item) => item.trim()).filter(Boolean))];
        const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
        const request = async (url, body) => {
            const response = await fetch(url, {method: 'POST', headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf}, body: JSON.stringify(body)});
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.message || Object.values(payload.errors || {}).flat().join(' ') || payload.error || 'Request failed.');
            return payload;
        };
        const setBusy = (button, value) => { button.disabled = value; button.setAttribute('aria-busy', String(value)); };
        document.querySelectorAll('[data-spf-tab]').forEach((tab) => tab.addEventListener('click', () => {
            document.querySelectorAll('[data-spf-tab]').forEach((item) => { const active = item === tab; item.classList.toggle('active', active); item.setAttribute('aria-selected', String(active)); });
            document.querySelectorAll('[data-spf-panel]').forEach((panel) => { const active = panel.dataset.spfPanel === tab.dataset.spfTab; panel.classList.toggle('active', active); panel.classList.toggle('d-none', !active); });
        }));

        const searchButton = document.getElementById('spf-search');
        let searchIps = [];
        let activeSearchRunId = null;
        let searchPollTimer = null;
        let searchGeneration = 0;
        const searchSamples = new Map();
        const searchStatus = document.getElementById('spf-search-status');
        const cancelSearchButton = document.getElementById('spf-search-cancel');
        const searchTable = window.jQuery?.fn?.DataTable ? window.jQuery('#spf-search-results').DataTable({
            serverSide: true,
            processing: true,
            deferLoading: 0,
            pageLength: 200,
            lengthMenu: [[25, 50, 100, 200, 250], [25, 50, 100, 200, 250]],
            order: [[1, 'asc']],
            ajax: async (data, callback) => {
                const empty = {draw: data.draw, recordsTotal: 0, recordsFiltered: 0, data: []};
                if (!activeSearchRunId) { callback(empty); return; }
                const params = new URLSearchParams({
                    draw: data.draw,
                    start: data.start,
                    length: data.length,
                    'search[value]': data.search?.value || '',
                    'order[0][column]': data.order?.[0]?.column ?? 1,
                    'order[0][dir]': data.order?.[0]?.dir || 'asc',
                });
                try {
                    const response = await fetch(`${base}/search-runs/${encodeURIComponent(activeSearchRunId)}/results?${params}`, {headers:{'Accept':'application/json'}});
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) throw new Error(payload.message || payload.error || 'Unable to load comparison results.');
                    callback(payload);
                } catch (error) {
                    searchStatus.textContent = error.message;
                    callback(empty);
                }
            },
            columns: [
                {data: 'matched_ip', searchable: false},
                {data: 'root', name: 'root'},
                {data: 'include', name: 'include'},
                {data: 'network', searchable: false},
                {data: 'root_id', orderable: false, searchable: false, render: (id, type, row) => id ? `<button type="button" class="btn-command-action" style="padding: 2px 8px; font-size: 0.75rem;" data-spf-record="${escapeHtml(id)}" data-spf-title="${escapeHtml(row.include || '')}"><i class="fas fa-eye mr-1"></i> View</button>` : ''}
            ],
            language: {
                searchPlaceholder: 'Prefix root or include…',
                emptyTable: 'Search IPs to load matching roots',
                zeroRecords: 'No matching roots',
                processing: '<i class="fa fa-spinner fa-spin mr-1"></i> Searching…'
            },
            buttons: [
                {text: 'Export CSV', className: 'btn-command-action', action: () => exportSearch()},
                {text: 'Copy roots (page)', className: 'btn-command-action', action: async (event, table) => {
                    const roots = [...new Set(table.rows({page: 'current'}).data().toArray().map((row) => String(row.root || '').trim()).filter(Boolean))];
                    if (!roots.length) return;
                    try { await navigator.clipboard.writeText(roots.join('\n')); searchStatus.textContent = `Copied ${roots.length} roots.`; }
                    catch { searchStatus.textContent = 'Clipboard access was denied.'; }
                }}
            ]
        }) : null;
        const exportSearch = async () => {
            if (!activeSearchRunId) { searchStatus.textContent = 'Run an IP comparison before exporting.'; return; }
            window.open(`${base}/search-runs/${encodeURIComponent(activeSearchRunId)}/export`, '_blank', 'noopener');
        };
        const pollSearchRun = async (runId, generation) => {
            if (generation !== searchGeneration) return;
            try {
                const response = await fetch(`${base}/search-runs/${encodeURIComponent(runId)}`, {headers:{'Accept':'application/json'}});
                const run = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(run.message || run.error || 'Unable to read comparison status.');
                const progress = `${Number(run.progress || 0).toLocaleString()} / ${Number(run.total_roots || 0).toLocaleString()} domains`;
                const matches = `${Number(run.total_matches || 0).toLocaleString()} matches`;
                const nowMs = Date.now();
                const previous = searchSamples.get(String(run.id));
                const elapsed = previous ? (nowMs - previous.time) / 1000 : 0;
                const rate = previous && elapsed >= 2 && Number(run.progress) > previous.progress ? (Number(run.progress) - previous.progress) / elapsed : 0;
                searchSamples.set(String(run.id), {progress: Number(run.progress || 0), time: nowMs});
                const throughput = rate > 0 ? ` · ${Math.round(rate).toLocaleString()} domains/s` : '';
                const eta = rate > 0 && Number(run.total_roots) > Number(run.progress) ? ` · ETA ${formatDuration((Number(run.total_roots) - Number(run.progress)) / rate)}` : '';
                if (run.status === 'complete') {
                    searchTable?.ajax.reload(null, true);
                    const dnsLimit = run.dns_checks_limited ? ' · DNS lookup cap reached; dynamic-mechanism results may be incomplete' : '';
                    searchStatus.textContent = `Comparison complete: ${progress} · ${matches}${dnsLimit}`;
                    setBusy(searchButton, false);
                    cancelSearchButton.hidden = true;
                    return;
                }
                if (['failed', 'cancelled'].includes(run.status)) {
                    searchStatus.textContent = run.failure || `Comparison ${run.status}: ${progress} · ${matches}`;
                    setBusy(searchButton, false);
                    cancelSearchButton.hidden = true;
                    return;
                }
                const health = run.health === 'queued_overdue' ? ` · no worker picked up the run after ${formatDuration(run.queue_age_seconds || 0)}`
                    : run.health === 'stale_heartbeat' ? ` · no progress checkpoint for ${formatDuration(run.heartbeat_age_seconds || 0)}; resolver or worker may be stalled`
                    : run.status === 'pending' ? ' · waiting for comparison worker'
                    : ` · ${run.stage || 'working'}`;
                searchStatus.textContent = `Comparison ${run.status}: ${progress} · ${matches}${throughput}${eta}${health}`;
                cancelSearchButton.hidden = false;
                searchPollTimer = window.setTimeout(() => pollSearchRun(runId, generation), 2000);
            } catch (error) {
                searchStatus.textContent = error.message;
                setBusy(searchButton, false);
            }
        };
        const runSearch = async () => {
            const parsed = uniqueLines(document.getElementById('spf-ips').value);
            searchIps = parsed.filter((ip) => /^\d{1,3}(?:\.\d{1,3}){3}$/.test(ip) || ip.includes(':'));
            const skipped = parsed.length - searchIps.length;
            if (!searchIps.length || searchIps.length > 100) { searchStatus.textContent = 'Enter between 1 and 100 valid IP addresses.'; return; }
            setBusy(searchButton, true);
            searchStatus.textContent = `Queueing comparison for ${searchIps.length} IP${searchIps.length === 1 ? '' : 's'}${skipped ? ` · ignoring ${skipped} invalid line(s)` : ''}…`;
            if (searchPollTimer) window.clearTimeout(searchPollTimer);
            const generation = ++searchGeneration;
            try {
                cancelSearchButton.hidden = true;
                searchSamples.clear();
                if (activeSearchRunId) {
                    await request(`${base}/search-runs/${encodeURIComponent(activeSearchRunId)}/cancel`, {} ).catch(() => {});
                }
                const run = await request(`${base}/search-runs`, {ips: searchIps});
                activeSearchRunId = run.id;
                if (searchTable) searchTable.ajax.reload(null, true);
                await pollSearchRun(run.id, generation);
            } catch (error) {
                searchStatus.textContent = error.message;
                setBusy(searchButton, false);
            }
        };
        searchButton.addEventListener('click', (event) => { event.preventDefault(); runSearch(); });
        cancelSearchButton.addEventListener('click', async () => {
            if (!activeSearchRunId) return;
            setBusy(cancelSearchButton, true);
            try {
                await request(`${base}/search-runs/${encodeURIComponent(activeSearchRunId)}/cancel`, {});
                searchStatus.textContent = 'Cancellation requested. The comparison worker will stop at its next checkpoint.';
            } catch (error) {
                searchStatus.textContent = error.message;
            } finally {
                setBusy(cancelSearchButton, false);
            }
        });
        document.getElementById('spf-ips').addEventListener('keydown', (event) => { if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') { event.preventDefault(); runSearch(); } });

        const scanTable = document.getElementById('spf-scans');
        const scanSamples = new Map();
        const activitySelect = document.getElementById('spf-activity-scan');
        const workerGrid = document.getElementById('spf-worker-grid');
        const activityLog = document.getElementById('spf-activity-log');
        const activityUpdated = document.getElementById('spf-activity-updated');
        let lastWorkerSignature = '';
        let lastEventSignature = '';
        const formatDuration = (seconds) => {
            const remaining = Math.max(0, Math.floor(seconds));
            if (remaining < 60) return `${remaining}s`;
            if (remaining < 3600) return `${Math.floor(remaining / 60)}m ${remaining % 60}s`;
            return `${Math.floor(remaining / 3600)}h ${Math.floor((remaining % 3600) / 60)}m`;
        };
        const smoothedRate = (samplesById, id, progress, nowMs) => {
            const key = String(id);
            const samples = (samplesById.get(key) || []).filter((sample) => nowMs - sample.time <= 30000);
            samples.push({progress, time: nowMs});
            while (samples.length > 12) samples.shift();
            samplesById.set(key, samples);
            const baseline = samples.find((sample) => nowMs - sample.time >= 15000);
            if (!baseline || progress <= baseline.progress) return 0;
            const elapsed = (nowMs - baseline.time) / 1000;
            return elapsed > 0 ? (progress - baseline.progress) / elapsed : 0;
        };
        const stageLabel = (stage) => String(stage || 'waiting').replaceAll('_', ' ');
        const refreshActivity = async () => {
            const scanId = activitySelect.value;
            if (!scanId) {
                workerGrid.innerHTML = '<div class="text-muted small">Start or select a scan to see live worker status.</div>';
                activityLog.innerHTML = '<div class="text-muted small py-2">No scan selected.</div>';
                activityUpdated.textContent = 'No scan selected';
                return;
            }
            try {
                const response = await fetch(`${base}/scans/${encodeURIComponent(scanId)}/activity`, {headers:{'Accept':'application/json'}});
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || 'The live activity service returned an error.');
                const workers = payload.workers || [];
                const workerSignature = JSON.stringify(workers);
                if (workerSignature !== lastWorkerSignature) {
                    workerGrid.innerHTML = workers.length ? workers.map((worker) => {
                        const age = Number(worker.heartbeat_age_seconds || 0);
                        const health = worker.status === 'resolving' && age >= 15 ? `No resolver update for ${formatDuration(age)}` : `Updated ${formatDuration(age)} ago`;
                        const lookup = worker.last_lookup_duration_ms !== null ? `${Number(worker.last_lookup_duration_ms).toLocaleString()} ms last DNS lookup` : 'No DNS timing yet';
                        return `<article class="spf-worker-card" data-state="${escapeHtml(worker.status)}"><div class="spf-worker-title"><strong>Worker ${escapeHtml(worker.worker)}</strong><span class="badge badge-light">${escapeHtml(worker.status)}</span></div><div class="small text-muted mb-1">${escapeHtml(stageLabel(worker.stage))} · ${escapeHtml(health)}</div><div class="spf-worker-domain mb-2">${escapeHtml(worker.current_domain || 'Waiting for next domain')}</div><div class="small">${Number(worker.domains_processed || 0).toLocaleString()} domains · ${Number(worker.spf_records_found || 0).toLocaleString()} SPF · ${Number(worker.dns_errors || 0).toLocaleString()} DNS errors</div><div class="small text-muted">${escapeHtml(lookup)}${worker.last_batch_duration_ms !== null ? ` · ${Number(worker.last_batch_duration_ms).toLocaleString()} ms batch` : ''}</div></article>`;
                    }).join('') : '<div class="text-muted small">Detailed worker telemetry is available for scans started after this update. Older scans still show progress in the table above.</div>';
                    lastWorkerSignature = workerSignature;
                }
                const events = payload.events || [];
                const eventSignature = events.map((event) => event.id).join(',');
                if (eventSignature !== lastEventSignature) {
                    const wasAtBottom = activityLog.scrollHeight - activityLog.scrollTop - activityLog.clientHeight < 48;
                    activityLog.innerHTML = events.length ? events.map((event) => {
                        const metadata = event.metadata || {};
                        const details = [
                            metadata.worker ? `Worker ${metadata.worker}` : '',
                            metadata.stage ? stageLabel(metadata.stage) : '',
                            metadata.domain || '',
                            metadata.duration_ms ? `${Number(metadata.duration_ms).toLocaleString()} ms` : '',
                            metadata.domains_processed !== undefined ? `${Number(metadata.domains_processed).toLocaleString()} domains complete` : '',
                            metadata.spf_records_found !== undefined ? `${Number(metadata.spf_records_found).toLocaleString()} SPF found` : '',
                            metadata.dns_errors !== undefined ? `${Number(metadata.dns_errors).toLocaleString()} DNS errors` : '',
                        ].filter(Boolean).join(' · ');
                        const time = event.created_at ? new Date(event.created_at).toLocaleTimeString() : '';
                        return `<div class="spf-activity-event" data-level="${escapeHtml(event.level)}"><time>${escapeHtml(time)}</time><div><strong>${escapeHtml(event.message)}</strong>${details ? `<div class="small text-muted mt-1">${escapeHtml(details)}</div>` : ''}</div></div>`;
                    }).join('') : payload.telemetry_available ? '<div class="text-muted small py-2">Waiting for the next worker checkpoint.</div>' : '<div class="text-muted small py-2">This scan started before detailed activity tracking was enabled. New scans show worker phases and resolver delays here.</div>';
                    if (wasAtBottom || !lastEventSignature) activityLog.scrollTop = activityLog.scrollHeight;
                    lastEventSignature = eventSignature;
                }
                const scan = payload.scan || {};
                const preparing = scan.status === 'pending' && ['preparing', 'validating', 'partitioning', 'preparing_ranges'].includes(scan.stage);
                const preparationLabel = scan.stage === 'validating' ? 'domain file' : ['partitioning', 'preparing_ranges'].includes(scan.stage) ? 'balanced work ranges' : 'scan worker';
                activityUpdated.textContent = preparing
                    ? `Preparing ${preparationLabel} · ${Number(scan.preparation_percent || 0)}% of file · refreshed ${new Date().toLocaleTimeString()}`
                    : `Scan ${scan.status || ''} · ${Number(scan.progress || 0).toLocaleString()} / ${Number(scan.total || 0).toLocaleString()} domains · refreshed ${new Date().toLocaleTimeString()}`;
            } catch (error) {
                activityUpdated.textContent = `Unable to refresh activity: ${error.message}`;
            }
        };
        activitySelect.addEventListener('change', () => {
            lastWorkerSignature = '';
            lastEventSignature = '';
            refreshActivity();
        });
        let refreshScansRunning = false;
        const refreshScans = async () => {
            if (refreshScansRunning) return;
            refreshScansRunning = true;
            try {
                const response = await fetch(`${base}/scans`, {headers:{'Accept':'application/json'}});
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || 'The scan status service returned an error.');
                const scans = payload.data || [];
                scanTable.innerHTML = scans.length ? scans.map((scan) => {
                    const canStop = ['pending', 'scanning'].includes(scan.status);
                    const total = Number(scan.progress_total || 0);
                    const exactPct = total ? Math.min(100, Number(scan.progress || 0) / total * 100) : 0;
                    const isPreparing = scan.status === 'pending' && ['preparing', 'validating', 'partitioning', 'preparing_ranges'].includes(scan.stage);
                    const preparationPct = Math.min(100, Number(scan.preparation_percent || 0));
                    const displayPct = isPreparing ? preparationPct : exactPct;
                    const barPct = displayPct > 0 ? Math.max(0.5, displayPct) : 0;
                    const nowMs = Date.now();
                    const rate = smoothedRate(scanSamples, scan.id, Number(scan.progress || 0), nowMs);
                    const throughput = rate > 0 ? ` · ${Math.round(rate).toLocaleString()} domains/s` : '';
                    const eta = rate > 0 && total > Number(scan.progress || 0) ? ` · ETA ${formatDuration((total - Number(scan.progress || 0)) / rate)}` : '';
                    const healthText = scan.health === 'preparing' ? `Preparing input · ${preparationPct}%`
                        : scan.health === 'queued_overdue' ? `No SPF worker picked this up after ${formatDuration(scan.queue_age_seconds || 0)}`
                        : scan.health === 'queued' ? 'Waiting for an SPF worker'
                        : scan.health === 'stale_heartbeat' ? `Worker heartbeat missing for ${scan.heartbeat_age_seconds}s`
                        : scan.health === 'no_heartbeat' ? 'Worker has not reported a heartbeat'
                        : scan.health === 'no_recent_progress' ? `No completed batch for ${scan.progress_age_seconds}s · DNS lookup may be slow`
                        : scan.current_domain ? `Working around: ${scan.current_domain}`
                        : scan.stage || '';
                    const healthClass = ['stale_heartbeat', 'no_heartbeat'].includes(scan.health) ? 'text-danger' : 'text-muted';
                    const progress = isPreparing ? `Preparing file · ${preparationPct}%` : total ? `${Number(scan.progress || 0).toLocaleString()} / ${total.toLocaleString()} · ${exactPct.toFixed(2)}%` : 'Waiting for worker…';
                    const partitionLabel = Number(scan.partition_count || 1) === 1 ? 'single file' : `${Number(scan.partition_count)} equal parts`;
                    return `<tr><td>${escapeHtml(scan.id)}</td><td>${escapeHtml(scan.status)}<div class="small ${healthClass}">${escapeHtml(healthText)}</div>${scan.failure ? `<div class="text-danger small">${escapeHtml(scan.failure)}</div>` : ''}</td><td><div class="progress" style="min-width:150px; height:6px; border-radius:999px;"><div class="progress-bar bg-purple" role="progressbar" style="width:${barPct}%" aria-valuenow="${displayPct.toFixed(2)}" aria-valuemin="0" aria-valuemax="100"></div></div><small>${escapeHtml(progress + (isPreparing ? '' : throughput + eta))}</small></td><td>${escapeHtml(scan.total_found)}</td><td>${escapeHtml(scan.workers)}<div class="small text-muted">${escapeHtml(partitionLabel)}</div></td><td>${escapeHtml(scan.started_at || scan.created_at || '')}</td><td>${canStop ? `<button type="button" class="btn-command-danger" style="padding:2px 8px;font-size:.75rem;" data-spf-kill="${escapeHtml(scan.id)}">STOP</button>` : '—'}</td></tr>`;
                }).join('') : '<tr><td colspan="7" class="text-muted">No scans yet.</td></tr>';
                document.getElementById('spf-scan-updated').textContent = `Updated ${new Date().toLocaleTimeString()}`;
                const preferred = scans.find((scan) => ['pending', 'scanning'].includes(scan.status)) || scans[0];
                const priorSelection = activitySelect.value;
                activitySelect.innerHTML = scans.length ? scans.map((scan) => {
                    const total = Number(scan.progress_total || 0);
                    const percent = scan.health === 'preparing' ? `preparing ${Number(scan.preparation_percent || 0)}%` : total ? `${Math.min(100, Number(scan.progress || 0) / total * 100).toFixed(1)}%` : 'queued';
                    return `<option value="${escapeHtml(scan.id)}">Scan ${escapeHtml(scan.id)} · ${escapeHtml(scan.status)} · ${escapeHtml(percent)}</option>`;
                }).join('') : '<option value="">No scans available</option>';
                const keepPrior = scans.some((scan) => String(scan.id) === priorSelection);
                activitySelect.value = keepPrior ? priorSelection : (preferred ? String(preferred.id) : '');
                if (!keepPrior) {
                    lastWorkerSignature = '';
                    lastEventSignature = '';
                }
                await refreshActivity();
            } catch (error) { document.getElementById('spf-scan-updated').textContent = `Unable to refresh scans: ${error.message}`; }
            finally { refreshScansRunning = false; }
        };
        const feedbackDialog = document.getElementById('spf-feedback-dialog');
        const feedbackTitle = document.getElementById('spf-feedback-title');
        const feedbackMessage = document.getElementById('spf-feedback-message');
        const feedbackCancel = document.getElementById('spf-feedback-cancel');
        const feedbackConfirm = document.getElementById('spf-feedback-confirm');
        let feedbackResolver = null;
        const showFeedback = (message, {title = 'SPF Scanner', confirm = false, confirmLabel = 'Continue'} = {}) => new Promise((resolve) => {
            feedbackResolver = resolve;
            feedbackTitle.textContent = title;
            feedbackMessage.textContent = message;
            feedbackCancel.hidden = !confirm;
            feedbackConfirm.textContent = confirm ? confirmLabel : 'Close';
            feedbackDialog.showModal();
        });
        const closeFeedback = (confirmed) => {
            feedbackDialog.close();
            if (feedbackResolver) feedbackResolver(confirmed);
            feedbackResolver = null;
        };
        feedbackCancel.addEventListener('click', () => closeFeedback(false));
        feedbackConfirm.addEventListener('click', () => closeFeedback(true));
        feedbackDialog.addEventListener('cancel', (event) => { event.preventDefault(); closeFeedback(false); });
        scanTable.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-spf-kill]');
            if (!button) return;
            const scanId = button.dataset.spfKill;
            if (!await showFeedback(`Stop SPF scan ${scanId}? Active DNS lookups will finish before its workers exit.`, {title: 'Stop scan?', confirm: true, confirmLabel: 'Stop scan'})) return;
            setBusy(button, true);
            try { await request(`${base}/scans/${encodeURIComponent(scanId)}/kill`, {}); await refreshScans(); }
            catch (error) { await showFeedback(error.message); setBusy(button, false); }
        });
        refreshScans(); window.setInterval(refreshScans, 3000);

        const manualButton = document.getElementById('spf-manual-run');
        manualButton.addEventListener('click', async () => {
            const status = document.getElementById('spf-manual-status'); const domains = uniqueLines(document.getElementById('spf-manual-domains').value); const output = document.getElementById('spf-manual-results');
            if (!domains.length || domains.length > 50) { status.textContent = 'Enter between 1 and 50 domains.'; return; }
            setBusy(manualButton, true); status.textContent = 'Checking DNS records…'; output.innerHTML = '<tr><td colspan="4">Checking…</td></tr>';
            try { const rows = await request(`${base}/manual`, {domains}); output.innerHTML = rows.length ? rows.map((row) => `<tr><td>${escapeHtml(row.root)}</td><td>${escapeHtml(row.domain)}</td><td>${escapeHtml(row.status)}</td><td><pre class="mb-0" style="font-size: 0.75rem;">${escapeHtml(row.spf)}</pre></td></tr>`).join('') : '<tr><td colspan="4" class="text-muted">No SPF records found.</td></tr>'; status.textContent = `${rows.length} record${rows.length === 1 ? '' : 's'} found. Manual checks are not saved.`; }
            catch (error) { status.textContent = error.message; output.innerHTML = '<tr><td colspan="4" class="text-danger">DNS scan failed.</td></tr>'; } finally { setBusy(manualButton, false); }
        });
        const volumeButton = document.getElementById('spf-volume-run');
        volumeButton.addEventListener('click', async () => { const domains = uniqueLines(document.getElementById('spf-manual-domains').value); const status = document.getElementById('spf-manual-status'); if (domains.length !== 1) { status.textContent = 'Enter exactly one domain for a volume lookup.'; return; } setBusy(volumeButton, true); try { const data = await request(`${base}/volume`, {domain:domains[0]}); status.textContent = JSON.stringify(data, null, 2); } catch (error) { status.textContent = error.message; } finally { setBusy(volumeButton, false); } });

        const dialog = document.getElementById('spf-record-dialog');
        document.querySelectorAll('[data-spf-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
        document.addEventListener('click', async (event) => { const button = event.target.closest('[data-spf-record]'); if (!button) return; try { const response = await fetch(`${base}/records?id=${encodeURIComponent(button.dataset.spfRecord)}`, {headers:{'Accept':'application/json'}}); const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Record not found.'); document.getElementById('spf-record-content').textContent = [data.formatted || 'No SPF record', ...(data.hosts || []).map((host) => `\n${host.domain}: ${host.status}\n${host.record || '(no record)'}`)].join('\n'); dialog.showModal(); } catch (error) { await showFeedback(error.message); } });
    })();
</script>
@endpush
