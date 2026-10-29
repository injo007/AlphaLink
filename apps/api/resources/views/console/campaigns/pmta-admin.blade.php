@extends('layouts.dms-admin')

@section('title', 'Pmta Admin')

@section('content')
    <style>
        #pmta-monitor-refresh-status[hidden],
        #pmta-admin-panels[hidden] {
            display: none !important;
        }

        @media (min-width: 1200px) and (max-width: 1399.98px) {
            #pmta-admin-fields-row {
                margin-left: 0;
                margin-right: 0;
            }

            #pmta-admin-controls {
                display: grid;
                flex: 0 0 69.5rem;
                grid-template-columns: repeat(12, minmax(0, 1fr));
                gap: 0 .5rem;
                max-width: 69.5rem;
                min-width: 69.5rem;
                padding: 0;
                width: 100%;
            }

            #pmta-admin-server-column {
                grid-column: 1 / -1;
                grid-row: 1;
                margin-left: 3.9%;
                max-width: 69.1%;
                width: 69.1%;
            }

            #pmta-admin-tag-column {
                grid-column: 1 / -1;
                grid-row: 1;
                margin-left: 74.3%;
                max-width: 24%;
                width: 24%;
            }

            #pmta-admin-controls > fieldset {
                grid-column: 1 / -1;
                grid-row: 2;
                margin-left: 1.6%;
                margin-top: -.9rem;
                margin-bottom: -.2rem;
                width: 25%;
            }

            #pmta-admin-controls > fieldset > div {
                align-items: flex-start !important;
                flex-direction: column;
            }

            #pmta-admin-controls > fieldset .custom-control-inline {
                display: block;
                margin-right: 0;
            }

            #pmta-admin-controls > fieldset .custom-control-inline + .custom-control-inline {
                margin-top: .7rem;
            }

            #pmta-admin-controls > fieldset > div > .font-weight-bold {
                display: none;
            }

            #pmta-admin-action-row {
                align-self: end;
                grid-column: 1 / span 4;
                grid-row: 3;
                margin-left: .14rem;
                margin-bottom: .5rem;
                text-align: left !important;
            }

            #pmta-admin-schedule-row {
                display: contents;
            }

            #pmta-admin-schedule-row .pmta-admin-schedule-column,
            #pmta-admin-schedule-row .pmta-admin-restart-column {
                align-self: end;
                flex: none;
                max-width: none;
                padding-left: 0;
                padding-right: 0;
                width: auto;
            }

            #pmta-admin-schedule-row .pmta-admin-schedule-column {
                grid-column: 5 / span 4;
                grid-row: 3;
                left: -.83rem;
                position: relative;
            }

            #pmta-admin-schedule-row .pmta-admin-restart-column {
                grid-column: 9 / span 4;
                grid-row: 3;
                left: .1rem;
                position: relative;
            }

            #pmta-admin-results-column {
                grid-column: 1 / -1;
                grid-row: 4;
            }
        }
    </style>
    <section class="block block-rounded" data-pmta-admin
             data-action-url="{{ $actionUrl }}"
             data-restart-url="{{ $restartUrl }}"
>
        <div class="block-header">
            <h3 class="block-title">PMTA ADMIN ACTIONS</h3>
            <div class="block-options d-flex align-items-center">
                <span class="mr-2">GRID:</span>
                @foreach ([2, 3, 4] as $columns)
                    <div class="custom-control custom-radio custom-control-inline">
                        <input type="radio" class="custom-control-input" id="pmta-grid-{{ $columns }}" name="pmta-grid" value="{{ $columns }}" @checked($columns === 2)>
                        <label class="custom-control-label" for="pmta-grid-{{ $columns }}">x{{ $columns }}</label>
                    </div>
                @endforeach
                <button type="button" class="btn-block-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle fullscreen">
                    <i class="fas fa-expand-arrows-alt" aria-hidden="true"></i>
                </button>
            </div>
        </div>
        <div class="block-content block-content-full">
            <div class="row justify-content-center" id="pmta-admin-fields-row">
                <div class="col-sm-12 col-md-7" id="pmta-admin-controls">
                    <div class="form-group" id="pmta-admin-server-column">
                        <label for="pmta-admin-servers">
                            Servers:
                            <button type="button" class="btn btn-link btn-sm p-0 ml-1" id="pmta-admin-reload-servers" title="Reload servers" aria-label="Reload servers"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>
                            <button type="button" class="btn btn-link btn-sm p-0 ml-1" id="pmta-admin-search-servers" title="Search servers" aria-label="Search servers"><i class="fas fa-search" aria-hidden="true"></i></button>
                        </label>
                        <select class="form-control selectpicker" id="pmta-admin-servers" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 5" multiple title="Select Servers">
                            @foreach ($servers as $server)
                                <option value="{{ $server['id'] }}" data-panel-url="{{ $server['pmta_panel_url'] }}" data-monitor-url="{{ $server['pmta_panel_url'] }}" @selected(in_array((string) $server['id'], $selectedServerIds, true))>{{ $server['name'] }} | {{ $server['ip'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <fieldset class="form-group border-0 p-0">
                        <legend class="sr-only">Queue:</legend>
                        <div class="d-flex align-items-center">
                            <span class="font-weight-bold mr-2" aria-hidden="true">Queue:</span>
                            <div class="custom-control custom-radio custom-control-inline">
                                <input class="custom-control-input" type="radio" id="pmta-domain-queue" name="pmta-queue" value="domain" checked>
                                <label class="custom-control-label" for="pmta-domain-queue">Domain</label>
                            </div>
                            <div class="custom-control custom-radio custom-control-inline">
                                <input class="custom-control-input" type="radio" id="pmta-campaign-queue" name="pmta-queue" value="campaign">
                                <label class="custom-control-label" for="pmta-campaign-queue">Job ID</label>
                            </div>
                        </div>
                    </fieldset>
                    <div class="form-group" id="pmta-admin-tag-column">
                        <label class="sr-only" for="pmta-admin-tag-entry">Domain/VMTA or Job ID</label>
                        <div class="form-control d-flex flex-wrap align-items-center" id="pmta-admin-tag-box" style="min-height: 32px; height: auto; gap: .25rem; padding: .25rem .5rem;">
                            <div class="d-flex flex-wrap align-items-center" id="pmta-admin-tags" aria-live="polite"></div>
                            <input id="pmta-admin-tag-entry" type="text" class="border-0 flex-grow-1" style="width: 0; min-width: 0; outline: none;" placeholder="Domain/VMTA" autocomplete="off">
                        </div>
                    </div>
                    <div class="form-group text-center" id="pmta-admin-action-row" role="group" aria-label="PMTA queue actions" style="white-space: nowrap;">
                        @foreach (['pause' => 'PAUSE', 'resume' => 'RESUME', 'delete' => 'DELETE', 'reset' => 'RESET'] as $action => $label)
                            <button type="button" class="btn btn-primary btn-sm pmta-admin-action" data-action="{{ $action }}">{{ $label }}</button>
                        @endforeach
                        <button type="button" class="btn btn-danger btn-sm" id="pmta-admin-clear">CLEAR</button>
                    </div>
                    <div class="row" id="pmta-admin-schedule-row">
                        <div class="col-sm-12 col-md-6 pmta-admin-schedule-column">
                            <div class="form-group">
                                <label class="sr-only" for="pmta-admin-seconds">Seconds between queue schedules</label>
                                <div class="input-group input-group-sm">
                                    <input id="pmta-admin-seconds" class="form-control" type="number" min="1" placeholder="Seconds &gt;= 1">
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-primary pmta-admin-action" data-action="schedule">SCHEDULE</button>
                                        <button type="button" class="btn btn-danger" id="pmta-admin-stop-schedule">STOP</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-12 col-md-6 pmta-admin-restart-column">
                            <div class="form-group">
                                <label class="sr-only" for="pmta-admin-restart-seconds">Seconds between PMTA restarts</label>
                                <div class="input-group input-group-sm">
                                    <input id="pmta-admin-restart-seconds" class="form-control" type="number" min="5" placeholder="Seconds &gt;= 5">
                                    <div class="input-group-append">
                                        <button type="button" class="btn btn-warning pmta-admin-restart">RESTART</button>
                                        <button type="button" class="btn btn-danger" id="pmta-admin-stop-restart">STOP</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="pmta-admin-results-column">
                        <p id="pmta-admin-schedule-status" class="small text-muted mb-2" hidden role="status" aria-live="polite"></p>
                        <div class="table-responsive d-none" id="pmta-admin-results-wrap">
                            <table class="table table-sm table-hover table-bordered" id="pmta-admin-results" aria-live="polite">
                                <thead><tr><th>Run</th><th>Server</th><th>Command</th><th>Result</th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <p class="small text-muted mb-2">Open the link beside each server. Verified domains use HTTPS; servers without a DNS-managed certificate use the public IP over HTTP.</p>
        <div class="row no-gutters" id="pmta-admin-panels" @if ($selectedServerIds === []) hidden @endif aria-live="polite"></div>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            const root = document.querySelector('[data-pmta-admin]');
            if (!root) return;

            const servers = document.getElementById('pmta-admin-servers');
            const tagEntry = document.getElementById('pmta-admin-tag-entry');
            const tagList = document.getElementById('pmta-admin-tags');
            const panels = document.getElementById('pmta-admin-panels');
            const results = document.querySelector('#pmta-admin-results tbody');
            const resultsWrap = document.getElementById('pmta-admin-results-wrap');
            const scheduleStatus = document.getElementById('pmta-admin-schedule-status');
            const scheduleButton = document.querySelector('[data-action="schedule"]');
            const restartButton = document.querySelector('.pmta-admin-restart');
            const restartButtonLabel = restartButton.innerHTML;
            const gridInputs = Array.from(document.querySelectorAll('input[name="pmta-grid"]'));
            const storedGrid = localStorage.getItem('pmta-admin-grid');
            if (['2', '3', '4'].includes(storedGrid)) {
                const input = gridInputs.find((option) => option.value === storedGrid);
                if (input) input.checked = true;
            }

            let scheduleTimer = null;
            let restartTimer = null;
            let monitorRefreshTimer = null;
            let scheduleRuns = 0;
            let scheduleResponseCount = 0;
            let scheduleServerCount = 0;
            let scheduleInFlight = 0;
            let latestScheduleStatusRun = 0;
            let latestDisplayedRun = 0;
            let scheduleActive = false;
            let scheduleStopReason = null;
            const scheduleResultsByServer = new Map();
            const refreshStoppedScheduleStatus = () => {
                if (scheduleStopReason === null) return;
                const reason = scheduleStopReason === 'transport'
                    ? 'Schedule stopped after a request failed before a PMTA response.'
                    : 'Schedule stopped.';
                const progress = `${scheduleRuns} run(s) submitted; ${scheduleResponseCount} server response(s) received and ${scheduleRuns * scheduleServerCount} server command request(s) were made.`;
                scheduleStatus.textContent = `${reason} ${progress}${scheduleInFlight > 0 ? ` ${scheduleInFlight} request(s) are still in progress.` : ' All scheduled requests have returned.'}`;
            };
            const monitorRefreshMs = 5000;
            const storedQueueTags = localStorage.getItem('queues');
            const tags = new Set(storedQueueTags === null ? ['*/*'] : storedQueueTags.split(',').filter(Boolean));
            const selectedServers = () => Array.from(servers.selectedOptions);
            const selectedTags = () => Array.from(tags);
            const rememberSelection = () => {
                const url = new URL(window.location.href);
                Array.from(url.searchParams.keys()).forEach((key) => {
                    if (/^server_ids(?:\[\d*\])?$/.test(key)) url.searchParams.delete(key);
                });
                selectedServers().forEach((server) => url.searchParams.append('server_ids[]', server.value));
                window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
            };

            const renderTags = () => {
                tagList.replaceChildren();
                selectedTags().forEach((value) => {
                    const chip = document.createElement('span');
                    chip.className = 'badge badge-primary d-inline-flex align-items-center';
                    chip.textContent = value;
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'btn btn-link btn-sm text-white p-0 ml-1';
                    remove.setAttribute('aria-label', `Remove ${value}`);
                    remove.innerHTML = '<i class="fas fa-times" aria-hidden="true"></i>';
                    remove.addEventListener('click', () => {
                        tags.delete(value);
                        localStorage.setItem('queues', selectedTags().join(','));
                        renderTags();
                    });
                    chip.append(remove);
                    tagList.append(chip);
                });
            };

            const addTag = (value) => {
                const tag = value.trim();
                if (!tag) return;
                tags.add(tag);
                localStorage.setItem('queues', selectedTags().join(','));
                renderTags();
            };

            const renderResults = (items) => {
                results.replaceChildren();
                resultsWrap.classList.remove('d-none');
                items.forEach((item) => {
                    const row = document.createElement('tr');
                    const run = document.createElement('td');
                    const name = document.createElement('td');
                    const command = document.createElement('td');
                    const result = document.createElement('td');
                    run.textContent = item.run || '—';
                    name.textContent = item.name || item.id || 'AlphaLink';
                    command.textContent = item.command || '';
                    result.textContent = String(item.result || '');
                    row.append(run, name, command, result);
                    results.append(row);
                });
            };

            const commandForAction = (action, queue, values = []) => {
                if (action === 'reset') return 'reset counters';
                return values.map((tag) => {
                    if (action === 'pause') return `pmta pause queue ${tag}`;
                    if (action === 'resume') return `resume queue ${tag}`;
                    if (action === 'delete') return queue === 'campaign' ? `delete --jobId=${tag}` : `delete --queue=${tag}`;
                    if (action === 'schedule') return `schedule ${tag}`;
                    return '';
                }).filter(Boolean).join('\n');
            };

            const refreshMonitor = (frame) => {
                if (!frame.dataset.monitorBase) return;
                frame.dataset.monitorRefreshPending = 'true';
                const url = new URL(frame.dataset.monitorBase, window.location.href);
                url.searchParams.set('_', String(Date.now()));
                frame.src = url.toString();
            };

            const drawPanels = () => {
                panels.replaceChildren();
                const selected = selectedServers();
                const hasSelectedServers = selected.length > 0;
                panels.hidden = !hasSelectedServers;
                const columns = {'2': 6, '3': 4, '4': 3}[document.querySelector('input[name="pmta-grid"]:checked')?.value || '2'];
                selected.forEach((server) => {
                    const column = document.createElement('div');
                    column.className = `col-12 col-md-${columns} mb-3`;
                    const heading = document.createElement('h6');
                    heading.className = 'mb-2 px-2 d-flex align-items-center';
                    const label = document.createElement('span');
                    label.className = 'text-danger';
                    label.textContent = server.textContent.trim();
                    const refreshLabel = document.createElement('span');
                    refreshLabel.className = 'small text-muted ml-2 pmta-monitor-refresh-label';
                    refreshLabel.textContent = 'Loading · auto refresh 5s';
                    const link = document.createElement('a');
                    link.href = server.dataset.panelUrl;
                    link.hidden = !server.dataset.panelUrl;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.className = 'ml-1';
                    try {
                        const panelUrl = new URL(server.dataset.panelUrl, window.location.href);
                        link.textContent = `${panelUrl.protocol}//${panelUrl.host}`;
                        link.title = `Open the PMTA monitor at ${panelUrl.protocol}//${panelUrl.host} in a new tab.`;
                    } catch {
                        link.textContent = server.textContent.split('|').at(-1).trim();
                        link.title = 'Open the PMTA monitor in a new tab.';
                    }
                    const reload = document.createElement('button');
                    reload.type = 'button';
                    reload.className = 'btn btn-link btn-sm p-0 ml-2';
                    reload.title = 'Reload PMTA monitor';
                    reload.setAttribute('aria-label', `Reload PMTA monitor for ${server.textContent.trim()}`);
                    reload.innerHTML = '<i class="fas fa-sync-alt" aria-hidden="true"></i>';
                    const frame = document.createElement('iframe');
                    frame.title = `PMTA Web Monitor — ${server.textContent.trim()}`;
                    frame.dataset.monitorBase = server.dataset.monitorUrl;
                    frame.referrerPolicy = 'no-referrer';
                    frame.scrolling = 'yes';
                    frame.style.width = '100%';
                    frame.style.height = '500px';
                    frame.addEventListener('load', () => {
                        if (!frame.dataset.monitorRefreshPending) return;
                        frame.dataset.monitorRefreshPending = 'false';
                        refreshLabel.textContent = `Updated ${new Date().toLocaleTimeString()} · auto refresh 5s`;
                    });
                    frame.addEventListener('error', () => {
                        frame.dataset.monitorRefreshPending = 'false';
                        refreshLabel.textContent = 'Monitor unavailable · retrying every 5s';
                    });
                    reload.addEventListener('click', () => refreshMonitor(frame));
                    heading.append(label, link, reload, refreshLabel);
                    let monitorUrl = null;
                    try {
                        if (!server.dataset.monitorUrl) throw new Error('PMTA monitor URL is unavailable');
                        monitorUrl = new URL(server.dataset.monitorUrl, window.location.href);
                    } catch {
                        monitorUrl = null;
                    }
                    const insecureForPage = window.location.protocol === 'https:' && monitorUrl?.protocol !== 'https:';
                    if (monitorUrl === null || insecureForPage) {
                        refreshLabel.textContent = monitorUrl === null
                            ? 'PMTA monitor URL is unavailable · check the saved server address and installation state'
                            : 'PMTA uses the public IP over HTTP · open the link in a new tab; HTTPS embedding requires a verified domain certificate';
                        column.append(heading);
                        panels.append(column);
                        return;
                    }
                    column.append(heading, frame);
                    panels.append(column);
                    refreshMonitor(frame);
                });
            };

            const postAction = async (url, fields, runNumber = null, {render = true, commandText = null} = {}) => {
                const body = new URLSearchParams();
                body.set('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
                Object.entries(fields).forEach(([key, value]) => {
                    if (Array.isArray(value)) value.forEach((item) => body.append(`${key}[]`, item));
                    else body.set(key, value);
                });
                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                        body: body.toString(),
                    });
                    const payload = await response.json();
                    if (!response.ok) {
                        const message = payload.message || Object.values(payload.errors || {}).flat().join('\n') || 'The PMTA action could not be completed.';
                        if (render) renderResults([{name: 'AlphaLink', result: message}]);
                        return {success: false, received: false, items: [], message};
                    }
                    const items = payload.data || [];
                    const command = commandText ?? commandForAction(fields.action, fields.queue, fields.tags || []);
                    if (render && (runNumber === null || runNumber >= latestDisplayedRun)) {
                        latestDisplayedRun = runNumber ?? latestDisplayedRun;
                        renderResults(items.map((item) => ({
                            ...item,
                            run: runNumber ?? '—',
                            command,
                        })));
                    }
                    return {success: items.length > 0 && items.every((item) => item.success === true), received: true, items, message: null};
                } catch {
                    const message = 'AlphaLink could not reach the PMTA action endpoint.';
                    if (render) renderResults([{name: 'AlphaLink', result: message}]);
                    return {success: false, received: false, items: [], message};
                }
            };

            const queueAction = async (action, serverIds = selectedServers().map((server) => server.value), values = selectedTags(), queue = document.querySelector('input[name="pmta-queue"]:checked').value, runNumber = null, options = {}) => {
                if (serverIds.length === 0) {
                    servers.focus();
                    return {success: false, received: false, items: []};
                }
                if (!values.includes('*/*')) {
                    tagEntry.focus();
                    renderResults([{name: 'AlphaLink', result: 'Select the */* queue tag before executing a PMTA command.'}]);
                    return {success: false, received: false, items: []};
                }
                return postAction(root.dataset.actionUrl, {action, server_ids: serverIds, tags: values, queue}, runNumber, options);
            };

            const scheduleSelectedServers = async (serverIds, serverNames, values, queue, runNumber, seconds) => {
                const command = commandForAction('schedule', queue, values);
                scheduleResultsByServer.clear();
                serverIds.forEach((serverId) => {
                    scheduleResultsByServer.set(serverId, {
                        id: serverId,
                        name: serverNames.get(serverId) || 'SERVER',
                        run: runNumber,
                        command,
                        result: 'Loading…',
                    });
                });
                renderResults(Array.from(scheduleResultsByServer.values()));

                const responses = await Promise.all(serverIds.map(async (serverId) => {
                    scheduleInFlight++;
                    try {
                        let response;
                        try {
                            response = await queueAction('schedule', [serverId], values, queue, runNumber, {render: false, commandText: command});
                        } catch (error) {
                            response = {success: false, received: false, items: [], message: error.message || 'The PMTA schedule request failed.'};
                        }
                        if (response.received) scheduleResponseCount++;
                        if (seconds === 0) scheduleButton.disabled = false;
                        if (!response.received && scheduleActive) {
                            scheduleActive = false;
                            scheduleStopReason = 'transport';
                            if (scheduleTimer !== null) window.clearInterval(scheduleTimer);
                            scheduleTimer = null;
                            scheduleButton.disabled = false;
                        }

                        const item = response.items.find((entry) => String(entry.id) === String(serverId)) || response.items[0];
                        scheduleResultsByServer.set(serverId, {
                            ...(item || {}),
                            id: serverId,
                            name: item?.name || serverNames.get(serverId) || 'SERVER',
                            run: runNumber,
                            command,
                            result: item?.result || response.message || 'No result returned.',
                        });
                        renderResults(Array.from(scheduleResultsByServer.values()));

                        return response;
                    } finally {
                        scheduleInFlight--;
                        refreshStoppedScheduleStatus();
                    }
                }));

                return {
                    success: responses.length > 0 && responses.every((response) => response.success),
                    received: responses.length > 0 && responses.every((response) => response.received),
                    items: responses.flatMap((response) => response.items || []),
                };
            };

            let restartRunNumber = 0;
            let latestRestartRun = 0;
            let restartResultsSuppressed = false;
            const restartResultsByServer = new Map();
            const clearRestartResults = () => {
                restartResultsByServer.clear();
                results.replaceChildren();
                resultsWrap.classList.add('d-none');
            };
            const restartSelectedServers = (serverIds, serverNames, reenableButtonOnCompletion) => {
                const runNumber = ++restartRunNumber;
                latestRestartRun = runNumber;
                restartResultsSuppressed = false;
                restartResultsByServer.clear();
                serverIds.forEach((serverId) => {
                    restartResultsByServer.set(serverId, {
                        id: serverId,
                        name: serverNames.get(serverId) || 'SERVER',
                        run: runNumber,
                        command: 'RESTART',
                        result: 'Restarting…',
                    });
                });
                renderResults(Array.from(restartResultsByServer.values()));

                return Promise.all(serverIds.map(async (serverId) => {
                    try {
                        const outcome = await postAction(root.dataset.restartUrl, {server_ids: [serverId]}, null, {render: false, commandText: 'RESTART'});
                        if (!outcome.received) {
                            restartResultsSuppressed = true;
                            if (restartTimer !== null) {
                                window.clearInterval(restartTimer);
                                restartTimer = null;
                            }
                            clearRestartResults();

                            return;
                        }

                        if (!restartResultsSuppressed && runNumber === latestRestartRun) {
                            const item = outcome.items[0] || {
                                id: serverId,
                                name: serverNames.get(serverId) || 'SERVER',
                                result: 'No PMTA restart result was returned.',
                                success: false,
                            };
                            restartResultsByServer.set(serverId, {
                                ...item,
                                run: runNumber,
                                command: 'RESTART',
                            });
                            renderResults(Array.from(restartResultsByServer.values()));
                        }
                    } finally {
                        if (reenableButtonOnCompletion) {
                            restartButton.disabled = false;
                            restartButton.removeAttribute('aria-busy');
                            restartButton.innerHTML = restartButtonLabel;
                        }
                    }
                }));
            };

            servers.addEventListener('change', () => {
                rememberSelection();
                drawPanels();
            });
            gridInputs.forEach((input) => input.addEventListener('change', () => {
                localStorage.setItem('pmta-admin-grid', input.value);
                drawPanels();
            }));
            document.querySelectorAll('.pmta-admin-action').forEach((button) => button.addEventListener('click', async () => {
                const action = button.dataset.action;
                if (action !== 'schedule') {
                    button.disabled = true;
                    try { await queueAction(action); } finally { button.disabled = false; }
                    return;
                }

                scheduleStatus.hidden = false;

                if (scheduleTimer !== null) window.clearInterval(scheduleTimer);
                scheduleTimer = null;
                scheduleButton.disabled = false;
                const seconds = Number(document.getElementById('pmta-admin-seconds').value || 0);
                if (!Number.isInteger(seconds) || seconds < 0) {
                    scheduleStatus.textContent = 'Enter a whole number of seconds. Zero runs once; a positive value repeats on that interval.';
                    document.getElementById('pmta-admin-seconds').focus();
                    return;
                }
                const serverIds = selectedServers().map((server) => server.value);
                const values = selectedTags();
                const queue = document.querySelector('input[name="pmta-queue"]:checked').value;
                if (serverIds.length === 0 || !values.includes('*/*')) {
                    await queueAction('schedule', serverIds, values, queue);
                    scheduleStatus.textContent = 'Schedule did not start. Select servers and the */* queue tag first.';
                    return;
                }
                scheduleRuns = 0;
                scheduleResponseCount = 0;
                scheduleStopReason = null;
                latestDisplayedRun = 0;
                scheduleServerCount = serverIds.length;
                latestScheduleStatusRun = 0;
                scheduleResultsByServer.clear();
                scheduleActive = seconds > 0;
                scheduleButton.disabled = true;
                const scheduleOnce = async () => {
                    const runNumber = ++scheduleRuns;
                    latestScheduleStatusRun = Math.max(latestScheduleStatusRun, runNumber);
                    const serverNames = new Map(selectedServers().map((server) => [server.value, server.textContent.trim()]));
                    const result = await scheduleSelectedServers(serverIds, serverNames, values, queue, runNumber, seconds);
                    const completed = result.success;
                    const noMatchingQueueJobs = completed && result.items.length > 0 && result.items.every((item) => /^\s*0 of 0 matching queues scheduled\.?\s*$/i.test(String(item.result || '')));
                    const attemptedCommands = scheduleRuns * scheduleServerCount;
                    const latestRun = runNumber === latestScheduleStatusRun;

                    if (!result.received) {
                        if (scheduleActive) {
                            scheduleActive = false;
                            scheduleStopReason = 'transport';
                            if (scheduleTimer !== null) window.clearInterval(scheduleTimer);
                            scheduleTimer = null;
                            scheduleButton.disabled = false;
                            refreshStoppedScheduleStatus();
                        } else if (seconds === 0) {
                            scheduleButton.disabled = false;
                            scheduleStatus.textContent = 'One schedule request failed before a PMTA response. Inspect the latest server result, then retry.';
                        }
                        return false;
                    }

                    if (latestRun && completed && noMatchingQueueJobs) {
                        scheduleStatus.textContent = scheduleActive
                            ? `Schedule is active, but PMTA found no matching queued jobs in run ${runNumber}. It will check again in ${seconds} second(s) until STOP.`
                            : 'One schedule run completed, but PMTA found no matching queued jobs, so nothing was scheduled.';
                    } else if (latestRun && completed) {
                        scheduleStatus.textContent = scheduleActive
                            ? `Schedule running: schedule */* executes immediately and then every ${seconds} second(s). Submitted ${scheduleRuns} run(s), received ${scheduleResponseCount} server response(s); ${attemptedCommands} server command request(s). Latest result: run ${runNumber} at ${new Date().toLocaleTimeString()}.`
                            : seconds === 0
                                ? `One schedule run returned. ${attemptedCommands} server command attempt(s) were submitted.`
                                : `Schedule stopped after ${scheduleRuns} submitted run(s); ${scheduleResponseCount} server response(s) received and ${attemptedCommands} server command request(s) were made.`;
                    } else if (latestRun) {
                        scheduleStatus.textContent = scheduleActive
                            ? `Schedule is active; run ${runNumber} returned a PMTA error. It will check again in ${seconds} second(s) until STOP. Inspect the latest server result.`
                            : seconds === 0
                                ? 'One schedule run returned a PMTA error. Inspect the latest server result, then retry.'
                                : `Schedule stopped after ${scheduleRuns} submitted run(s); the latest PMTA response reported an error.`;
                    }
                    if (seconds === 0) scheduleButton.disabled = false;
                    return completed;
                };
                scheduleStatus.textContent = seconds > 0
                    ? `Schedule starting: schedule */* will run once now and every ${seconds} second(s) on ${serverIds.length} selected server(s).`
                    : `One schedule run starting for ${serverIds.length} selected server(s).`;
                if (seconds > 0) scheduleTimer = window.setInterval(() => { void scheduleOnce(); }, seconds * 1000);
                void scheduleOnce();
            }));

            document.getElementById('pmta-admin-stop-schedule').addEventListener('click', () => {
                if (scheduleTimer !== null) window.clearInterval(scheduleTimer);
                scheduleTimer = null;
                scheduleActive = false;
                if (scheduleStopReason === null) scheduleStopReason = 'manual';
                scheduleButton.disabled = false;
                refreshStoppedScheduleStatus();
            });

            restartButton.addEventListener('click', async () => {
                if (restartTimer !== null) window.clearInterval(restartTimer);
                restartTimer = null;
                const selected = selectedServers();
                const serverIds = selected.map((server) => server.value);
                if (serverIds.length === 0) {
                    if (typeof window.PmtaControls?.alert === 'function') {
                        await window.PmtaControls.alert('Oops', 'Please select at least one server');
                    } else {
                        window.AlphaLinkToast?.show('Please select at least one server', 'warning');
                    }
                    servers.focus();
                    return;
                }
                const seconds = Number(document.getElementById('pmta-admin-restart-seconds').value || 0);
                const serverNames = new Map(selected.map((server) => [server.value, server.textContent.trim()]));
                restartButton.disabled = true;
                restartButton.setAttribute('aria-busy', 'true');
                restartButton.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> RESTARTING';
                if (seconds >= 5) {
                    void restartSelectedServers(serverIds, serverNames, false);
                    restartTimer = window.setInterval(() => {
                        void restartSelectedServers(serverIds, serverNames, false);
                    }, seconds * 1000);
                    return;
                }
                await restartSelectedServers(serverIds, serverNames, true);
            });
            document.getElementById('pmta-admin-stop-restart').addEventListener('click', () => {
                if (restartTimer !== null) window.clearInterval(restartTimer);
                restartTimer = null;
                restartButton.disabled = false;
                restartButton.removeAttribute('aria-busy');
                restartButton.innerHTML = restartButtonLabel;
            });

            document.getElementById('pmta-admin-clear').addEventListener('click', () => {
                if (window.jQuery?.fn.selectpicker) window.jQuery(servers).selectpicker('val', []);
                else Array.from(servers.options).forEach((option) => { option.selected = false; });
                tags.clear();
                localStorage.setItem('queues', '');
                tagEntry.value = '';
                renderTags();
                resultsWrap.classList.add('d-none');
                results.replaceChildren();
                rememberSelection();
                drawPanels();
            });

            document.getElementById('pmta-admin-reload-servers').addEventListener('click', () => {
                rememberSelection();
                window.location.reload();
            });
            document.getElementById('pmta-admin-search-servers').addEventListener('click', async (event) => {
                event.preventDefault();
                const {value: query, isConfirmed} = await window.swal.fire({
                    input: 'textarea',
                    inputLabel: 'Search Servers',
                    inputPlaceholder: 'Type your servers here...',
                    inputAttributes: {
                        'aria-label': 'Type your servers here'
                    },
                    showCancelButton: true
                });

                if (!isConfirmed || !query) return;

                const terms = [...new Set(query.split('\n').filter((term) => term))];
                const matchingServerIds = new Set();
                terms.forEach((term) => {
                    Array.from(servers.options).forEach((option) => {
                        const [name = '', ip = ''] = option.textContent.split('|');
                        if (name.trim().toLowerCase() === term.toLowerCase() || ip.trim().includes(term)) {
                            matchingServerIds.add(option.value);
                        }
                    });
                });

                const selectedIds = Array.from(matchingServerIds);
                if (window.jQuery?.fn.selectpicker) {
                    window.jQuery(servers).selectpicker('val', selectedIds);
                    window.jQuery(servers).selectpicker('refresh');
                } else {
                    Array.from(servers.options).forEach((option) => {
                        option.selected = matchingServerIds.has(option.value);
                    });
                }
                servers.dispatchEvent(new Event('change', {bubbles: true}));
            });
            tagEntry.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' || event.key === ',') {
                    event.preventDefault();
                    addTag(tagEntry.value);
                    tagEntry.value = '';
                }
                if (event.key === 'Backspace' && tagEntry.value === '' && tags.size > 0) {
                    tags.delete(selectedTags().at(-1));
                    localStorage.setItem('queues', selectedTags().join(','));
                    renderTags();
                }
            });
            tagEntry.addEventListener('blur', () => {
                if (tagEntry.value.trim() !== '') { addTag(tagEntry.value); tagEntry.value = ''; }
            });
            document.querySelectorAll('input[name="pmta-queue"]').forEach((radio) => radio.addEventListener('change', () => {
                tagEntry.placeholder = radio.value === 'campaign' ? 'Campaign ID' : 'Domain/VMTA';
            }));

            renderTags();
            if (window.jQuery?.fn.selectpicker) window.jQuery(servers).selectpicker();
            drawPanels();
            monitorRefreshTimer = window.setInterval(() => {
                panels.querySelectorAll('iframe[data-monitor-base]').forEach(refreshMonitor);
            }, monitorRefreshMs);
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) panels.querySelectorAll('iframe[data-monitor-base]').forEach(refreshMonitor);
            });
        })();
    </script>
@endpush
