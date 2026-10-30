@extends('layouts.dms-admin')

@section('title', 'Workspace SMTP - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">PUSH WORKSPACE ACCOUNTS</h3>
            <div class="block-options"><button class="btn-block-option" type="button" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button></div>
        </div>
        <div class="block-content">
            <div id="workspace-status" class="alert d-none" role="status" aria-live="polite"></div>
            <div class="row justify-content-center">
                <div class="col-12 col-md-6">
                    <div class="form-group">
                        <label for="workspace-ips">IPs: <button class="btn btn-link btn-sm p-0" id="workspace-search-ips" type="button" title="Search Servers" aria-label="Search servers"><i class="fas fa-search" aria-hidden="true"></i></button></label>
                        <select class="form-control selectpicker" id="workspace-ips" data-live-search="true" title="Select Ips" data-size="6" data-selected-text-format="count > 5" data-actions-box="true" multiple aria-label="Select IPs">
                            @foreach ($ips as $ip)
                                <option value="{{ $ip->id }}">{{ $ip->server?->name }}{{ $ip->managed_server_id }} | {{ $ip->ip }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="workspace-projects">Project: <button class="btn btn-link btn-sm p-0" id="workspace-search-projects" type="button" title="Search Projects by Email" aria-label="Search projects by email"><i class="fas fa-search" aria-hidden="true"></i></button></label>
                        <select class="form-control selectpicker" id="workspace-projects" data-live-search="true" title="Select Project" data-size="6" data-actions-box="true" multiple aria-label="Select Workspace projects">
                            @forelse ($projects->where('is_active', true) as $project)
                                <option value="{{ $project->id }}" data-email="{{ $project->name }}">{{ $project->name }} -- {{ $project->created_at }}</option>
                            @empty
                            @endforelse
                        </select>
                    </div>
                    <div class="form-group"><div class="custom-control custom-switch custom-control-inline"><input class="custom-control-input" id="workspace-split" type="checkbox"><label class="custom-control-label" for="workspace-split">SPLIT ON IPS</label></div></div>
                    <div class="form-group">
                        <button type="button" class="btn btn-primary" id="workspace-push">PUSH</button>
                        <button type="button" class="btn btn-danger float-md-right" id="workspace-reset">RESET</button>
                    </div>
                    <div class="table-responsive"><table id="workspace-results" class="table table-sm table-hover table-bordered"></table></div>
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
            const showStatus = (message, tone = 'success') => { status.textContent = message; status.className = `alert alert-${tone}`; };
            const busy = (button, value) => { button.disabled = value; button.setAttribute('aria-busy', String(value)); };
            const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
            const selected = (element) => [...element.selectedOptions].map((option) => option.value);
            const draw = (rows) => { table.innerHTML = `<thead><tr><th class="col-md-4">Server</th><th class="col-md-8">Result</th></tr></thead><tbody class="collapse show">${rows.map((row) => `<tr><td>${escape(row.id)}</td><td><strong>${escape(row.data)}</strong></td></tr>`).join('')}</tbody>`; };
            const showDialog = async (options) => { const dialog = window.Swal || window.swal; return dialog?.fire ? dialog.fire(options) : {isConfirmed: window.confirm(options.text || options.title || 'Continue?')}; };
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
                    } else {
                        table.innerHTML = `<tbody><tr><td colspan="2">${escape(payload.data || successText)}</td></tr></tbody>`;
                        showStatus(`${successText} No per-server results were returned.`, 'warning');
                    }
                } catch (error) {
                    if (!table.innerHTML) table.innerHTML = `<tbody><tr><td colspan="2" class="text-danger">${escape(error.message)}</td></tr></tbody>`;
                    showStatus(error.message, 'danger');
                } finally {
                    busy(button, false);
                }
            };
            document.getElementById('workspace-push').addEventListener('click', (event) => runAction(event.currentTarget, 'PUSH', 'Workspace push:'));
            document.getElementById('workspace-reset').addEventListener('click', async (event) => {
                if (!selected(document.getElementById('workspace-ips')).length) {
                    await showDialog({icon:'error',title:'Oops',text:'Please select at least one IP.'});
                    showStatus('Please select at least one IP.', 'danger');
                    return;
                }
                const decision = await showDialog({icon:'question',title:'Are you sure?',text:'Your SMTP configurations on these servers will be reset.',showCancelButton:true,confirmButtonText:'YES',allowOutsideClick:false,showCloseButton:true});
                if (decision.isConfirmed || decision.value) await runAction(event.currentTarget, 'RESET', 'Workspace SMTP reset:');
            });
            const applySelection = (select, values) => { if (window.jQuery?.fn?.selectpicker) window.jQuery(select).selectpicker('val', values).selectpicker('refresh'); else for (const option of select.options) option.selected = values.includes(option.value); };
            document.getElementById('workspace-search-ips').addEventListener('click', async () => { const result = await showDialog({input:'textarea',inputLabel:'Search Ips',inputPlaceholder:'Type your Ips here...',inputAttributes:{'aria-label':'Type your servers here'},showCancelButton:true}); const text = result.value; if (!text) return; const queries = [...new Set(text.split('\n').map((value) => value.trim().toLowerCase()).filter(Boolean))]; const ids = [...document.querySelectorAll('#workspace-ips option')].filter((option) => queries.some((query) => option.textContent.toLowerCase().trim().includes(query))).map((option) => option.value); applySelection(document.getElementById('workspace-ips'), ids); });
            document.getElementById('workspace-search-projects').addEventListener('click', async () => { const result = await showDialog({input:'textarea',inputLabel:'Search Projects by Email',inputPlaceholder:'Type emails here, one per line...',inputAttributes:{'aria-label':'Type emails here'},showCancelButton:true}); const emails = new Set((result.value || '').split('\n').map((value) => value.trim().toLowerCase()).filter(Boolean)); if (!emails.size) return; const ids = [...document.querySelectorAll('#workspace-projects option')].filter((option) => emails.has((option.dataset.email || '').toLowerCase())).map((option) => option.value); applySelection(document.getElementById('workspace-projects'), ids); });

        })();
    </script>
@endsection
