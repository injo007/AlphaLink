@extends('layouts.dms-admin')

@section('title', 'Reinstall Servers')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">Reinstall Servers</h3>
            <div class="block-options">
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="row push">
                <div class="col-sm-12 offset-md-3 col-md-6">
                    <div class="form-group">
                        <label for="reinstall-server">Servers:</label>
                        <select id="reinstall-server" class="form-control selectpicker" data-live-search="true" title="Select Server ..." @disabled($servers->isEmpty())>
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}">{{ $server->name }}{{ $server->id }} | {{ $server->ip_address }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="button" class="btn btn-primary btn-block" id="load-reinstall-config" @disabled($servers->isEmpty())>LOAD CONFIG</button>
                    </div>
                    @if ($servers->isEmpty())
                        <div class="alert alert-info" role="status">No active installed servers are available to reinstall.</div>
                    @endif
                    <div class="alert alert-danger d-none" id="reinstall-error" role="alert" aria-live="polite"></div>
                </div>
            </div>

            <div class="alert alert-warning" role="status">
                Reinstall changes the selected server's host and mail services. Owned-domain DNS records may be replaced, and the saved IP map may be refreshed when PMTA is selected. AlphaLink checks PMTA assets and host readiness before opening an SSH session.
            </div>

            <div class="row push d-none" id="reinstall-holder">
                <div class="col-sm-12">
                    <fieldset class="form-group" aria-label="Reinstallation settings">
                        <div class="custom-control custom-control-inline">
                            <select id="reinstall-pmta-version" class="form-control" style="height: 30px;">
                                <option value="40">PMTA 4.0</option>
                                <option value="45">PMTA 4.5</option>
                                <option value="50">PMTA 5.0</option>
                            </select>
                        </div>
                        @foreach (['pmta' => 'PMTA', 'tracking' => 'TRACKING', 'https' => 'HTTPS', 'proxy' => 'PROXY', 'imap' => 'IMAP', 'spf' => 'SPF', 'dkim' => 'DKIM', 'dmarc' => 'DMARC'] as $option => $label)
                            <div class="custom-control custom-switch custom-control-inline">
                                <input type="checkbox" class="custom-control-input" id="reinstall-{{ $option }}" data-reinstall-option="{{ $option }}">
                                <label class="custom-control-label" for="reinstall-{{ $option }}">{{ $label }}</label>
                            </div>
                        @endforeach
                    </fieldset>

                    <div class="table-responsive">
                        <table id="reinstall-ips" class="table table-sm table-hover">
                            <thead>
                                <tr><th scope="col">IP</th><th scope="col">TYPE</th><th scope="col">MAIN</th><th scope="col">SUB DOMAIN</th><th scope="col">DOMAIN</th><th scope="col" aria-label="Actions"></th></tr>
                            </thead>
                            <tbody id="reinstall-ips-body">
                                <tr><td colspan="6">Load a server configuration to review its saved IP assignments.</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="form-group">
                        <div class="custom-control custom-switch custom-control-inline mb-1">
                            <input type="checkbox" class="custom-control-input" id="reinstall-with-ipv6" checked>
                            <label class="custom-control-label" for="reinstall-with-ipv6">WITH IPV6</label>
                        </div>
                        <button type="button" class="btn btn-info" id="reload-reinstall-ips" disabled>RELOAD IPS</button>
                        <button type="button" class="btn btn-warning" id="configure-reinstall-ips" disabled aria-haspopup="dialog" aria-controls="reinstall-ip-config-dialog">CONFIG IPS</button>
                        <button type="button" class="btn btn-primary float-md-right" id="reinstall-submit" disabled aria-haspopup="dialog" aria-controls="reinstall-confirmation">REINSTALL</button>
                        @unless (config('infrastructure.reinstall_execution_enabled', false))
                            <p class="small text-warning mt-2" role="status">Remote reinstall is disabled by ALPHALINK_REINSTALL_EXECUTION_ENABLED.</p>
                        @endunless
                        @unless (config('infrastructure.reinstall_ip_config_execution_enabled', false))
                            <p class="small text-warning mt-2" role="status">CONFIG IPS is disabled by ALPHALINK_REINSTALL_IP_CONFIG_EXECUTION_ENABLED.</p>
                        @endunless
                    </div>
                    <p class="small text-warning" id="reinstall-network-blocker">CONFIG IPS changes server network interfaces. Permanent mode replaces existing IPv4 alias files and restarts networking.</p>

                    <div class="form-group">
                        <h2 class="content-heading border-bottom mb-4 pb-2">Logs</h2>
                        <code id="reinstall-log" role="status" aria-live="polite" style="white-space: pre-wrap;">Load a server configuration to begin.</code>
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
    <dialog id="reinstall-confirmation" aria-labelledby="reinstall-confirmation-title" aria-describedby="reinstall-confirmation-description" style="max-width: 34rem; width: calc(100% - 2rem); border: 1px solid #DFE3D9; border-radius: .7rem; padding: 1.25rem; color: #183636; background: #FFFEFA; box-shadow: 0 1rem 3.5rem rgba(24,54,54,.22);">
        <h2 id="reinstall-confirmation-title" class="h4">Confirm server reinstall</h2>
        <p id="reinstall-confirmation-description" class="mb-4">This reruns the selected host and mail services. It can replace DNS records for domains managed by AlphaLink and update saved server IP assignments. Continue only if this maintenance is intended.</p>
        <div class="d-flex justify-content-end">
            <button type="button" class="btn btn-alt-secondary mr-2" id="reinstall-confirm-cancel">Cancel</button>
            <button type="button" class="btn btn-danger" id="reinstall-confirm-accept">Reinstall server</button>
        </div>
    </dialog>
    <dialog id="reinstall-row-confirmation" aria-labelledby="reinstall-row-confirmation-title" aria-describedby="reinstall-row-confirmation-description" style="max-width: 32rem; width: calc(100% - 2rem); border: 1px solid #DFE3D9; border-radius: .7rem; padding: 1.25rem; color: #183636; background: #FFFEFA; box-shadow: 0 1rem 3.5rem rgba(24,54,54,.22);">
        <h2 id="reinstall-row-confirmation-title" class="h4">Remove this IP from the reinstall</h2>
        <p id="reinstall-row-confirmation-description" class="mb-4">The saved assignment remains untouched unless a reinstall completes with PMTA selected.</p>
        <div class="d-flex justify-content-end">
            <button type="button" class="btn btn-alt-secondary mr-2" id="reinstall-row-cancel">Keep IP</button>
            <button type="button" class="btn btn-danger" id="reinstall-row-accept">Remove IP</button>
        </div>
    </dialog>
@endsection

@push('scripts')
    <script src="{{ asset('dms-reference/plugins/bootstrap_select/bootstrap-select.js') }}"></script>
    <script type="module" src="/alphalink/infrastructure/server-ip-config-dialog.js"></script>
    <script>
        (() => {
            const serverSelect = document.getElementById('reinstall-server');
            const loadButton = document.getElementById('load-reinstall-config');
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

            if (window.jQuery?.fn?.selectpicker) {
                window.jQuery(serverSelect).selectpicker();
            }

            function serverUrl(template) {
                return template.replace('__SERVER__', encodeURIComponent(serverSelect.value));
            }

            function requestConfirmation(dialog, acceptButton, cancelButton, callback) {
                if (typeof dialog.showModal !== 'function') {
                    showError('This browser does not support the confirmation dialog required for server changes.');
                    return;
                }
                confirmedAction = callback;
                dialog.showModal();
                acceptButton.focus();
                const finish = (accepted) => {
                    const action = confirmedAction;
                    confirmedAction = null;
                    dialog.close();
                    if (accepted && typeof action === 'function') action();
                };
                acceptButton.onclick = () => finish(true);
                cancelButton.onclick = () => finish(false);
                dialog.oncancel = () => { confirmedAction = null; };
                dialog.onclose = () => { confirmedAction = null; };
            }

            function showError(message) {
                error.textContent = message;
                error.classList.remove('d-none');
            }

            function textCell(value) {
                const cell = document.createElement('td');
                cell.textContent = String(value ?? '');
                return cell;
            }

            function renderRows(assignments) {
                rows.replaceChildren();
                if (!assignments.length) {
                    const row = document.createElement('tr');
                    const cell = textCell('No saved IP assignments are available.');
                    cell.colSpan = 6;
                    row.append(cell);
                    rows.append(row);
                    return;
                }

                for (const assignment of assignments) {
                    const row = document.createElement('tr');
                    row.dataset.domainId = String(assignment.domain_id ?? '');
                    row.dataset.ip = String(assignment.ip ?? '');
                    row.append(textCell(assignment.ip));
                    row.append(textCell(String(assignment.type ?? '').toUpperCase()));

                    const mainCell = document.createElement('td');
                    const main = document.createElement('input');
                    main.type = 'checkbox';
                    main.checked = Boolean(assignment.main);
                    main.setAttribute('aria-label', `Main IP ${assignment.ip}`);
                    main.addEventListener('change', () => {
                        if (main.checked) {
                            for (const other of rows.querySelectorAll('input[type="checkbox"]')) {
                                if (other !== main) other.checked = false;
                            }
                        }
                    });
                    mainCell.append(main);
                    row.append(mainCell);

                    const subCell = document.createElement('td');
                    const sub = document.createElement('input');
                    sub.type = 'text';
                    sub.className = 'form-control form-control-sm';
                    sub.value = String(assignment.sub ?? '');
                    sub.setAttribute('aria-label', `Subdomain for ${assignment.ip}`);
                    subCell.append(sub);
                    row.append(subCell);

                    row.append(textCell(assignment.domain));

                    const actionCell = document.createElement('td');
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'btn btn-sm btn-alt-danger';
                    remove.setAttribute('aria-label', `Remove IP ${assignment.ip}`);
                    const removeIcon = document.createElement('i');
                    removeIcon.className = 'fa fa-times';
                    removeIcon.setAttribute('aria-hidden', 'true');
                    remove.append(removeIcon);
                    remove.addEventListener('click', () => {
                        pendingRemoveRow = row;
                        requestConfirmation(
                            rowConfirmation,
                            document.getElementById('reinstall-row-accept'),
                            document.getElementById('reinstall-row-cancel'),
                            () => { pendingRemoveRow?.remove(); pendingRemoveRow = null; updateRunAvailability(); },
                        );
                    });
                    actionCell.append(remove);
                    row.append(actionCell);
                    rows.append(row);
                }
            }

            function applyConfiguration(configuration) {
                document.getElementById('reinstall-pmta').checked = true;
                document.getElementById('reinstall-pmta-version').value = String(configuration.pmta_version ?? 40);
                for (const option of ['tracking', 'https', 'proxy', 'imap', 'spf', 'dkim', 'dmarc']) {
                    document.getElementById(`reinstall-${option}`).checked = Boolean(configuration[option]);
                }
                renderRows(Array.isArray(configuration.data) ? configuration.data : []);
                loadedServerId = serverSelect.value;
                updateRunAvailability();
            }

            function updateRunAvailability() {
                reinstallButton.disabled = !reinstallExecutionEnabled || loadedServerId !== serverSelect.value || rows.querySelectorAll('tr[data-ip]').length === 0;
                configureButton.disabled = !reinstallIpConfigExecutionEnabled || loadedServerId !== serverSelect.value;
            }

            serverSelect.addEventListener('change', updateRunAvailability);

            loadButton.addEventListener('click', async () => {
                error.classList.add('d-none');
                if (!serverSelect.value) {
                    showError('Please select server!');
                    return;
                }
                loadButton.disabled = true;
                loadButton.textContent = 'LOADING CONFIG';
                try {
                    const response = await fetch(serverUrl(configUrl), {headers: {Accept: 'application/json'}});
                    const configuration = await response.json();
                    if (!response.ok) throw new Error(configuration.message || 'Unable to load server configuration.');
                    applyConfiguration(configuration);
                    holder.classList.remove('d-none');
                    reloadButton.disabled = false;
                    log.textContent = 'Server configuration loaded.';
                    updateRunAvailability();
                } catch (failure) {
                    showError(failure.message || 'Unable to load server configuration.');
                } finally {
                    loadButton.disabled = false;
                    loadButton.textContent = 'LOAD CONFIG';
                }
            });

            reloadButton.addEventListener('click', async () => {
                error.classList.add('d-none');
                reloadButton.disabled = true;
                reloadButton.textContent = 'RELOADING IPS';
                try {
                    const response = await fetch(serverUrl(ipsUrl), {
                        method: 'POST',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf},
                        body: JSON.stringify({with_ipv6: document.getElementById('reinstall-with-ipv6').checked}),
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.data || result.message || 'Unable to rediscover server IPs.');
                    renderRows(result.data || []);
                    updateRunAvailability();
                    log.textContent = 'Server IPs rediscovered. No server configuration was changed.';
                } catch (failure) {
                    showError(failure.message || 'Unable to rediscover server IPs.');
                } finally {
                    reloadButton.disabled = false;
                    reloadButton.textContent = 'RELOAD IPS';
                }
            });

            serverSelect.addEventListener('change', () => {
                if (loadedServerId !== serverSelect.value) {
                    holder.classList.add('d-none');
                    reinstallButton.disabled = true;
                }
            });

            reinstallButton.addEventListener('click', () => {
                if (reinstallButton.disabled || loadedServerId !== serverSelect.value) return;
                error.classList.add('d-none');
                requestConfirmation(confirmation, document.getElementById('reinstall-confirm-accept'), document.getElementById('reinstall-confirm-cancel'), async () => {
                    reinstallButton.disabled = true;
                    loadButton.disabled = true;
                    reloadButton.disabled = true;
                    configureButton.disabled = true;
                    reinstallButton.textContent = 'REINSTALLING';
                    log.textContent = 'Checking readiness…';
                    const assignments = Array.from(rows.querySelectorAll('tr[data-ip]')).map((row) => ({
                        ip: row.dataset.ip,
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
                        const response = await fetch(serverUrl(runUrl), {
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
                                // Continue polling while retaining the latest transcript.
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
                        reinstallButton.textContent = 'REINSTALL';
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
