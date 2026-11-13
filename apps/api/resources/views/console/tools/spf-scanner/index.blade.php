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
                            <div class="col-md-4 mb-3 mb-md-0">
                                <label class="command-form-label" for="spf-file"><i class="fas fa-file-alt mr-1 text-purple"></i> Domain list (.txt):</label>
                                <input class="command-form-control p-1" type="file" id="spf-file" name="file" accept=".txt,text/plain" required style="height: 38px;">
                            </div>
                            <div class="col-md-2 mb-3 mb-md-0">
                                <label class="command-form-label" for="spf-batch-size">Write Batch:</label>
                                <input class="command-form-control" id="spf-batch-size" name="batch_size" type="number" value="25" min="1" max="1000" required style="height: 38px;">
                            </div>
                            <div class="col-md-2 mb-3 mb-md-0">
                                <label class="command-form-label" for="spf-workers">DNS Workers (1-{{ $maxWorkers }}):</label>
                                <input class="command-form-control" id="spf-workers" name="workers" type="number" value="{{ $maxWorkers }}" min="1" max="{{ $maxWorkers }}" style="height: 38px;">
                            </div>
                            <div class="col-md-4">
                                <div class="custom-control custom-switch mb-2">
                                    <input class="custom-control-input" type="checkbox" name="skip_existing" id="spf-skip-existing" value="1" checked>
                                    <label class="custom-control-label" for="spf-skip-existing">Skip scanned</label>
                                </div>
                                <button class="btn-command-primary w-100" type="submit" style="height: 38px;">
                                    <i class="fas fa-upload mr-1"></i> UPLOAD &amp; SCAN
                                </button>
                            </div>
                        </div>
                        <small class="form-text text-muted mt-2">Supports {{ number_format(max(100000, (int) config('spf.max_domains_per_scan', 250000))) }} valid domain lines per upload (max 64 MB). DNS scans are network-bound; progress shows completed domains and the current worker heartbeat.</small>
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
        const formatDuration = (seconds) => {
            const remaining = Math.max(0, Math.floor(seconds));
            if (remaining < 60) return `${remaining}s`;
            if (remaining < 3600) return `${Math.floor(remaining / 60)}m ${remaining % 60}s`;
            return `${Math.floor(remaining / 3600)}h ${Math.floor((remaining % 3600) / 60)}m`;
        };
        const refreshScans = async () => {
            try {
                const response = await fetch(`${base}/scans`, {headers:{'Accept':'application/json'}});
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || 'The scan status service returned an error.');
                const scans = payload.data || [];
                scanTable.innerHTML = scans.length ? scans.map((scan) => {
                    const canStop = ['pending', 'scanning'].includes(scan.status);
                    const total = Number(scan.progress_total || 0);
                    const exactPct = total ? Math.min(100, Number(scan.progress || 0) / total * 100) : 0;
                    const barPct = exactPct > 0 ? Math.max(0.5, exactPct) : 0;
                    const nowMs = Date.now();
                    const previous = scanSamples.get(String(scan.id));
                    const elapsed = previous ? (nowMs - previous.time) / 1000 : 0;
                    const rate = previous && elapsed >= 2 && Number(scan.progress) > previous.progress ? (Number(scan.progress) - previous.progress) / elapsed : 0;
                    scanSamples.set(String(scan.id), {progress: Number(scan.progress || 0), time: nowMs});
                    const throughput = rate > 0 ? ` · ${Math.round(rate).toLocaleString()} domains/s` : '';
                    const eta = rate > 0 && total > Number(scan.progress || 0) ? ` · ETA ${formatDuration((total - Number(scan.progress || 0)) / rate)}` : '';
                    const healthText = scan.health === 'queued_overdue' ? `No SPF worker picked this up after ${formatDuration(scan.queue_age_seconds || 0)}`
                        : scan.health === 'queued' ? 'Waiting for an SPF worker'
                        : scan.health === 'stale_heartbeat' ? `Worker heartbeat missing for ${scan.heartbeat_age_seconds}s`
                        : scan.health === 'no_heartbeat' ? 'Worker has not reported a heartbeat'
                        : scan.health === 'no_recent_progress' ? `No completed batch for ${scan.progress_age_seconds}s · DNS lookup may be slow`
                        : scan.current_domain ? `Working around: ${scan.current_domain}`
                        : scan.stage || '';
                    const healthClass = ['stale_heartbeat', 'no_heartbeat'].includes(scan.health) ? 'text-danger' : 'text-muted';
                    const progress = total ? `${Number(scan.progress || 0).toLocaleString()} / ${total.toLocaleString()} · ${exactPct.toFixed(2)}%` : 'Preparing domain count…';
                    return `<tr><td>${escapeHtml(scan.id)}</td><td>${escapeHtml(scan.status)}<div class="small ${healthClass}">${escapeHtml(healthText)}</div>${scan.failure ? `<div class="text-danger small">${escapeHtml(scan.failure)}</div>` : ''}</td><td><div class="progress" style="min-width:150px; height:6px; border-radius:999px;"><div class="progress-bar bg-purple" role="progressbar" style="width:${barPct}%" aria-valuenow="${exactPct.toFixed(2)}" aria-valuemin="0" aria-valuemax="100"></div></div><small>${escapeHtml(progress + throughput + eta)}</small></td><td>${escapeHtml(scan.total_found)}</td><td>${escapeHtml(scan.workers)}</td><td>${escapeHtml(scan.started_at || scan.created_at || '')}</td><td>${canStop ? `<button type="button" class="btn-command-danger" style="padding:2px 8px;font-size:.75rem;" data-spf-kill="${escapeHtml(scan.id)}">STOP</button>` : '—'}</td></tr>`;
                }).join('') : '<tr><td colspan="7" class="text-muted">No scans yet.</td></tr>';
                document.getElementById('spf-scan-updated').textContent = `Updated ${new Date().toLocaleTimeString()}`;
            } catch (error) { document.getElementById('spf-scan-updated').textContent = `Unable to refresh scans: ${error.message}`; }
        };
        scanTable.addEventListener('click', async (event) => { const button = event.target.closest('[data-spf-kill]'); if (!button) return; if (!window.confirm(`Stop SPF scan ${button.dataset.spfKill}?`)) return; setBusy(button, true); try { await request(`${base}/scans/${encodeURIComponent(button.dataset.spfKill)}/kill`, {}); await refreshScans(); } catch (error) { window.alert(error.message); setBusy(button, false); } });
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
        document.addEventListener('click', async (event) => { const button = event.target.closest('[data-spf-record]'); if (!button) return; try { const response = await fetch(`${base}/records?id=${encodeURIComponent(button.dataset.spfRecord)}`, {headers:{'Accept':'application/json'}}); const data = await response.json(); if (!response.ok) throw new Error(data.error || 'Record not found.'); document.getElementById('spf-record-content').textContent = [data.formatted || 'No SPF record', ...(data.hosts || []).map((host) => `\n${host.domain}: ${host.status}\n${host.record || '(no record)'}`)].join('\n'); dialog.showModal(); } catch (error) { window.alert(error.message); } });
    })();
</script>
@endpush
