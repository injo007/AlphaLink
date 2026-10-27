@extends('layouts.dms-admin')

@section('title', 'Gmail Accounts - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">GMAIL ACCOUNTS</h3>
            <div class="block-options">
                <button class="btn btn-sm btn-secondary" type="button" id="workspace-account-refresh" title="REFRESH ACCOUNTS" aria-label="Refresh accounts"><i class="fas fa-sync" aria-hidden="true"></i></button>
                <button class="btn-block-option" type="button" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content">
            <div id="workspace-account-status" class="alert d-none" role="status" aria-live="polite"></div>
            <form id="workspace-account-form" class="form-row align-items-end border rounded p-3 mb-3">
                <div class="form-group col-md-4">
                    <label for="workspace-account-project">Project</label>
                    <select class="form-control" id="workspace-account-project" required>
                        <option value="">Select Project</option>
                        @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((string) $selectedProjectId === (string) $project->id)>{{ $project->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group col-md-6"><label for="workspace-account-emails">Accounts</label><textarea class="form-control" id="workspace-account-emails" rows="2" placeholder="One email address per line" required></textarea></div>
                <div class="form-group col-md-2"><button class="btn btn-primary btn-block" type="submit" id="workspace-account-add">ADD ACCOUNTS</button></div>
            </form>
            <div class="mb-2">
                <button class="btn btn-sm btn-outline-secondary" id="workspace-accounts-enable" type="button"><i class="fas fa-check mr-1" aria-hidden="true"></i>ENABLE SELECTED</button>
                <button class="btn btn-sm btn-outline-secondary" id="workspace-accounts-disable" type="button"><i class="fas fa-minus mr-1" aria-hidden="true"></i>DISABLE SELECTED</button>
                <button class="btn btn-sm btn-danger" id="workspace-accounts-delete" type="button"><i class="fas fa-trash mr-1" aria-hidden="true"></i>DELETE SELECTED</button>
            </div>
            <div class="table-responsive">
                <table id="workspace-account-table" class="table table-sm table-hover table-striped">
                    <thead><tr><th><input type="checkbox" id="workspace-accounts-all" aria-label="Select all accounts on this page"></th><th>#</th><th>Email</th><th>Project</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($accounts as $account)
                            <tr>
                                <td><input type="checkbox" value="{{ $account->id }}" class="workspace-account-select" aria-label="Select account {{ $account->email }}"></td>
                                <td>{{ $account->id }}</td>
                                <td>{{ $account->email }}</td>
                                <td>{{ $account->project?->name }}</td>
                                <td>{{ $account->is_active ? 'ACTIVE' : 'INACTIVE' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">No synced Workspace accounts have been added.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const base = @json(url('/app/workspaces/'.$workspace->id.'/tools/workspace'));
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const status = document.getElementById('workspace-account-status');
            const form = document.getElementById('workspace-account-form');
            const add = document.getElementById('workspace-account-add');
            const request = async (url, method = 'POST', body = {}) => {
                const response = await fetch(url, {method, headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf}, body: method === 'GET' ? undefined : JSON.stringify(body)});
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || payload.error || Object.values(payload.errors || {}).flat().join(' ') || 'Request failed.');
                return payload;
            };
            const showStatus = (message, ok = true) => { status.textContent = message; status.className = `alert ${ok ? 'alert-success' : 'alert-danger'}`; };
            const busy = (button, value) => { button.disabled = value; button.setAttribute('aria-busy', String(value)); };
            const dialog = async (options) => { const modal = window.Swal || window.swal; return modal?.fire ? modal.fire(options) : {isConfirmed: window.confirm(options.text || options.title || 'Continue?')}; };

            document.getElementById('workspace-account-refresh').addEventListener('click', () => window.location.reload());

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                busy(add, true);
                try {
                    const emails = document.getElementById('workspace-account-emails').value.split(/[\s,;]+/).filter(Boolean);
                    const result = await request(`${base}/accounts`, 'POST', {project_id:document.getElementById('workspace-account-project').value, accounts:emails});
                    showStatus(result.message || 'Accounts added.');
                    window.location.reload();
                } catch (error) {
                    showStatus(error.message, false);
                    busy(add, false);
                }
            });

            document.getElementById('workspace-accounts-all').addEventListener('change', (event) => {
                document.querySelectorAll('#workspace-account-table tbody .workspace-account-select').forEach((checkbox) => { checkbox.checked = event.target.checked; });
            });
            const updateAccounts = async (mode, button) => {
                const accounts = [...document.querySelectorAll('#workspace-account-table tbody .workspace-account-select:checked')].map((checkbox) => checkbox.value);
                if (!accounts.length) return showStatus('Select at least one account.', false);
                const decision = await dialog({icon:'question',title:'Are you sure?',text:mode === 'delete' ? 'Delete the selected Workspace accounts?' : `${mode.toUpperCase()} the selected Workspace accounts?`,showCancelButton:true,confirmButtonText:`${mode.toUpperCase()} ACCOUNTS`});
                if (mode === 'delete') {
                    if (!decision.isConfirmed && !decision.value) return;
                } else if (!decision.isConfirmed && !decision.value) {
                    return;
                }
                busy(button, true);
                try {
                    const result = await request(`${base}/accounts${mode === 'delete' ? '' : '/status'}`, mode === 'delete' ? 'DELETE' : 'PATCH', mode === 'delete' ? {accounts} : {accounts,status:mode === 'enable'});
                    showStatus(result.message || 'Accounts updated.');
                    window.location.reload();
                } catch (error) {
                    showStatus(error.message, false);
                    busy(button, false);
                }
            };
            document.getElementById('workspace-accounts-enable').addEventListener('click', (event) => updateAccounts('enable', event.currentTarget));
            document.getElementById('workspace-accounts-disable').addEventListener('click', (event) => updateAccounts('disable', event.currentTarget));
            document.getElementById('workspace-accounts-delete').addEventListener('click', (event) => updateAccounts('delete', event.currentTarget));

            if (window.jQuery?.fn?.DataTable) window.jQuery('#workspace-account-table').DataTable({pageLength:50, order:[[1,'desc']]});
        })();
    </script>
@endpush
