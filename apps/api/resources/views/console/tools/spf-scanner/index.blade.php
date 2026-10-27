@extends('layouts.dms-admin')

@section('title', 'SPF Scanner - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">SPF SCANNER</h3>
            <div class="block-options d-flex align-items-center">
                <span class="badge badge-secondary mr-2">Roots {{ number_format($stats['roots']) }}</span>
                <span class="badge badge-secondary mr-2">Hosts {{ number_format($stats['hosts']) }}</span>
                <span class="badge badge-secondary mr-3">CIDRs {{ number_format($stats['cidrs']) }}</span>
                <a class="btn btn-sm btn-outline-primary mr-2" href="{{ route('console.tools.spf.domains', $workspace->id) }}">Browse domains</a>
                <button class="btn-block-option" type="button" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content">
            @if (session('status')) <div class="alert alert-success" role="status">{{ session('status') }}</div> @endif
            @if ($errors->any()) <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div> @endif
            <ul class="nav nav-tabs nav-tabs-block" role="tablist" aria-label="SPF Scanner tools">
                <li class="nav-item"><button class="nav-link active" id="spf-search-tab" type="button" role="tab" aria-selected="true" aria-controls="spf-search-panel" data-spf-tab="search">SEARCH</button></li>
                <li class="nav-item"><button class="nav-link" id="spf-upload-tab" type="button" role="tab" aria-selected="false" aria-controls="spf-upload-panel" data-spf-tab="upload">SCAN DOMAINS</button></li>
                <li class="nav-item"><button class="nav-link" id="spf-manual-tab" type="button" role="tab" aria-selected="false" aria-controls="spf-manual-panel" data-spf-tab="manual">MANUAL</button></li>
            </ul>
            <div class="tab-content pt-3">
                <section class="tab-pane active" id="spf-search-panel" role="tabpanel" aria-labelledby="spf-search-tab" data-spf-panel="search">
                    <div class="form-row align-items-end">
                        <div class="form-group col-lg-8">
                            <label for="spf-ips">IP addresses</label>
                            <textarea class="form-control" id="spf-ips" rows="4" placeholder="One IPv4 or IPv6 address per line (up to 100)"></textarea>
                            <small class="form-text text-muted">Returns every root whose SPF authorizes the IP. Table search prefixes root/include only.</small>
                        </div>
                        <div class="form-group col-lg-4"><button type="button" class="btn btn-primary btn-block" id="spf-search"><i class="fa fa-search mr-1"></i> SEARCH</button></div>
                    </div>
                    <div id="spf-search-status" class="small text-muted mb-2" role="status" aria-live="polite"></div>
                    <div class="table-responsive"><table id="spf-search-results" class="table table-sm table-striped table-hover w-100"><thead><tr><th>Matched IP</th><th>Root</th><th>Include</th><th>Network</th><th>SPF</th></tr></thead><tbody></tbody></table></div>
                </section>
                <section class="tab-pane d-none" id="spf-upload-panel" role="tabpanel" aria-labelledby="spf-upload-tab" data-spf-panel="upload">
                    <form method="POST" enctype="multipart/form-data" action="{{ route('console.tools.spf.upload', $workspace->id) }}" class="border rounded p-3 mb-4">
                        @csrf
                        <div class="form-row align-items-end">
                            <div class="form-group col-md-5"><label for="spf-file">Domain list (.txt)</label><input class="form-control-file" type="file" id="spf-file" name="file" accept=".txt,text/plain" required></div>
                            <div class="form-group col-md-2"><label for="spf-batch-size">Batch size</label><input class="form-control" id="spf-batch-size" name="batch_size" type="number" value="500" min="1" max="1000" required></div>
                            <div class="form-group col-md-2"><label for="spf-workers">Workers</label><input class="form-control" id="spf-workers" name="workers" type="number" value="1" min="1" max="8"><small class="form-text text-muted">Maximum: 8.</small></div>
                            <div class="form-group col-md-3"><div class="custom-control custom-checkbox mb-2"><input class="custom-control-input" type="checkbox" name="skip_existing" id="spf-skip-existing" value="1" checked><label class="custom-control-label" for="spf-skip-existing">Skip domains already scanned</label></div><button class="btn btn-primary btn-block" type="submit">UPLOAD &amp; START SCAN</button></div>
                        </div>
                    </form>
                    <div class="d-flex align-items-center justify-content-between mb-2"><h4 class="h5 mb-0">Scans</h4><small id="spf-scan-updated" class="text-muted" aria-live="polite">Loading…</small></div>
                    <div class="table-responsive"><table class="table table-sm table-striped table-hover w-100"><thead><tr><th>ID</th><th>Status</th><th>Progress</th><th>SPF found</th><th>Workers</th><th>Started</th><th>Action</th></tr></thead><tbody id="spf-scans"><tr><td colspan="7" class="text-muted">Loading scans…</td></tr></tbody></table></div>
                </section>
                <section class="tab-pane d-none" id="spf-manual-panel" role="tabpanel" aria-labelledby="spf-manual-tab" data-spf-panel="manual">
                    <div class="form-row align-items-end">
                        <div class="form-group col-lg-8"><label for="spf-manual-domains">Domains</label><textarea class="form-control" id="spf-manual-domains" rows="6" placeholder="One domain per line, maximum 50. Live DNS lookup; results are not saved."></textarea></div>
                        <div class="form-group col-lg-2"><button type="button" class="btn btn-primary btn-block" id="spf-manual-run">CHECK</button></div>
                        <div class="form-group col-lg-2"><button type="button" class="btn btn-outline-primary btn-block" id="spf-volume-run">DOMAIN VOLUME</button></div>
                    </div>
                    <div id="spf-manual-status" class="small text-muted mb-2" role="status" aria-live="polite"></div>
                    <div class="table-responsive"><table class="table table-sm table-striped table-hover"><thead><tr><th>Root</th><th>Domain</th><th>Status</th><th>SPF</th></tr></thead><tbody id="spf-manual-results"><tr><td colspan="4" class="text-muted">Results appear here.</td></tr></tbody></table></div>
                </section>
            </div>
        </div>
    </div>
    <dialog id="spf-record-dialog" class="p-0 border-0 rounded shadow" aria-labelledby="spf-record-title" style="width:min(760px,94vw)">
        <div class="block block-rounded mb-0"><div class="block-header"><h3 id="spf-record-title" class="block-title">SPF RECORD</h3><div class="block-options"><button type="button" class="btn-block-option" data-spf-close aria-label="Close">×</button></div></div><div class="block-content"><pre id="spf-record-content" class="text-monospace text-wrap"></pre></div></div>
    </dialog>
    @push('scripts')
    <script>
        (() => {
            const workspaceId = @json($workspace->id);
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
            const searchStatus = document.getElementById('spf-search-status');
            const searchTable = $('#spf-search-results').DataTable({
                serverSide: true,
                processing: true,
                deferLoading: 0,
                pageLength: 200,
                lengthMenu: [[25, 50, 100, 200, 250], [25, 50, 100, 200, 250]],
                order: [[1, 'asc']],
                ajax: {
                    url: `${base}/search`,
                    type: 'POST',
                    headers: {'X-CSRF-TOKEN': csrf},
                    data: (data) => { data.ips = searchIps; },
                    error: (xhr) => {
                        setBusy(searchButton, false);
                        const errors = Object.values(xhr.responseJSON?.errors || {}).flat();
                        searchStatus.textContent = errors[0] || xhr.responseJSON?.message || 'Unable to search SPF records.';
                    }
                },
                columns: [
                    {data: 'matched_ip', searchable: false},
                    {data: 'root', name: 'root'},
                    {data: 'include', name: 'include'},
                    {data: 'network', searchable: false},
                    {data: 'root_id', orderable: false, searchable: false, render: (id, type, row) => id ? `<button type="button" class="btn btn-sm btn-info" data-spf-record="${escapeHtml(id)}" data-spf-title="${escapeHtml(row.include || '')}">View</button>` : ''}
                ],
                language: {
                    searchPlaceholder: 'Prefix root or include…',
                    emptyTable: 'Search IPs to load matching roots',
                    zeroRecords: 'No matching roots',
                    processing: '<i class="fa fa-spinner fa-spin mr-1"></i> Searching…'
                },
                buttons: [
                    {text: 'Export CSV', action: () => exportSearch()},
                    {text: 'Copy roots (page)', action: async (event, table) => {
                        const roots = [...new Set(table.rows({page: 'current'}).data().toArray().map((row) => String(row.root || '').trim()).filter(Boolean))];
                        if (!roots.length) return;
                        try { await navigator.clipboard.writeText(roots.join('\n')); searchStatus.textContent = `Copied ${roots.length} roots.`; }
                        catch { searchStatus.textContent = 'Clipboard access was denied.'; }
                    }}
                ]
            });
            const exportSearch = async () => {
                if (!searchIps.length) { searchStatus.textContent = 'Search IPs first.'; return; }
                const form = document.createElement('form'); form.method = 'POST'; form.action = `${base}/search/export`; form.target = '_blank';
                const csrfInput = document.createElement('input'); csrfInput.type = 'hidden'; csrfInput.name = '_token'; csrfInput.value = csrf; form.append(csrfInput);
                searchIps.forEach((ip) => { const input = document.createElement('input'); input.type = 'hidden'; input.name = 'ips[]'; input.value = ip; form.append(input); });
                document.body.append(form); form.submit(); form.remove();
            };
            const runSearch = () => {
                const parsed = uniqueLines(document.getElementById('spf-ips').value);
                searchIps = parsed.filter((ip) => /^\d{1,3}(?:\.\d{1,3}){3}$/.test(ip) || ip.includes(':'));
                const skipped = parsed.length - searchIps.length;
                if (!searchIps.length || searchIps.length > 100) { searchStatus.textContent = 'Enter between 1 and 100 valid IP addresses.'; return; }
                setBusy(searchButton, true); searchStatus.textContent = 'Searching saved and dynamic SPF rules…';
                searchTable.ajax.reload((json) => {
                    setBusy(searchButton, false);
                    const count = Number(json?.recordsFiltered || 0);
                    const suffix = skipped ? ` · ignored ${skipped} invalid line(s)` : '';
                    searchStatus.textContent = count ? `${json?.estimated ? 'About ' : ''}${count.toLocaleString()} matching roots${suffix}` : `No matches${suffix}`;
                }, true);
            };
            searchButton.addEventListener('click', (event) => { event.preventDefault(); runSearch(); });
            document.getElementById('spf-ips').addEventListener('keydown', (event) => { if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') { event.preventDefault(); runSearch(); } });

            const scanTable = document.getElementById('spf-scans');
            const refreshScans = async () => {
                try {
                    const response = await fetch(`${base}/scans`, {headers:{'Accept':'application/json'}}); const payload = await response.json(); const scans = payload.data || [];
                    scanTable.innerHTML = scans.length ? scans.map((scan) => { const canStop = scan.status === 'scanning'; const pct = scan.total_lines ? Math.min(100, Math.round(scan.progress / scan.total_lines * 100)) : 0; return `<tr><td>${escapeHtml(scan.id)}</td><td>${escapeHtml(scan.status)}${scan.failure ? `<div class="text-danger small">${escapeHtml(scan.failure)}</div>` : ''}</td><td><div class="progress" style="min-width:150px"><div class="progress-bar" role="progressbar" style="width:${pct}%" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100"></div></div><small>${escapeHtml(scan.progress_display)}</small></td><td>${escapeHtml(scan.total_found)}</td><td>${escapeHtml(scan.workers)}</td><td>${escapeHtml(scan.started_at || scan.created_at || '')}</td><td>${canStop ? `<button type="button" class="btn btn-sm btn-danger" data-spf-kill="${escapeHtml(scan.id)}">STOP</button>` : '—'}</td></tr>`; }).join('') : '<tr><td colspan="7" class="text-muted">No scans yet.</td></tr>';
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
                try { const rows = await request(`${base}/manual`, {domains}); output.innerHTML = rows.length ? rows.map((row) => `<tr><td>${escapeHtml(row.root)}</td><td>${escapeHtml(row.domain)}</td><td>${escapeHtml(row.status)}</td><td><pre class="mb-0">${escapeHtml(row.spf)}</pre></td></tr>`).join('') : '<tr><td colspan="4" class="text-muted">No SPF records found.</td></tr>'; status.textContent = `${rows.length} record${rows.length === 1 ? '' : 's'} found. Manual checks are not saved.`; }
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
@endsection
