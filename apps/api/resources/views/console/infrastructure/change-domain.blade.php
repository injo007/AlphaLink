@extends('layouts.admin-shell')

@section('title', 'Change Server Domain')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-exchange-alt"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">DNS REBINDING // ROTATION ENGINE</div>
                    <h1 class="command-os-report-hero-title">CHANGE DOMAIN</h1>
                    <p class="command-os-report-hero-desc">Rebind sending domains and update DNS/PMTA configurations on existing deployed mail servers.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">DNS RE-ORCHESTRATION</span>
                </div>
            </div>
        </header>

        <!-- 2. Form & Workspace Card -->
        <div class="command-os-table-card p-4">
            <div class="row justify-content-center push">
                <div class="col-sm-12 col-md-8 col-lg-6">
                    <div class="command-form-group">
                        <label class="command-form-label" for="change-domain-server">
                            <i class="fas fa-server mr-1 text-purple"></i> TARGET SERVER
                        </label>
                        <div class="command-select-wrapper">
                            <select id="change-domain-server" name="servers" class="command-form-control command-select" title="Select Server ..." @disabled($servers->isEmpty())>
                                <option value="" disabled selected>Select Target Server ...</option>
                                @foreach ($servers as $server)
                                    <option value="{{ $server->id }}">{{ $server->name }}{{ $server->id }} | {{ $server->ip_address }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="command-modal-actions mt-3">
                        <button type="button" class="btn-command-primary w-100" id="load-change-domain" @disabled($servers->isEmpty())>
                            <i class="fas fa-sync mr-1"></i> LOAD SERVER IP CONFIGURATION
                        </button>
                    </div>

                    @if ($servers->isEmpty())
                        <div class="alert alert-info mt-3" role="status" style="border-radius: 12px; font-size: 0.8125rem;">
                            <i class="fas fa-info-circle mr-1"></i> No active installed servers are available in this workspace.
                        </div>
                    @endif
                    <div class="alert alert-danger d-none mt-3" id="change-domain-error" role="alert" aria-live="polite" style="border-radius: 12px; font-size: 0.8125rem;"></div>
                </div>
            </div>

            <div class="row push d-none mt-4" id="change-domain-editor">
                <div class="col-sm-12">
                    <div class="p-3 mb-3 d-flex flex-wrap align-items-center" style="background: rgba(99, 102, 241, 0.04); border: 1px solid rgba(99, 102, 241, 0.12); border-radius: 12px; gap: 14px;">
                        <span class="font-w600" style="font-size: 0.8125rem;">DNS & Security Options:</span>
                        @foreach (['https' => 'HTTPS', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $option => $label)
                            <div class="custom-control custom-switch custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="change-domain-{{ $option }}" data-change-domain-option="{{ $option }}">
                                <label class="custom-control-label" for="change-domain-{{ $option }}" style="font-size: 0.75rem; font-weight: 600;">{{ $label }}</label>
                            </div>
                        @endforeach
                    </div>

                    <div class="table-responsive">
                        <table id="change-domain-ips" class="table table-sm table-hover table-striped">
                            <thead class="thead-light">
                                <tr>
                                    <th scope="col">IP ID</th>
                                    <th scope="col">IP ADDRESS</th>
                                    <th scope="col">TYPE</th>
                                    <th scope="col">MAIN</th>
                                    <th scope="col">SUB DOMAIN</th>
                                    <th scope="col">ASSIGNED SENDING DOMAIN</th>
                                </tr>
                            </thead>
                            <tbody id="change-domain-ips-body">
                                <tr><td colspan="6" class="text-center py-3 text-muted">Load a server to review its saved IP assignments.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3 my-3" style="background: rgba(99, 102, 241, 0.03); border: 1px dashed rgba(99, 102, 241, 0.2); border-radius: 12px;">
                        <h5 class="mb-1 font-w700" style="font-size: 0.875rem;"><i class="fas fa-clipboard-check mr-1 text-purple"></i> REBIND SUMMARY</h5>
                        <p id="change-domain-summary" aria-live="polite" class="mb-1 font-w600" style="font-size: 0.8125rem; color: #8b5cf6;">No server assignments loaded.</p>
                        <p class="small text-muted mb-0">Selecting a domain applies it to every IP in that row’s original domain group. Exactly one IP should be marked MAIN.</p>
                    </div>

                    <div class="command-modal-actions mt-3 justify-content-end">
                        <button type="button" class="btn-command-primary" id="apply-domain-change" disabled>
                            <i class="fas fa-play mr-1"></i> APPLY DOMAIN REBIND
                        </button>
                    </div>

                    <div class="mt-4">
                        <h4 class="mb-2 font-w700" style="font-size: 0.9375rem;"><i class="fas fa-terminal mr-1 text-purple"></i> REBIND LOGS</h4>
                        <div style="background: #090d16; border: 1px solid rgba(99, 102, 241, 0.25); border-radius: 12px; padding: 1rem; max-height: 380px; overflow-y: auto;">
                            <code id="change-domain-logs" style="white-space: pre-wrap; font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.8125rem; color: #4ade80; display: block;">No change has been submitted.</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
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
                    sub.className = 'command-form-control form-control-sm';
                    sub.value = assignment.sub_domain || '';
                    sub.setAttribute('aria-label', `Subdomain for ${assignment.ip}`);
                    sub.dataset.subDomain = '';
                    subCell.append(sub);
                    row.append(subCell);

                    const domainCell = document.createElement('td');
                    const select = document.createElement('select');
                    select.className = 'command-form-control form-control-sm command-select';
                    select.setAttribute('aria-label', `Domain for ${assignment.ip}`);
                    select.dataset.domainSelect = '';

                    const emptyOption = document.createElement('option');
                    emptyOption.value = '';
                    emptyOption.textContent = 'Select domain...';
                    select.append(emptyOption);

                    for (const domain of domains) {
                        const option = document.createElement('option');
                        option.value = String(domain.id);
                        option.textContent = domain.label;
                        if (String(domain.id) === String(assignment.domain_id)) {
                            option.selected = true;
                        }
                        select.append(option);
                    }
                    domainCell.append(select);
                    row.append(domainCell);

                    tableBody.append(row);
                }

                updateSummary(payload.data);
                updateDomainAvailability();
                changeButton.disabled = false;
            }

            loadButton.addEventListener('click', async () => {
                const serverId = serverSelect.value;
                if (!serverId) return;
                error.classList.add('d-none');
                error.textContent = '';
                loadButton.disabled = true;
                changeButton.disabled = true;

                try {
                    const response = await fetch(loadUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({ server_id: serverId }),
                    });
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'Could not load server IP configuration.');
                    editor.classList.remove('d-none');
                    renderRows(payload);
                } catch (err) {
                    error.textContent = err.message;
                    error.classList.remove('d-none');
                } finally {
                    loadButton.disabled = false;
                }
            });

            tableBody.addEventListener('change', (event) => {
                if (event.target.matches('[data-domain-select]')) {
                    const select = event.target;
                    const row = select.closest('tr');
                    const oldDomainId = row.dataset.oldDomainId;
                    for (const peerRow of tableBody.querySelectorAll(`[data-old-domain-id="${oldDomainId}"]`)) {
                        const peerSelect = peerRow.querySelector('[data-domain-select]');
                        if (peerSelect) peerSelect.value = select.value;
                    }
                    updateDomainAvailability();
                }

                if (event.target.matches('[data-main-ip]')) {
                    if (event.target.checked) {
                        for (const input of tableBody.querySelectorAll('[data-main-ip]')) {
                            if (input !== event.target) input.checked = false;
                        }
                    }
                }
            });

            changeButton.addEventListener('click', async () => {
                const serverId = serverSelect.value;
                if (!serverId) return;
                const rows = [...tableBody.querySelectorAll('tr[data-ip-id]')].map((row) => ({
                    ip_id: row.dataset.ipId,
                    main: row.querySelector('[data-main-ip]')?.checked ? 1 : 0,
                    sub_domain: row.querySelector('[data-sub-domain]')?.value || '',
                    domain_id: row.querySelector('[data-domain-select]')?.value || '',
                }));

                const options = {};
                for (const input of document.querySelectorAll('[data-change-domain-option]')) {
                    options[input.dataset.changeDomainOption] = input.checked ? 1 : 0;
                }

                changeButton.disabled = true;
                logs.textContent = 'Submitting domain changes...';

                try {
                    const response = await fetch(changeUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            server_id: serverId,
                            rows,
                            ...options,
                        }),
                    });
                    const payload = await response.json();
                    if (!response.ok) throw new Error(payload.message || 'Domain change submission failed.');
                    logs.textContent = payload.message || 'Change dispatched successfully. Monitoring logs...';

                    const pollLogs = async () => {
                        try {
                            const logRes = await fetch(logsUrlTemplate.replace('__SERVER__', encodeURIComponent(serverId)));
                            const logData = await logRes.json();
                            logs.textContent = logData.logs || payload.message || 'Completed.';
                        } catch {
                            // keep last text
                        }
                    };
                    setTimeout(pollLogs, 2000);
                } catch (err) {
                    logs.textContent = `Error: ${err.message}`;
                } finally {
                    changeButton.disabled = false;
                }
            });
        })();
    </script>
@endpush
