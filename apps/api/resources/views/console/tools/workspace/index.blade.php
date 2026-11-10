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
            const table = document.getElementById('workspace-results');
            const request = async (url, method = 'POST', body = {}) => {
                const response = await fetch(url, {method, headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf}, body: method === 'GET' ? undefined : JSON.stringify(body)});
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || payload.error || payload.data || Object.values(payload.errors || {}).flat().join(' ') || 'Request failed.');
                return payload;
            };
            const showStatus = (message, tone = 'success') => { status.textContent = message; status.className = `alert alert-${tone} mb-4`; };
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

                    const payload = await request(`${base}/${action.toLowerCase()}`, action === 'RESET' ? 'DELETE' : 'POST', body);
                    const rows = Array.isArray(payload.data) ? payload.data : [];
                    if (rows.length) {
                        draw(rows);
                        const succeeded = rows.filter((row) => row.success === true).length;
                        const failed = rows.length - succeeded;
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
                        table.innerHTML = `<tbody><tr><td colspan="2">${escape(payload.data || successText)}</td></tr></tbody>`;
                        showStatus(`${successText} No per-server results were returned.`, 'warning');
                        if (action === 'RESET') {
                            await showDialog({icon:'warning',title:'Workspace reset returned no server results',text:payload.data || 'The server did not return a reset result.',confirmButtonText:'CLOSE'});
                        }
                    }
                } catch (error) {
                    if (!table.innerHTML) table.innerHTML = `<tbody><tr><td colspan="2" class="text-danger">${escape(error.message)}</td></tr></tbody>`;
                    showStatus(error.message, 'danger');
                    if (action === 'RESET') await showDialog({icon:'error',title:'Workspace reset failed',text:error.message,confirmButtonText:'CLOSE'});
                } finally {
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
