@extends('layouts.admin-shell')

@section('title', 'Workspace SMTP')

@section('content')
    <div class="command-os-report-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero mb-4">
            <div class="command-os-report-hero-left">
                <div class="command-os-report-hero-icon" aria-hidden="true">
                    <i class="fas fa-th-large"></i>
                </div>
                <div>
                    <div class="command-os-report-hero-eyebrow">OPERATIONS TOOLKIT // WORKSPACE SMTP AUTOMATION</div>
                    <h1 class="command-os-report-hero-title">PUSH WORKSPACE ACCOUNTS</h1>
                    <p class="command-os-report-hero-desc">Distribute configured server IPs and projects to workspace accounts with optional IP-splitting.</p>
                </div>
            </div>
            <div class="command-os-report-hero-right">
                <div class="command-os-report-hero-meta">
                    <span class="command-os-report-badge">TOOLKIT</span>
                </div>
            </div>
        </header>

        <!-- 2. Form Surface Card -->
        <div class="command-os-table-card p-4">
            <div id="workspace-progress-panel" class="d-none mb-3 p-3" style="border-radius: 12px; border: 1px solid rgba(99, 102, 241, 0.22); background: rgba(99, 102, 241, 0.04);">
                <div class="d-flex flex-wrap align-items-center justify-content-between mb-2" style="gap: 8px;">
                    <div class="d-flex align-items-center" style="gap: 8px; min-width: 0;">
                        <span id="workspace-progress-spinner" class="spinner-border spinner-border-sm text-purple" role="status" aria-hidden="true"></span>
                        <span id="workspace-progress-stage" class="font-w700 text-truncate" style="font-size: 0.8125rem;">Starting…</span>
                    </div>
                    <div class="d-flex flex-wrap align-items-center" style="gap: 6px; font-size: 0.75rem;">
                        <span class="badge badge-purple" id="workspace-progress-percent">0%</span>
                        <span class="badge badge-secondary" id="workspace-progress-servers">Servers: 0/0</span>
                        <span class="badge badge-info" id="workspace-progress-accounts" style="display:none;">Accounts: 0</span>
                        <span class="badge badge-success" id="workspace-progress-succeeded" style="display:none;">OK: 0</span>
                        <span class="badge badge-danger" id="workspace-progress-failed" style="display:none;">Failed: 0</span>
                        <span class="text-muted font-w600" id="workspace-progress-elapsed">0s</span>
                    </div>
                </div>
                <div class="progress" style="height: 8px; border-radius: 999px; background: rgba(148, 163, 184, 0.2); overflow: hidden;">
                    <div id="workspace-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%; background: linear-gradient(90deg, #6366f1, #8b5cf6); transition: width 0.35s ease;"></div>
                </div>
            </div>

            <div id="workspace-status" class="alert d-none mb-4" role="status" aria-live="polite" style="border-radius: 12px; font-size: 0.8125rem;"></div>

            <div class="row justify-content-center">
                <div class="col-12 col-md-8 col-lg-6">
                    <div class="form-group mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="command-form-label mb-0" for="workspace-ips">
                                <i class="fas fa-network-wired mr-1 text-purple"></i> TARGET IPS:
                            </label>
                            <button class="btn-command-action" id="workspace-search-ips" type="button" title="Search Servers" aria-label="Search servers" style="padding: 2px 8px; font-size: 0.75rem;">
                                <i class="fas fa-search mr-1" aria-hidden="true"></i> Batch Search
                            </button>
                        </div>
                        <select class="form-control selectpicker" id="workspace-ips" data-live-search="true" title="Select Ips" data-size="6" data-selected-text-format="count > 5" data-actions-box="true" multiple aria-label="Select IPs">
                            @foreach ($ips as $ip)
                                <option value="{{ $ip->id }}">{{ $ip->server?->name }}{{ $ip->managed_server_id }} | {{ $ip->ip }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="command-form-label mb-0" for="workspace-projects">
                                <i class="fas fa-project-diagram mr-1 text-purple"></i> PROJECT:
                            </label>
                            <button class="btn-command-action" id="workspace-search-projects" type="button" title="Search Projects by Email" aria-label="Search projects by email" style="padding: 2px 8px; font-size: 0.75rem;">
                                <i class="fas fa-search mr-1" aria-hidden="true"></i> Search By Email
                            </button>
                        </div>
                        <select class="form-control selectpicker" id="workspace-projects" data-live-search="true" title="Select Project" data-size="6" data-actions-box="true" multiple aria-label="Select Workspace projects">
                            @forelse ($projects->where('is_active', true) as $project)
                                <option value="{{ $project->id }}" data-email="{{ $project->name }}">{{ $project->name }} -- {{ $project->created_at }}</option>
                            @empty
                            @endforelse
                        </select>
                    </div>

                    <div class="form-group mb-4">
                        <div class="custom-control custom-switch">
                            <input class="custom-control-input" id="workspace-split" type="checkbox">
                            <label class="custom-control-label" for="workspace-split">SPLIT ON IPS</label>
                        </div>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mb-4" style="gap: 12px;">
                        <button type="button" class="btn-command-primary flex-grow-1" id="workspace-push" style="height: 40px;">
                            <i class="fas fa-paper-plane mr-1"></i> PUSH TO SERVERS
                        </button>
                        <button type="button" class="btn-command-danger" id="workspace-reset" style="height: 40px; padding: 0 18px;">
                            <i class="fas fa-undo mr-1"></i> RESET
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table id="workspace-results" class="table table-sm table-hover command-os-table"></table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (() => {
            const base = @json(url('/app/workspaces/'.$workspace->id.'/tools/workspace'));
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const status = document.getElementById('workspace-status');
            const progressPanel = document.getElementById('workspace-progress-panel');
            const progressBar = document.getElementById('workspace-progress-bar');
            const progressStage = document.getElementById('workspace-progress-stage');
            const progressPercent = document.getElementById('workspace-progress-percent');
            const progressServers = document.getElementById('workspace-progress-servers');
            const progressAccounts = document.getElementById('workspace-progress-accounts');
            const progressSucceeded = document.getElementById('workspace-progress-succeeded');
            const progressFailed = document.getElementById('workspace-progress-failed');
            const progressElapsed = document.getElementById('workspace-progress-elapsed');
            const progressSpinner = document.getElementById('workspace-progress-spinner');
            const table = document.getElementById('workspace-results');
            const request = async (url, method = 'POST', body = {}) => {
                const response = await fetch(url, {method, headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf}, body: method === 'GET' ? undefined : JSON.stringify(body)});
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    if (response.status === 429) {
                        const retryAfter = response.headers.get('Retry-After');
                        throw new Error(retryAfter ? `Too many attempts — wait ${retryAfter}s and retry.` : 'Too many attempts — wait a moment and retry.');
                    }
                    throw new Error(payload.message || payload.error || payload.data || Object.values(payload.errors || {}).flat().join(' ') || 'Request failed.');
                }
                return payload;
            };
            const showStatus = (message, tone = 'success') => { status.textContent = message; status.className = `alert alert-${tone} mb-4`; };
            const createOperationId = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                const r = (Math.random() * 16) | 0;
                return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
            }));
            const renderProgress = (state, startedAt) => {
                progressPanel.classList.remove('d-none');
                const percent = Math.max(0, Math.min(100, Number(state.percent ?? 0)));
                const stateStatus = state.status || 'running';
                progressBar.style.width = `${percent}%`;
                progressBar.setAttribute('aria-valuenow', String(percent));
                progressPercent.textContent = `${percent}%`;
                progressStage.textContent = state.stage || 'Working…';
                const totalServers = Number(state.total_servers ?? 0);
                const completedServers = Number(state.completed_servers ?? 0);
                progressServers.textContent = `Servers: ${completedServers}/${totalServers}`;
                const totalAccounts = Number(state.total_accounts ?? 0);
                if (totalAccounts > 0) {
                    progressAccounts.style.display = '';
                    progressAccounts.textContent = `Accounts: ${totalAccounts.toLocaleString()}`;
                } else {
                    progressAccounts.style.display = 'none';
                }
                const ok = Number(state.succeeded_servers ?? 0);
                const fail = Number(state.failed_servers ?? 0);
                progressSucceeded.style.display = (ok > 0 || stateStatus !== 'running') ? '' : 'none';
                progressSucceeded.textContent = `OK: ${ok}`;
                progressFailed.style.display = fail > 0 ? '' : 'none';
                progressFailed.textContent = `Failed: ${fail}`;
                const secs = Math.max(0, Math.round((Date.now() - startedAt) / 1000));
                progressElapsed.textContent = `${secs}s`;
                progressSpinner.classList.toggle('d-none', stateStatus !== 'running');
                progressBar.classList.toggle('progress-bar-animated', stateStatus === 'running');
                progressBar.classList.toggle('progress-bar-striped', stateStatus === 'running');
                if (stateStatus === 'completed') {
                    progressBar.style.background = 'linear-gradient(90deg, #10b981, #059669)';
                } else if (stateStatus === 'partial') {
                    progressBar.style.background = 'linear-gradient(90deg, #f59e0b, #d97706)';
                } else if (stateStatus === 'failed') {
                    progressBar.style.background = 'linear-gradient(90deg, #ef4444, #dc2626)';
                } else {
                    progressBar.style.background = 'linear-gradient(90deg, #6366f1, #8b5cf6)';
                }
            };
            const busy = (button, value) => { button.disabled = value; button.setAttribute('aria-busy', String(value)); };
            const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
            const selected = (element) => [...element.selectedOptions].map((option) => option.value);
            const draw = (rows) => { table.innerHTML = `<thead><tr><th class="col-md-4">Server</th><th class="col-md-8">Result</th></tr></thead><tbody class="collapse show">${rows.map((row) => `<tr><td><span class="command-mono-badge">${escape(row.id)}</span></td><td><strong>${escape(row.data)}</strong></td></tr>`).join('')}</tbody>`; };
            const showDialog = async (options) => {
                const dialog = window.swal?.fire ? window.swal : window.Swal?.fire ? window.Swal : null;
                if (dialog) return dialog.fire(options);
                if (options.input) {
                    const value = window.prompt(options.inputPlaceholder || options.inputLabel || 'Enter a value');
                    return {isConfirmed: value !== null, value: value ?? ''};
                }
                if (options.showCancelButton || options.showDenyButton || options.input) {
                    return {isConfirmed: window.confirm([options.title, options.text].filter(Boolean).join('\n\n') || 'Continue?')};
                }
                window.alert([options.title, options.text].filter(Boolean).join('\n\n'));
                return {isConfirmed: true};
            };
            const runAction = async (button, action, successText) => {
                busy(button, true);
                showStatus(`${action} in progress…`, 'info');
                table.innerHTML = '';
                let pollTimer = null;
                let latestState = { percent: 4, stage: `${action} starting…`, status: 'running', total_servers: 0, completed_servers: 0, succeeded_servers: 0, failed_servers: 0, total_accounts: 0 };
                const startedAt = Date.now();
                try {
                    const body = {ips:selected(document.getElementById('workspace-ips'))};
                    if (action === 'PUSH') {
                        body.projects = selected(document.getElementById('workspace-projects'));
                        body.split_on_ips = document.getElementById('workspace-split').checked;
                    }
                    if (!body.ips.length) {
                        await showDialog({icon:'error',title:'Oops',text:'Please select at least one IP.'});
                        throw new Error('Please select at least one IP.');
                    }
                    if (action === 'PUSH' && !body.projects.length) {
                        await showDialog({icon:'error',title:'Oops',text:'Please select a project.'});
                        throw new Error('Please select a project.');
                    }

                    const operationId = createOperationId();
                    body.operation_id = operationId;
                    renderProgress(latestState, startedAt);
                    pollTimer = window.setInterval(async () => {
                        try {
                            const progress = await request(`${base}/progress/${operationId}`, 'GET');
                            latestState = progress;
                            renderProgress(latestState, startedAt);
                        } catch (_) {
                            renderProgress(latestState, startedAt);
                        }
                    }, 750);

                    const payload = await request(`${base}/${action.toLowerCase()}`, action === 'RESET' ? 'DELETE' : 'POST', body);
                    window.clearInterval(pollTimer);
                    pollTimer = null;
                    const rows = Array.isArray(payload.data) ? payload.data : [];
                    if (rows.length) {
                        draw(rows);
                        const succeeded = rows.filter((row) => row.success === true).length;
                        const failed = rows.length - succeeded;
                        renderProgress({
                            ...latestState,
                            percent: 100,
                            status: failed === 0 ? 'completed' : succeeded > 0 ? 'partial' : 'failed',
                            total_servers: rows.length,
                            completed_servers: rows.length,
                            succeeded_servers: succeeded,
                            failed_servers: failed,
                            stage: failed === 0 ? `${action} completed (${succeeded}/${rows.length} servers).` : `${action} finished: ${succeeded} succeeded, ${failed} failed.`,
                        }, startedAt);
                        if (failed === 0) {
                            showStatus(`${successText} ${succeeded} server${succeeded === 1 ? '' : 's'} completed successfully.`, 'success');
                        } else if (succeeded > 0) {
                            showStatus(`${action} partially completed: ${succeeded} succeeded and ${failed} failed. See the server results below.`, 'warning');
                        } else {
                            showStatus(`${action} failed on ${failed} server${failed === 1 ? '' : 's'}. See the server results below.`, 'danger');
                        }
                        if (action === 'RESET') {
                            const summary = rows.map((row) => `${row.id}: ${row.data}`).join('\n');
                            await showDialog({
                                icon: failed === 0 ? 'success' : succeeded > 0 ? 'warning' : 'error',
                                title: failed === 0 ? 'Workspace reset complete' : succeeded > 0 ? 'Workspace reset partially completed' : 'Workspace reset failed',
                                text: summary,
                                confirmButtonText: 'CLOSE',
                            });
                        }
                    } else {
                        renderProgress({ ...latestState, percent: 100, status: 'partial', stage: 'No per-server results were returned.' }, startedAt);
                        table.innerHTML = `<tbody><tr><td colspan="2">${escape(payload.data || successText)}</td></tr></tbody>`;
                        showStatus(`${successText} No per-server results were returned.`, 'warning');
                        if (action === 'RESET') {
                            await showDialog({icon:'warning',title:'Workspace reset returned no server results',text:payload.data || 'The server did not return a reset result.',confirmButtonText:'CLOSE'});
                        }
                    }
                } catch (error) {
                    if (pollTimer) {
                        window.clearInterval(pollTimer);
                        pollTimer = null;
                    }
                    renderProgress({ ...latestState, percent: 100, status: 'failed', stage: error.message }, startedAt);
                    if (!table.innerHTML) table.innerHTML = `<tbody><tr><td colspan="2" class="text-danger">${escape(error.message)}</td></tr></tbody>`;
                    showStatus(error.message, 'danger');
                    if (action === 'RESET') await showDialog({icon:'error',title:'Workspace reset failed',text:error.message,confirmButtonText:'CLOSE'});
                } finally {
                    if (pollTimer) window.clearInterval(pollTimer);
                    busy(button, false);
                }
            };
            document.getElementById('workspace-push').addEventListener('click', (event) => runAction(event.currentTarget, 'PUSH', 'Workspace push:'));
            document.getElementById('workspace-reset').addEventListener('click', async (event) => {
                const button = event.currentTarget;
                if (!selected(document.getElementById('workspace-ips')).length) {
                    await showDialog({icon:'error',title:'Oops',text:'Please select at least one IP.'});
                    showStatus('Please select at least one IP.', 'danger');
                    return;
                }
                const decision = await showDialog({icon:'question',title:'Are you sure ?',text:'Your Smtp\'s in those servers will be reset',showCancelButton:true,confirmButtonText:'YES',allowOutsideClick:false,showCloseButton:true});
                if (decision.isConfirmed || decision.value) {
                    await runAction(button, 'RESET', 'Workspace SMTP reset:');
                } else {
                    showStatus('RESET cancelled. No servers were changed.', 'info');
                }
            });
            const applySelection = (select, values) => { if (window.jQuery?.fn?.selectpicker) window.jQuery(select).selectpicker('val', values).selectpicker('refresh'); else for (const option of select.options) option.selected = values.includes(option.value); };
            document.getElementById('workspace-search-ips').addEventListener('click', async () => { const result = await showDialog({input:'textarea',inputLabel:'Search Ips',inputPlaceholder:'Type your Ips here...',inputAttributes:{'aria-label':'Type your servers here'},showCancelButton:true}); const text = result.value; if (!text) return; const queries = [...new Set(text.split('\n').map((value) => value.trim().toLowerCase()).filter(Boolean))]; const ids = [...document.querySelectorAll('#workspace-ips option')].filter((option) => queries.some((query) => option.textContent.toLowerCase().trim().includes(query))).map((option) => option.value); applySelection(document.getElementById('workspace-ips'), ids); });
            document.getElementById('workspace-search-projects').addEventListener('click', async () => { const result = await showDialog({input:'textarea',inputLabel:'Search Projects by Email',inputPlaceholder:'Type emails here, one per line...',inputAttributes:{'aria-label':'Type emails here'},showCancelButton:true}); const emails = new Set((result.value || '').split('\n').map((value) => value.trim().toLowerCase()).filter(Boolean)); if (!emails.size) return; const ids = [...document.querySelectorAll('#workspace-projects option')].filter((option) => emails.has((option.dataset.email || '').toLowerCase())).map((option) => option.value); applySelection(document.getElementById('workspace-projects'), ids); });
        })();
    </script>
@endsection
