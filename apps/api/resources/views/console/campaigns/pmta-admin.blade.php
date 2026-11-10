@extends('layouts.admin-shell')

@section('title', 'Pmta Admin')

@section('content')
    <style>
        #pmta-monitor-refresh-status[hidden],
        #pmta-admin-panels[hidden] {
            display: none !important;
        }

        .pmta-admin-container {
            width: 100%;
        }

        .pmta-admin-surface {
            background: rgba(139, 92, 246, 0.03);
            border: 1px solid var(--command-os-border, rgba(139, 92, 246, 0.12));
            border-radius: 12px;
            padding: 1rem 1.25rem;
            transition: all 0.2s ease;
        }

        html[data-command-theme='dark'] .pmta-admin-surface {
            background: rgba(15, 23, 42, 0.45);
            border-color: rgba(139, 92, 246, 0.22);
        }

        .pmta-admin-action-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .pmta-admin-action-group {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pmta-automation-box {
            display: flex;
            flex-direction: column;
            gap: 8px;
            height: 100%;
        }

        .pmta-automation-input-row {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        @media (max-width: 575.98px) {
            .pmta-automation-input-row {
                flex-wrap: wrap;
            }
            .pmta-automation-input-row input {
                width: 100% !important;
                flex: 1 1 100% !important;
            }
            .pmta-automation-input-row button {
                flex: 1 1 calc(50% - 4px);
            }
        }
    </style>
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-desktop"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">POWERMTA MANAGEMENT // DAEMON MONITOR</div>
                    <h1 class="command-os-report-hero-title">PMTA CONTROLLER</h1>
                    <p class="command-os-report-hero-desc">Real-time PowerMTA daemon management, multi-server monitor grid, queue manipulation, and restart automation.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">DAEMON ORCHESTRATION</span>
                </div>
            </div>
        </header>

        <div class="command-os-table-card p-4 mb-4" data-pmta-admin
             data-action-url="{{ $actionUrl }}"
             data-restart-url="{{ $restartUrl }}">
            
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <h3 style="font-size: 0.9375rem; font-weight: 700; color: var(--command-os-text, #1e293b); margin: 0; text-transform: uppercase; letter-spacing: 0.04em;">
                    <i class="fas fa-terminal mr-1 text-purple"></i> PMTA Queue &amp; Daemon Controls
                </h3>
                <div class="d-flex align-items-center" style="gap: 12px;">
                    <span style="font-size: 0.75rem; font-weight: 700; color: var(--command-os-text-muted, #64748b);">GRID:</span>
                    @foreach ([2, 3, 4] as $columns)
                        <label class="mb-0 d-inline-flex align-items-center" style="gap: 4px; font-size: 0.8125rem; font-weight: 600; cursor: pointer;">
                            <input type="radio" id="pmta-grid-{{ $columns }}" name="pmta-grid" value="{{ $columns }}" @checked($columns === 2)>
                            <span>x{{ $columns }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="pmta-admin-container" id="pmta-admin-controls">
                <!-- 1. Configuration Row: Servers on Left, Queue Target on Right -->
                <div class="row mb-3" id="pmta-admin-fields-row">
                    <div class="col-lg-6 mb-3 mb-lg-0" id="pmta-admin-server-column">
                        <div class="form-group mb-0">
                            <label class="command-form-label d-flex align-items-center justify-content-between mb-2" for="pmta-admin-servers">
                                <span><i class="fas fa-server mr-1 text-purple"></i> SERVERS:</span>
                                <span class="d-inline-flex" style="gap: 4px;">
                                    <button type="button" class="btn-command-action" id="pmta-admin-reload-servers" title="Reload servers" aria-label="Reload servers" style="padding: 2px 8px; font-size: 0.75rem;"><i class="fas fa-sync-alt" aria-hidden="true"></i></button>
                                    <button type="button" class="btn-command-action" id="pmta-admin-search-servers" title="Search servers" aria-label="Search servers" style="padding: 2px 8px; font-size: 0.75rem;"><i class="fas fa-search" aria-hidden="true"></i></button>
                                </span>
                            </label>
                            <select class="form-control selectpicker" id="pmta-admin-servers" data-live-search="true" data-actions-box="true" data-size="6" data-selected-text-format="count > 5" multiple title="Select Servers">
                                @foreach ($servers as $server)
                                    <option value="{{ $server['id'] }}" data-panel-url="{{ $server['pmta_panel_url'] }}" data-monitor-url="{{ $server['pmta_panel_display_url'] }}" data-monitor-endpoint="{{ $server['ip'] }}:{{ $pmtaAdminPort }}" data-monitor-proxied="{{ $server['pmta_panel_display_url'] !== $server['pmta_panel_url'] ? 'true' : 'false' }}" @selected(in_array((string) $server['id'], $selectedServerIds, true))>{{ $server['name'] }} | {{ $server['ip'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="col-lg-6" id="pmta-admin-tag-column">
                        <div class="form-group mb-0">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <span class="command-form-label mb-0"><i class="fas fa-filter mr-1 text-purple"></i> QUEUE TARGET:</span>
                                <fieldset class="border-0 p-0 m-0 d-inline-flex align-items-center" style="gap: 14px;">
                                    <legend class="sr-only">Queue:</legend>
                                    <label class="mb-0 d-inline-flex align-items-center" style="gap: 4px; font-size: 0.8125rem; font-weight: 600; cursor: pointer;">
                                        <input type="radio" id="pmta-domain-queue" name="pmta-queue" value="domain" checked>
                                        <span>DOMAIN / VMTA</span>
                                    </label>
                                    <label class="mb-0 d-inline-flex align-items-center" style="gap: 4px; font-size: 0.8125rem; font-weight: 600; cursor: pointer;">
                                        <input type="radio" id="pmta-campaign-queue" name="pmta-queue" value="campaign">
                                        <span>JOB ID</span>
                                    </label>
                                </fieldset>
                            </div>
                            <div class="form-control d-flex flex-wrap align-items-center" id="pmta-admin-tag-box" style="min-height: 40px; height: auto; gap: .35rem; padding: .35rem .65rem; border-radius: 10px;">
                                <div class="d-flex flex-wrap align-items-center" id="pmta-admin-tags" aria-live="polite"></div>
                                <input id="pmta-admin-tag-entry" type="text" class="border-0 flex-grow-1" style="width: 0; min-width: 100px; outline: none; background: transparent; font-size: 0.8125rem;" placeholder="Type Domain/VMTA and press Enter..." autocomplete="off">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. Queue Operations Action Bar -->
                <div class="pmta-admin-surface mb-3">
                    <div class="pmta-admin-action-bar">
                        <span class="font-w700 d-inline-flex align-items-center" style="font-size: 0.75rem; letter-spacing: 0.05em; color: var(--command-os-text-muted, #64748b); text-transform: uppercase;">
                            <i class="fas fa-tasks mr-1 text-purple"></i> Queue Controls
                        </span>
                        <div id="pmta-admin-action-row" class="pmta-admin-action-group" role="group" aria-label="PMTA queue actions">
                            <button type="button" class="btn-command-primary pmta-admin-action" data-action="pause" style="padding: 7px 14px; font-size: 0.75rem;"><i class="fas fa-pause mr-1"></i> PAUSE</button>
                            <button type="button" class="btn-command-primary pmta-admin-action" data-action="resume" style="padding: 7px 14px; font-size: 0.75rem;"><i class="fas fa-play mr-1"></i> RESUME</button>
                            <button type="button" class="btn-command-primary pmta-admin-action" data-action="delete" style="padding: 7px 14px; font-size: 0.75rem;"><i class="fas fa-trash-alt mr-1"></i> DELETE</button>
                            <button type="button" class="btn-command-primary pmta-admin-action" data-action="reset" style="padding: 7px 14px; font-size: 0.75rem;"><i class="fas fa-undo mr-1"></i> RESET</button>
                            <button type="button" class="btn-command-danger" id="pmta-admin-clear" style="padding: 7px 14px; font-size: 0.75rem;"><i class="fas fa-broom mr-1"></i> CLEAR</button>
                        </div>
                    </div>
                </div>

                <!-- 3. Automation Intervals (Schedule & Restart) -->
                <div class="row mb-3" id="pmta-admin-schedule-row">
                    <div class="col-xl-6 col-lg-12 mb-3 pmta-admin-schedule-column">
                        <div class="pmta-admin-surface pmta-automation-box">
                            <label class="command-form-label mb-0" for="pmta-admin-seconds" style="font-size: 0.8125rem;">
                                <i class="fas fa-clock mr-1 text-purple"></i> Queue Runner Interval:
                            </label>
                            <div class="pmta-automation-input-row">
                                <input id="pmta-admin-seconds" class="command-form-control flex-grow-1" type="number" min="1" placeholder="Seconds &gt;= 1" style="height: 38px;">
                                <button type="button" class="btn-command-primary pmta-admin-action text-nowrap" data-action="schedule" style="height: 38px; padding: 0 14px; font-size: 0.75rem;"><i class="fas fa-calendar-alt mr-1"></i> SCHEDULE</button>
                                <button type="button" class="btn-command-danger text-nowrap" id="pmta-admin-stop-schedule" style="height: 38px; padding: 0 14px; font-size: 0.75rem;"><i class="fas fa-stop mr-1"></i> STOP</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6 col-lg-12 mb-3 pmta-admin-restart-column">
                        <div class="pmta-admin-surface pmta-automation-box">
                            <label class="command-form-label mb-0" for="pmta-admin-restart-seconds" style="font-size: 0.8125rem;">
                                <i class="fas fa-redo-alt mr-1 text-purple"></i> Daemon Auto-Restart Interval:
                            </label>
                            <div class="pmta-automation-input-row">
                                <input id="pmta-admin-restart-seconds" class="command-form-control flex-grow-1" type="number" min="5" placeholder="Seconds &gt;= 5" style="height: 38px;">
                                <button type="button" class="btn-command-action pmta-admin-restart text-nowrap" style="height: 38px; padding: 0 14px; font-size: 0.75rem;"><i class="fas fa-sync-alt mr-1"></i> RESTART</button>
                                <button type="button" class="btn-command-danger text-nowrap" id="pmta-admin-stop-restart" style="height: 38px; padding: 0 14px; font-size: 0.75rem;"><i class="fas fa-stop mr-1"></i> STOP</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 4. Results Column -->
                <div id="pmta-admin-results-column">
                    <p id="pmta-admin-schedule-status" class="small text-muted mb-2" hidden role="status" aria-live="polite"></p>
                    <div class="table-responsive d-none" id="pmta-admin-results-wrap">
                        <table class="table table-sm table-hover table-striped command-os-table" id="pmta-admin-results" aria-live="polite">
                            <thead><tr><th>Run</th><th>Server</th><th>Command</th><th>Result</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <p class="small text-muted mb-3" style="font-size: 0.75rem;"><i class="fas fa-shield-alt mr-1"></i> HTTP-only monitors open through AlphaLink’s authenticated HTTPS read-only proxy. Verified PMTA certificates open directly over HTTPS.</p>
        <div class="row" id="pmta-admin-panels" @if ($selectedServerIds === []) hidden @endif aria-live="polite"></div>
    </div>
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
                    chip.className = 'badge-command-pill mr-1 d-inline-flex align-items-center';
                    chip.style.fontWeight = '700';
                    chip.textContent = value;
                    const remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'btn btn-link btn-sm text-danger p-0 ml-1';
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
                    const card = document.createElement('div');
                    card.className = 'command-os-table-card p-3 h-100';
                    card.style.borderRadius = '14px';
                    card.style.overflow = 'hidden';
                    const heading = document.createElement('h6');
                    heading.className = 'mb-2 px-2 d-flex align-items-center';
                    const label = document.createElement('span');
                    label.className = 'badge-command-pill mr-2';
                    label.style.fontWeight = '700';
                    label.style.color = '#8b5cf6';
                    label.textContent = server.textContent.trim();
                    const refreshLabel = document.createElement('span');
                    refreshLabel.className = 'small text-muted ml-2 pmta-monitor-refresh-label';
                    refreshLabel.textContent = 'Loading · auto refresh 5s';
                    const link = document.createElement('a');
                    link.href = server.dataset.monitorUrl || server.dataset.panelUrl;
                    link.hidden = !server.dataset.panelUrl;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.className = 'ml-1';
                    try {
                        const panelUrl = new URL(server.dataset.panelUrl, window.location.href);
                        link.textContent = server.dataset.monitorProxied === 'true'
                            ? `Open HTTPS read-only monitor: ${server.dataset.monitorEndpoint}`
                            : `${panelUrl.protocol}//${panelUrl.host}`;
                        link.title = server.dataset.monitorProxied === 'true'
                            ? `Open the PMTA monitor through AlphaLink HTTPS. AlphaLink fetches it from ${panelUrl.protocol}//${panelUrl.host}; monitor controls are read-only.`
                            : `Open the PMTA monitor at ${panelUrl.protocol}//${panelUrl.host} in a new tab.`;
                    } catch {
                        link.textContent = server.textContent.split('|').at(-1).trim();
                        link.title = 'Open the PMTA monitor in a new tab.';
                    }
                    const reload = document.createElement('button');
                    reload.type = 'button';
                    reload.className = 'btn-command-action ml-2';
                    reload.style.padding = '2px 8px';
                    reload.style.fontSize = '0.75rem';
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
                    frame.style.borderRadius = '10px';
                    frame.style.border = '1px solid var(--command-os-border, rgba(99, 102, 241, 0.15))';
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
                    const insecureForPage = window.location.protocol === 'https:'
                        && monitorUrl?.protocol !== 'https:'
                        && server.dataset.monitorProxied !== 'true';
                    if (monitorUrl === null || insecureForPage) {
                        refreshLabel.textContent = monitorUrl === null
                            ? 'PMTA monitor URL is unavailable · check the saved server address and installation state'
                            : 'PMTA monitor is HTTP-only · open the link in a new tab';
                        card.append(heading);
                        column.append(card);
                        panels.append(column);
                        return;
                    }
                    if (server.dataset.monitorProxied === 'true') {
                        frame.setAttribute('sandbox', '');
                        refreshLabel.textContent = 'Read-only monitor via AlphaLink HTTPS · auto refresh 5s';
                    }
                    card.append(heading, frame);
                    column.append(card);
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
