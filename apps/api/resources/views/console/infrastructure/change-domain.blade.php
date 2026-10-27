@extends('layouts.dms-admin')

@section('title', 'Servers Change Domain')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">Change domain</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="row push">
                <div class="col-sm-12 offset-md-3 col-md-6">
                    <div class="form-group">
                        <label for="change-domain-server">Servers:</label>
                        <select id="change-domain-server" name="servers" class="form-control selectpicker" data-live-search="true" title="Select Server ..." @disabled($servers->isEmpty())>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}">{{ $server->name }}{{ $server->id }} | {{ $server->ip_address }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="button" class="btn btn-primary btn-block" id="load-change-domain" @disabled($servers->isEmpty())>LOAD</button>
                    </div>
                    @if ($servers->isEmpty())
                        <div class="alert alert-info" role="status">No active installed servers are available in this workspace.</div>
                    @endif
                    <div class="alert alert-danger d-none" id="change-domain-error" role="alert" aria-live="polite"></div>
                </div>
            </div>

            <div class="row push d-none" id="change-domain-editor">
                <div class="col-sm-12">
                    <fieldset class="form-group">
                        <legend class="sr-only">Installation settings</legend>
                        @foreach (['https' => 'HTTPS', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $option => $label)
                            <div class="custom-control custom-switch custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="change-domain-{{ $option }}" data-change-domain-option="{{ $option }}">
                                <label class="custom-control-label" for="change-domain-{{ $option }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </fieldset>

                    <div class="table-responsive">
                        <table id="change-domain-ips" class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th scope="col">IP ID</th>
                                    <th scope="col">IP</th>
                                    <th scope="col">TYPE</th>
                                    <th scope="col">MAIN</th>
                                    <th scope="col">SUB DOMAIN</th>
                                    <th scope="col">SELECTED DOMAIN</th>
                                </tr>
                            </thead>
                            <tbody id="change-domain-ips-body">
                                <tr><td colspan="6">Load a server to review its saved IP assignments.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-group">
                        <h4 class="content-heading border-bottom mb-3 pb-2">CHANGE SUMMARY</h4>
                        <p id="change-domain-summary" aria-live="polite">No server assignments loaded.</p>
                        <p class="small text-muted">Selecting a domain applies it to every IP in that row’s original domain group. Only one IP can be marked MAIN.</p>
                    </div>
                    <div class="form-group text-right">
                        <button type="button" class="btn btn-primary" id="apply-domain-change" disabled>CHANGE</button>
                    </div>
                    <div class="form-group">
                        <h2 class="content-heading border-bottom mb-4 pb-2">LOGS</h2>
                        <code id="change-domain-logs" style="white-space: pre-wrap;">No change has been submitted.</code>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('dms-reference/plugins/bootstrap_select/bootstrap-select.js') }}"></script>
    <script>
        (() => {
            const serverSelect = document.getElementById('change-domain-server');
            const loadButton = document.getElementById('load-change-domain');
            const editor = document.getElementById('change-domain-editor');
            const error = document.getElementById('change-domain-error');
            const tableBody = document.getElementById('change-domain-ips-body');
            const summary = document.getElementById('change-domain-summary');
            const changeButton = document.getElementById('apply-domain-change');
            const logs = document.getElementById('change-domain-logs');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const loadUrl = @json(route('console.infrastructure.change-domain.load-ips', $workspace->id));
            const changeUrl = @json(route('console.infrastructure.change-domain.change', $workspace->id));
            const logsUrlTemplate = @json(route('console.infrastructure.change-domain.logs', [$workspace->id, '__SERVER__']));
            const domains = @json($domainOptions);

            if (window.jQuery?.fn?.selectpicker) {
                window.jQuery(serverSelect).selectpicker();
            }

            function createCell(value) {
                const cell = document.createElement('td');
                cell.textContent = value;
                return cell;
            }

            function updateDomainAvailability() {
                const selectedValues = [...tableBody.querySelectorAll('[data-domain-select]')].map((select) => select.value);
                for (const select of tableBody.querySelectorAll('[data-domain-select]')) {
                    for (const option of select.options) {
                        option.disabled = selectedValues.includes(option.value);
                    }
                }
            }

            function updateSummary(rows) {
                const groups = new Set(rows.map((row) => row.old_domain_id));
                const selectedDomains = new Set(rows.map((row) => row.domain_id).filter(Boolean));
                const selectedOptions = [...document.querySelectorAll('[data-change-domain-option]')]
                    .filter((input) => input.checked)
                    .map((input) => input.id.replace('change-domain-', '').toUpperCase());
                summary.textContent = `${rows.length} IP assignments · ${groups.size} original domain group${groups.size === 1 ? '' : 's'} · ${selectedDomains.size} selected domain${selectedDomains.size === 1 ? '' : 's'} · ${selectedOptions.length ? selectedOptions.join(', ') : 'no optional settings enabled'}.`;
            }

            function renderRows(payload) {
                tableBody.replaceChildren();
                if (payload.data.length === 0) {
                    const row = document.createElement('tr');
                    const cell = createCell('No saved IP assignments are available for this server.');
                    cell.colSpan = 6;
                    row.append(cell);
                    tableBody.append(row);
                    summary.textContent = 'No saved IP assignments are available.';
                    return;
                }

                for (const assignment of payload.data) {
                    const row = document.createElement('tr');
                    row.dataset.ipId = String(assignment.ip_id);
                    row.dataset.oldDomainId = String(assignment.old_domain_id);
                    row.append(createCell(String(assignment.ip_id)));
                    row.append(createCell(assignment.ip));
                    row.append(createCell(assignment.type.toUpperCase()));

                    const mainCell = document.createElement('td');
                    const main = document.createElement('input');
                    main.type = 'checkbox';
                    main.className = 'mainIpCheck';
                    main.checked = Boolean(assignment.main);
                    main.setAttribute('aria-label', `Mark ${assignment.ip} as main IP`);
                    main.dataset.mainIp = '';
                    mainCell.append(main);
                    row.append(mainCell);

                    const subCell = document.createElement('td');
                    const sub = document.createElement('input');
                    sub.type = 'text';
                    sub.className = 'form-control';
                    sub.value = assignment.sub || '';
                    sub.maxLength = 63;
                    sub.autocomplete = 'off';
                    sub.setAttribute('aria-label', `Subdomain for ${assignment.ip}`);
                    sub.dataset.subdomain = '';
                    subCell.append(sub);
                    row.append(subCell);

                    const domainCell = document.createElement('td');
                    const domainSelect = document.createElement('select');
                    domainSelect.className = 'form-control domains';
                    domainSelect.setAttribute('aria-label', `Selected domain for ${assignment.ip}`);
                    domainSelect.dataset.domainSelect = '';
                    const placeholder = document.createElement('option');
                    placeholder.value = '';
                    placeholder.textContent = 'Select domain…';
                    domainSelect.append(placeholder);
                    for (const domain of domains) {
                        const option = document.createElement('option');
                        option.value = domain.id;
                        option.textContent = `${domain.name} | STATUS: ${domain.status.toUpperCase()}`;
                        domainSelect.append(option);
                    }
                    domainSelect.value = assignment.domain_id;
                    domainCell.append(domainSelect);
                    row.append(domainCell);
                    tableBody.append(row);
                }

                updateSummary(payload.data);
            }

            loadButton?.addEventListener('click', async () => {
                error.classList.add('d-none');
                editor.classList.add('d-none');
                if (!serverSelect.value) {
                    error.textContent = 'Please select a server and domains.';
                    error.classList.remove('d-none');
                    return;
                }

                loadButton.disabled = true;
                loadButton.textContent = 'LOADING…';
                try {
                    const response = await fetch(loadUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({server_id: serverSelect.value}),
                    });
                    const payload = await response.json();
                    if (!response.ok) {
                        throw new Error(payload.message || 'Could not load this server’s saved domain assignments.');
                    }

                    for (const key of ['spf', 'dkim', 'dmarc']) {
                        document.getElementById(`change-domain-${key}`).checked = Boolean(payload[key]);
                    }
                    renderRows(payload);
                    editor.classList.remove('d-none');
                    changeButton.disabled = payload.data.length === 0;
                    logs.textContent = '';
                } catch (requestError) {
                    error.textContent = requestError instanceof Error ? requestError.message : 'Could not load this server’s saved domain assignments.';
                    error.classList.remove('d-none');
                } finally {
                    loadButton.disabled = false;
                    loadButton.textContent = 'LOAD';
                    if (window.jQuery?.fn?.selectpicker) {
                        window.jQuery(serverSelect).selectpicker('refresh');
                    }
                }
            });

            tableBody.addEventListener('change', (event) => {
                const target = event.target;
                const row = target.closest('tr[data-ip-id]');
                if (!row) {
                    return;
                }

                if (target.matches('[data-main-ip]') && target.checked) {
                    for (const other of tableBody.querySelectorAll('[data-main-ip]')) {
                        if (other !== target) {
                            other.checked = false;
                        }
                    }
                }

                if (target.matches('[data-domain-select]')) {
                    const rows = [...tableBody.querySelectorAll('tr[data-ip-id]')];
                    const changedIndex = rows.indexOf(row);
                    for (let index = changedIndex; index < rows.length; index++) {
                        const member = rows[index];
                        if (member.dataset.oldDomainId === row.dataset.oldDomainId) {
                            member.querySelector('[data-domain-select]').value = target.value;
                        }
                    }
                    updateDomainAvailability();
                }

                const assignments = [...tableBody.querySelectorAll('tr[data-ip-id]')].map((item) => ({old_domain_id: item.dataset.oldDomainId, domain_id: item.querySelector('[data-domain-select]').value}));
                updateSummary(assignments);
            });

            for (const option of document.querySelectorAll('[data-change-domain-option]')) {
                option.addEventListener('change', () => {
                    const rows = [...tableBody.querySelectorAll('tr[data-ip-id]')].map((row) => ({old_domain_id: row.dataset.oldDomainId, domain_id: row.querySelector('[data-domain-select]').value}));
                    updateSummary(rows);
                });
            }

            let logPoll = null;
            async function refreshLogs(serverId) {
                try {
                    const response = await fetch(logsUrlTemplate.replace('__SERVER__', encodeURIComponent(serverId)), {
                        headers: {'Accept': 'application/json'},
                    });
                    if (!response.ok) return;
                    const entries = await response.json();
                    logs.textContent = Array.isArray(entries) ? entries.join('\n') : '';
                    logs.scrollTop = logs.scrollHeight;
                } catch (_) {
                    // The operation response remains authoritative when a log refresh fails.
                }
            }

            changeButton?.addEventListener('click', async () => {
                const serverId = serverSelect.value;
                const rows = [...tableBody.querySelectorAll('tr[data-ip-id]')].map((row) => ({
                    ip_id: Number(row.dataset.ipId),
                    ip: row.children[1].textContent,
                    type: row.children[2].textContent.toLowerCase(),
                    old_domain_id: row.dataset.oldDomainId,
                    domain_id: row.querySelector('[data-domain-select]').value,
                    sub: row.querySelector('[data-subdomain]').value.trim(),
                    main: row.querySelector('[data-main-ip]').checked,
                }));
                if (!serverId || rows.some((row) => !row.domain_id)) {
                    error.textContent = 'Select a domain for every IP assignment before changing the server.';
                    error.classList.remove('d-none');
                    return;
                }

                error.classList.add('d-none');
                changeButton.disabled = true;
                changeButton.textContent = 'CHANGING…';
                logs.textContent = '';
                logPoll = window.setInterval(() => refreshLogs(serverId), 2000);

                try {
                    const response = await fetch(changeUrl, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            server_id: serverId,
                            data: rows,
                            https: document.getElementById('change-domain-https').checked,
                            spf: document.getElementById('change-domain-spf').checked,
                            dkim: document.getElementById('change-domain-dkim').checked,
                            dmarc: document.getElementById('change-domain-dmarc').checked,
                        }),
                    });
                    const result = await response.json();
                    await refreshLogs(serverId);
                    if (!response.ok) {
                        throw new Error(result.message || result.data || 'The server domain change failed.');
                    }
                    summary.textContent = result.data;
                    changeButton.textContent = 'DONE';
                } catch (requestError) {
                    error.textContent = requestError instanceof Error ? requestError.message : 'The server domain change failed.';
                    error.classList.remove('d-none');
                    changeButton.textContent = 'DONE';
                } finally {
                    if (logPoll !== null) window.clearInterval(logPoll);
                    logPoll = null;
                    changeButton.disabled = true;
                }
            });
        })();
    </script>
@endpush
