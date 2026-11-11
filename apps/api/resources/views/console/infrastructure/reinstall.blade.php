@extends('layouts.admin-shell')

@section('title', 'Reinstall Servers')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-redo"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">LIFECYCLE RECOVERY // FULL STACK REBUILD</div>
                    <h1 class="command-os-report-hero-title">REINSTALL SERVERS</h1>
                    <p class="command-os-report-hero-desc">Rebuild operating system mail stack, reinstall PowerMTA, regenerate certificates, and update DNS bindings.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">MAINTENANCE MODE</span>
                </div>
            </div>
        </header>

        <!-- 2. Form & Workspace Card -->
        <div class="command-os-table-card p-4">
            <div class="row justify-content-center push">
                <div class="col-sm-12 col-md-8 col-lg-6">
                    <div class="command-form-group">
                        <label class="command-form-label" for="reinstall-server">
                            <i class="fas fa-server mr-1 text-purple"></i> TARGET SERVER
                        </label>
                        <div class="command-select-wrapper">
                            <select id="reinstall-server" class="command-form-control command-select" title="Select Server ..." @disabled($servers->isEmpty())>
                                <option value="" disabled selected>Select Target Server ...</option>
                                @foreach ($servers as $server)
                                    <option value="{{ $server->id }}">{{ $server->name }}{{ $server->id }} | {{ $server->ip_address }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="command-modal-actions mt-3">
                        <button type="button" class="btn-command-primary w-100" id="load-reinstall-config" @disabled($servers->isEmpty())>
                            <i class="fas fa-sync mr-1"></i> LOAD SAVED CONFIGURATION
                        </button>
                    </div>
                    <p class="small text-muted mt-2 text-center" id="reinstall-action-status" role="status" aria-live="polite">Select a server, then load its saved configuration before reinstalling.</p>

                    @if ($servers->isEmpty())
                        <div class="alert alert-info mt-3" role="status" style="border-radius: 12px; font-size: 0.8125rem;">
                            <i class="fas fa-info-circle mr-1"></i> No active installed servers are available to reinstall.
                        </div>
                    @endif
                    <div class="alert alert-danger d-none mt-3" id="reinstall-error" role="alert" aria-live="polite" style="border-radius: 12px; font-size: 0.8125rem;"></div>
                </div>
            </div>

            <div class="alert alert-warning my-3" role="status" style="border-radius: 12px; font-size: 0.8125rem;">
                <i class="fas fa-exclamation-triangle mr-1"></i> <strong>NOTICE:</strong> Reinstall modifies the target server's host services. Owned-domain DNS records may be replaced and the saved IP allocation map refreshed when PMTA is selected.
            </div>

            <div class="row push d-none mt-4" id="reinstall-holder">
                <div class="col-sm-12">
                    <div class="command-trigger-bar mb-3">
                        <div class="d-flex align-items-center" style="gap: 8px;">
                            <label class="mb-0 font-w600" for="reinstall-pmta-version" style="font-size: 0.8125rem;">PMTA Version:</label>
                            <div class="command-select-wrapper" style="width: 140px;">
                                <select id="reinstall-pmta-version" class="command-form-control command-select" style="height: 34px; padding: 4px 8px; font-size: 0.8125rem;">
                                    <option value="40">PMTA 4.0</option>
                                    <option value="45" selected>PMTA 4.5</option>
                                    <option value="50">PMTA 5.0</option>
                                </select>
                            </div>
                        </div>
                        <div class="command-trigger-group">
                            @foreach (['pmta' => 'PMTA', 'tracking' => 'TRACKING', 'https' => 'HTTPS', 'proxy' => 'PROXY', 'imap' => 'IMAP', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $option => $label)
                                <div class="d-inline-flex align-items-center mr-2">
                                    <label class="command-switch mr-2">
                                        <input type="checkbox" id="reinstall-{{ $option }}" data-reinstall-option="{{ $option }}">
                                        <span class="command-slider"></span>
                                    </label>
                                    <label class="command-form-label mb-0" for="reinstall-{{ $option }}" style="cursor: pointer; font-size: 0.75rem; font-weight: 700; letter-spacing: 0.04em;">{{ $label }}</label>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table id="reinstall-ips" class="table table-sm table-hover table-striped">
                            <thead class="thead-light">
                                <tr>
                                    <th scope="col">IP ADDRESS</th>
                                    <th scope="col">TYPE</th>
                                    <th scope="col">MAIN</th>
                                    <th scope="col">SUB DOMAIN</th>
                                    <th scope="col">ASSIGNED DOMAIN</th>
                                    <th scope="col" aria-label="Actions"></th>
                                </tr>
                            </thead>
                            <tbody id="reinstall-ips-body">
                                <tr><td colspan="6" class="text-center py-3 text-muted">Load a server configuration to review its saved IP assignments.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex flex-wrap align-items-center justify-content-between mt-3" style="gap: 10px;">
                        <div class="d-inline-flex align-items-center">
                            <label class="command-switch mr-2">
                                <input type="checkbox" id="reinstall-with-ipv6" checked>
                                <span class="command-slider"></span>
                            </label>
                            <label class="command-form-label mb-0" for="reinstall-with-ipv6" style="cursor: pointer; font-size: 0.8125rem; font-weight: 700;">WITH IPV6 SUPPORT</label>
                        </div>
                        <div class="d-flex align-items-center" style="gap: 8px;">
                            <button type="button" class="btn-command-action" id="reload-reinstall-ips" disabled>
                                <i class="fas fa-sync mr-1"></i> RELOAD IPS
                            </button>
                            <button type="button" class="btn-command-action" id="configure-reinstall-ips" disabled aria-haspopup="dialog" aria-controls="reinstall-ip-config-dialog">
                                <i class="fas fa-sliders-h mr-1"></i> CONFIG IPS
                            </button>
                            <button type="button" class="btn-command-primary" id="reinstall-submit" disabled aria-haspopup="dialog" aria-controls="reinstall-confirmation">
                                <i class="fas fa-redo mr-1"></i> REINSTALL SERVER
                            </button>
                        </div>
                    </div>

                    @unless (config('infrastructure.reinstall_ip_config_execution_enabled', false))
                        <p class="small text-warning mt-2 mb-0" role="status"><i class="fas fa-info-circle mr-1"></i> CONFIG IPS execution is disabled by ALPHALINK_REINSTALL_IP_CONFIG_EXECUTION_ENABLED.</p>
                    @endunless
                    @unless (config('infrastructure.reinstall_execution_enabled', false))
                        <p class="small text-warning mt-2 mb-0" role="status"><i class="fas fa-info-circle mr-1"></i> REINSTALL execution is disabled by ALPHALINK_REINSTALL_EXECUTION_ENABLED.</p>
                    @endunless
                    <p class="small text-muted mt-1" id="reinstall-network-blocker">CONFIG IPS modifies server network interfaces. Permanent mode replaces existing IPv4 alias files and restarts networking.</p>

                    <div class="mt-4">
                        <h4 class="mb-2 font-w700" style="font-size: 0.9375rem;"><i class="fas fa-terminal mr-1 text-purple"></i> REINSTALL LOGS</h4>
                        <div style="background: #090d16; border: 1px solid rgba(99, 102, 241, 0.25); border-radius: 12px; padding: 1rem; max-height: 400px; overflow-y: auto;">
                            <code id="reinstall-log" role="status" aria-live="polite" style="white-space: pre-wrap; font-family: 'JetBrains Mono', 'Fira Code', monospace; font-size: 0.8125rem; color: #4ade80; display: block;">Load a server configuration to begin.</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('console.infrastructure.partials.server-ip-config-dialog', [
        'idPrefix' => 'reinstall',
        'configUrlTemplate' => route('console.infrastructure.reinstall.config-ips', [$workspace->id, '__SERVER__']),
        'serverSelector' => '#reinstall-server',
        'logSelector' => '#reinstall-log',
        'refreshSelector' => '#reload-reinstall-ips',
    ])

    <dialog id="reinstall-confirmation" aria-labelledby="reinstall-confirmation-title" aria-describedby="reinstall-confirmation-description" style="max-width: 34rem; width: calc(100% - 2rem); border: 1px solid var(--command-os-border, rgba(99, 102, 241, 0.2)); border-radius: 16px; padding: 1.5rem; color: var(--command-os-text, #1e293b); background: var(--command-os-card-bg, #ffffff); box-shadow: 0 1rem 3.5rem rgba(0, 0, 0, 0.25);">
        <h3 id="reinstall-confirmation-title" class="font-w700 mb-2" style="font-size: 1.125rem;"><i class="fas fa-exclamation-triangle mr-1 text-warning"></i> Confirm Server Reinstallation</h3>
        <p id="reinstall-confirmation-description" class="mb-4 text-muted" style="font-size: 0.875rem;">This reruns the selected host and mail services. It can replace DNS records for domains managed by AlphaLink and update saved server IP assignments. Continue only if this maintenance is intended.</p>
        <div class="d-flex justify-content-end" style="gap: 8px;">
            <button type="button" class="btn-command-action" id="reinstall-confirm-cancel">Cancel</button>
            <button type="button" class="btn-command-primary text-danger" id="reinstall-confirm-accept">Proceed with Reinstall</button>
        </div>
    </dialog>

    <dialog id="reinstall-row-confirmation" aria-labelledby="reinstall-row-confirmation-title" aria-describedby="reinstall-row-confirmation-description" style="max-width: 32rem; width: calc(100% - 2rem); border: 1px solid var(--command-os-border, rgba(99, 102, 241, 0.2)); border-radius: 16px; padding: 1.5rem; color: var(--command-os-text, #1e293b); background: var(--command-os-card-bg, #ffffff); box-shadow: 0 1rem 3.5rem rgba(0, 0, 0, 0.25);">
        <h3 id="reinstall-row-confirmation-title" class="font-w700 mb-2" style="font-size: 1.125rem;">Remove IP From Reinstallation</h3>
        <p id="reinstall-row-confirmation-description" class="mb-4 text-muted" style="font-size: 0.875rem;">The saved assignment remains untouched unless a reinstall completes with PMTA selected.</p>
        <div class="d-flex justify-content-end" style="gap: 8px;">
            <button type="button" class="btn-command-action" id="reinstall-row-cancel">Keep IP</button>
            <button type="button" class="btn-command-primary text-danger" id="reinstall-row-accept">Remove IP</button>
        </div>
    </dialog>
@endsection

@push('scripts')
    <script type="module" src="/alphalink/infrastructure/server-ip-config-dialog.js"></script>
    <script>
        (() => {
            const serverSelect = document.getElementById('reinstall-server');
            const loadButton = document.getElementById('load-reinstall-config');
            const actionStatus = document.getElementById('reinstall-action-status');
            const holder = document.getElementById('reinstall-holder');
            const error = document.getElementById('reinstall-error');
            const rows = document.getElementById('reinstall-ips-body');
            const log = document.getElementById('reinstall-log');
            const reloadButton = document.getElementById('reload-reinstall-ips');
            const configureButton = document.getElementById('configure-reinstall-ips');
            const reinstallButton = document.getElementById('reinstall-submit');
            const reinstallExecutionEnabled = @json((bool) config('infrastructure.reinstall_execution_enabled', false));
            const reinstallIpConfigExecutionEnabled = @json((bool) config('infrastructure.reinstall_ip_config_execution_enabled', false));
            const confirmation = document.getElementById('reinstall-confirmation');
            const rowConfirmation = document.getElementById('reinstall-row-confirmation');
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const configUrl = @json(route('console.infrastructure.reinstall.config', [$workspace->id, '__SERVER__']));
            const ipsUrl = @json(route('console.infrastructure.reinstall.ips', [$workspace->id, '__SERVER__']));
            const runUrl = @json(route('console.infrastructure.reinstall.runs.store', [$workspace->id, '__SERVER__']));
            const logsUrl = @json(route('console.infrastructure.reinstall.runs.logs', [$workspace->id, '__RUN__']));
            let loadedServerId = '';
            let confirmedAction = null;
            let pendingRemoveRow = null;

            function serverUrl(template, serverId = serverSelect.value) {
                return template.replace('__SERVER__', encodeURIComponent(serverId));
            }

            function clearError() {
                error.classList.add('d-none');
                error.textContent = '';
            }

            function showError(message) {
                error.textContent = message;
                error.classList.remove('d-none');
            }

            function isMainSelected() {
                return rows.querySelector('input[type="checkbox"]:checked') !== null;
            }

            function updateRunAvailability() {
                const hasRows = rows.querySelectorAll('tr[data-ip-id]').length > 0;
                const currentConfigurationLoaded = loadedServerId !== '' && loadedServerId === serverSelect.value;
                const hasMain = hasRows && isMainSelected();
                reinstallButton.disabled = !currentConfigurationLoaded || !hasRows || !hasMain || !reinstallExecutionEnabled;
                reloadButton.disabled = !currentConfigurationLoaded || !hasRows;
                configureButton.disabled = !currentConfigurationLoaded || !reinstallIpConfigExecutionEnabled;

                if (!serverSelect.value) {
                    actionStatus.textContent = 'Select a server, then load its saved configuration before reinstalling.';
                } else if (!currentConfigurationLoaded) {
                    actionStatus.textContent = 'Load this server’s saved configuration before using its IP assignments.';
                } else if (!hasRows) {
                    actionStatus.textContent = 'This server has no saved IP and domain assignments. Reinstall is unavailable until its configuration includes them.';
                } else if (!hasMain) {
                    actionStatus.textContent = 'Choose one IP as MAIN before reinstalling.';
                } else if (!reinstallExecutionEnabled) {
                    actionStatus.textContent = 'The configuration is ready, but reinstall execution is disabled by ALPHALINK_REINSTALL_EXECUTION_ENABLED.';
                } else {
                    actionStatus.textContent = 'Configuration ready. Review the options and IP assignments before reinstalling.';
                }
            }

            function setSingleMain(changedInput) {
                if (!changedInput.checked) {
                    updateRunAvailability();
                    return;
                }
                for (const checkbox of rows.querySelectorAll('input[type="checkbox"]')) {
                    if (checkbox !== changedInput) checkbox.checked = false;
                }
                updateRunAvailability();
            }

            function renderRows(assignments) {
                rows.replaceChildren();
                if (!assignments.length) {
                    const row = document.createElement('tr');
                    const cell = document.createElement('td');
                    cell.colSpan = 6;
                    cell.className = 'text-center py-3 text-muted';
                    cell.textContent = 'No saved server IP-to-domain map is available. Reinstall needs an existing AlphaLink IP and domain assignment map.';
                    row.append(cell);
                    rows.append(row);
                    updateRunAvailability();
                    return;
                }
                let mainAssigned = false;
                for (const [index, assignment] of assignments.entries()) {
                    const row = document.createElement('tr');
                    row.dataset.ipId = String(assignment.id ?? assignment.ip_id ?? index + 1);
                    row.dataset.domainId = String(assignment.domain_id || '');

                    const ipCell = document.createElement('td');
                    ipCell.style.fontFamily = 'monospace';
                    ipCell.style.fontWeight = '600';
                    ipCell.style.color = '#6366f1';
                    ipCell.textContent = assignment.ip;
                    row.append(ipCell);

                    const typeCell = document.createElement('td');
                    const typeBadge = document.createElement('span');
                    typeBadge.className = 'badge-command-pill';
                    typeBadge.textContent = String(assignment.type || 'unknown').toUpperCase();
                    typeCell.append(typeBadge);
                    row.append(typeCell);

                    const mainCell = document.createElement('td');
                    const main = document.createElement('input');
                    main.type = 'checkbox';
                    main.checked = Boolean(assignment.main) && !mainAssigned;
                    if (main.checked) mainAssigned = true;
                    main.setAttribute('aria-label', `Mark ${assignment.ip} as main IP`);
                    main.addEventListener('change', () => setSingleMain(main));
                    mainCell.append(main);
                    row.append(mainCell);

                    const subCell = document.createElement('td');
                    const sub = document.createElement('input');
                    sub.type = 'text';
                    sub.className = 'command-form-control form-control-sm';
                    sub.value = assignment.sub || '';
                    sub.setAttribute('aria-label', `Subdomain for ${assignment.ip}`);
                    subCell.append(sub);
                    row.append(subCell);

                    const domainCell = document.createElement('td');
                    domainCell.style.fontWeight = '600';
                    domainCell.textContent = assignment.domain_name || assignment.domain || 'No domain';
                    row.append(domainCell);

                    const actionCell = document.createElement('td');
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'command-action-btn action-danger';
                    remove.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
                    remove.setAttribute('aria-label', `Remove ${assignment.ip}`);
                    remove.addEventListener('click', () => {
                        pendingRemoveRow = row;
                        if (typeof rowConfirmation.showModal === 'function') {
                            rowConfirmation.showModal();
                        } else {
                            pendingRemoveRow = null;
                            showError('This browser cannot open the IP confirmation dialog. No row was removed.');
                        }
                    });
                    actionCell.append(remove);
                    row.append(actionCell);

                    rows.append(row);
                }
                updateRunAvailability();
            }

            document.getElementById('reinstall-confirm-cancel').addEventListener('click', () => confirmation.close());
            document.getElementById('reinstall-row-cancel').addEventListener('click', () => {
                pendingRemoveRow = null;
                rowConfirmation.close();
            });
            document.getElementById('reinstall-confirm-accept').addEventListener('click', () => {
                confirmation.close();
                if (typeof confirmedAction === 'function') confirmedAction();
            });
            document.getElementById('reinstall-row-accept').addEventListener('click', () => {
                rowConfirmation.close();
                if (pendingRemoveRow) {
                    pendingRemoveRow.remove();
                    pendingRemoveRow = null;
                    updateRunAvailability();
                }
            });

            loadButton.addEventListener('click', async () => {
                if (!serverSelect.value) return;
                const requestedServerId = serverSelect.value;
                clearError();
                loadButton.disabled = true;
                loadedServerId = '';
                holder.classList.add('d-none');
                updateRunAvailability();
                actionStatus.textContent = 'Loading configuration…';
                try {
                    const response = await fetch(serverUrl(configUrl, requestedServerId), {headers: {Accept: 'application/json'}});
                    const config = await response.json();
                    if (serverSelect.value !== requestedServerId) return;
                    if (!response.ok) throw new Error(config.message || config.data || 'Could not load server configuration.');

                    document.getElementById('reinstall-pmta-version').value = String(config.pmta_version || 45);
                    for (const option of ['pmta', 'tracking', 'https', 'proxy', 'imap', 'spf', 'dkim', 'dmarc']) {
                        const input = document.getElementById(`reinstall-${option}`);
                        if (input) input.checked = Boolean(config[option]);
                    }
                    loadedServerId = requestedServerId;
                    holder.classList.remove('d-none');
                    const assignments = Array.isArray(config.assignments)
                        ? config.assignments
                        : (Array.isArray(config.data) ? config.data : []);
                    renderRows(assignments);
                } catch (failure) {
                    if (serverSelect.value !== requestedServerId) return;
                    loadedServerId = '';
                    holder.classList.add('d-none');
                    showError(failure.message);
                    actionStatus.textContent = failure.message;
                } finally {
                    loadButton.disabled = !serverSelect.value;
                }
            });

            reloadButton.addEventListener('click', async () => {
                if (!loadedServerId) return;
                const requestedServerId = loadedServerId;
                clearError();
                reloadButton.disabled = true;
                try {
                    const response = await fetch(serverUrl(ipsUrl, requestedServerId), {
                        method: 'POST',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                        body: JSON.stringify({with_ipv6: document.getElementById('reinstall-with-ipv6').checked}),
                    });
                    const ips = await response.json();
                    if (serverSelect.value !== requestedServerId || loadedServerId !== requestedServerId) return;
                    if (!response.ok) throw new Error(ips.message || ips.data || 'Could not reload IPs.');
                    const assignments = Array.isArray(ips.assignments)
                        ? ips.assignments
                        : (Array.isArray(ips.data) ? ips.data : []);
                    renderRows(assignments);
                } catch (failure) {
                    if (serverSelect.value === requestedServerId && loadedServerId === requestedServerId) showError(failure.message);
                } finally {
                    updateRunAvailability();
                }
            });

            serverSelect.addEventListener('change', () => {
                loadedServerId = '';
                holder.classList.add('d-none');
                rows.replaceChildren();
                const row = document.createElement('tr');
                const cell = document.createElement('td');
                cell.colSpan = 6;
                cell.className = 'text-center py-3 text-muted';
                cell.textContent = 'Load a server configuration to review its saved IP assignments.';
                row.append(cell);
                rows.append(row);
                log.textContent = 'Load a server configuration to begin.';
                clearError();
                updateRunAvailability();
            });

            reinstallButton.addEventListener('click', () => {
                const requestedServerId = loadedServerId;
                if (!requestedServerId || requestedServerId !== serverSelect.value || reinstallButton.disabled) return;
                if (typeof confirmation.showModal === 'function') {
                    confirmation.showModal();
                }
                confirmedAction = (async () => {
                    if (serverSelect.value !== requestedServerId || loadedServerId !== requestedServerId) {
                        showError('The selected server changed. Load its configuration again before reinstalling.');
                        updateRunAvailability();
                        return;
                    }
                    clearError();
                    reinstallButton.disabled = true;
                    reinstallButton.textContent = 'REINSTALLING…';
                    serverSelect.disabled = true;
                    loadButton.disabled = true;
                    reloadButton.disabled = true;
                    configureButton.disabled = true;
                    log.textContent = 'Dispatching reinstall request…';

                    const assignments = [...rows.querySelectorAll('tr[data-ip-id]')].map((row) => ({
                        id: Number(row.dataset.ipId),
                        ip: row.cells[0].textContent.trim(),
                        type: row.cells[1].textContent.trim().toLowerCase(),
                        main: row.querySelector('input[type="checkbox"]')?.checked || false,
                        sub: row.querySelector('input[type="text"]')?.value.trim() || '',
                        domain_id: row.dataset.domainId,
                    }));
                    const payload = {pmta_version: Number(document.getElementById('reinstall-pmta-version').value), data: assignments};
                    for (const option of ['pmta', 'tracking', 'https', 'proxy', 'imap', 'spf', 'dkim', 'dmarc']) {
                        payload[option] = document.getElementById(`reinstall-${option}`).checked;
                    }
                    try {
                        const response = await fetch(serverUrl(runUrl, requestedServerId), {
                            method: 'POST',
                            headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                            body: JSON.stringify(payload),
                        });
                        const result = await response.json();
                        if (!response.ok) {
                            const details = Array.isArray(result.blockers) ? `\n${result.blockers.join('\n')}` : '';
                            log.textContent = result.logs || `${result.message || 'Reinstall could not start.'}${details}`;
                            showError(result.message || 'Reinstall could not start.');
                            if (!result.logs && result.run_id) {
                                const logsResponse = await fetch(logsUrl.replace('__RUN__', encodeURIComponent(result.run_id)), {headers: {Accept: 'application/json'}});
                                if (logsResponse.ok) log.textContent = (await logsResponse.json()).data || log.textContent;
                            }
                            return;
                        }
                        const runLogUrl = logsUrl.replace('__RUN__', encodeURIComponent(result.run_id));
                        log.textContent = result.data || 'Reinstall queued. Waiting for live logs…';
                        let runState = 'queued';
                        while (runState === 'queued' || runState === 'running') {
                            try {
                                const logsResponse = await fetch(runLogUrl, {headers: {Accept: 'application/json'}, cache: 'no-store'});
                                if (logsResponse.ok) {
                                    const snapshot = await logsResponse.json();
                                    log.textContent = snapshot.data || log.textContent;
                                    runState = snapshot.status || 'running';
                                } else if (logsResponse.status === 401 || logsResponse.status === 419) {
                                    window.location.reload();
                                    return;
                                }
                            } catch {
                                // Continue polling
                            }
                            if (runState === 'queued' || runState === 'running') {
                                await new Promise((resolve) => window.setTimeout(resolve, 1000));
                            }
                        }
                        if (runState === 'failed') showError('Reinstall failed. Review the latest log output.');
                    } catch (failure) {
                        showError(failure.message || 'The reinstall request could not be completed.');
                        log.textContent = failure.message || 'The reinstall request could not be completed.';
                    } finally {
                        reinstallButton.textContent = 'REINSTALL SERVER';
                        serverSelect.disabled = false;
                        loadButton.disabled = false;
                        reloadButton.disabled = false;
                        configureButton.disabled = false;
                        updateRunAvailability();
                    }
                });
            });

            updateRunAvailability();
        })();
    </script>
@endpush
