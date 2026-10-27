@extends('layouts.dms-admin')

@section('title', 'Gmail Projects - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">GMAIL PROJECTS</h3>
            <div class="block-options">
                <button class="btn btn-sm btn-secondary" type="button" id="workspace-project-refresh" title="REFRESH PROJECTS" aria-label="Refresh projects"><i class="fas fa-sync" aria-hidden="true"></i></button>
                <button class="btn btn-sm btn-primary" type="button" data-toggle="collapse" data-target="#workspace-project-create" aria-expanded="false" aria-controls="workspace-project-create">
                    <i class="fa fa-plus mr-1" aria-hidden="true"></i> ADD PROJECT
                </button>
                <button class="btn-block-option" type="button" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content">
            <div id="workspace-project-status" class="alert d-none" role="status" aria-live="polite"></div>
            <div class="collapse mb-4" id="workspace-project-create">
                <form id="workspace-project-form" class="border rounded p-3">
                    <h4 class="font-size-base mb-3">ADD WORKSPACE PROJECT</h4>
                    <div class="form-group">
                        <label for="workspace-admin-email">Delegated admin email / project name</label>
                        <input class="form-control" id="workspace-admin-email" type="email" maxlength="255" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="workspace-service-account">Service account JSON</label>
                        <textarea class="form-control" id="workspace-service-account" rows="8" spellcheck="false" autocomplete="off" required placeholder="Paste the Google service-account JSON with Workspace domain-wide delegation enabled"></textarea>
                        <small class="form-text text-muted">Stored encrypted. Saving reads accounts from Google Admin Directory. The service account key is never shown again.</small>
                    </div>
                    <button class="btn btn-primary" type="submit" id="workspace-project-save">SAVE &amp; SYNC</button>
                </form>
            </div>
            <div class="table-responsive">
                <table id="workspace-project-table" class="table table-sm table-hover table-striped">
                    <thead><tr><th>#</th><th>Project / delegated admin</th><th>Active accounts</th><th>Status</th><th>Last sync</th><th>Created</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($projects as $project)
                            <tr>
                                <td>{{ $project->id }}</td>
                                <td>{{ $project->name }}@if ($project->sync_error)<div class="small text-danger">{{ $project->sync_error }}</div>@endif</td>
                                <td><a href="{{ route('console.gcampaigns.accounts', [$workspace->id, 'project' => $project->id]) }}">{{ $project->accounts_count }}</a></td>
                                <td>{{ $project->is_active ? 'ACTIVE' : 'INACTIVE' }}</td>
                                <td>{{ $project->synced_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                                <td>{{ $project->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                                <td class="text-nowrap">
                                    <button class="btn btn-sm btn-info" type="button" data-project-sync="{{ $project->id }}" title="SYNC PROJECT" aria-label="Sync {{ $project->name }}"><i class="fas fa-sync" aria-hidden="true"></i></button>
                                    <button class="btn btn-sm {{ $project->is_active ? 'btn-warning' : 'btn-success' }}" type="button" data-project-status="{{ $project->id }}" title="{{ $project->is_active ? 'DISABLE' : 'ENABLE' }} PROJECT" aria-label="{{ $project->is_active ? 'Disable' : 'Enable' }} {{ $project->name }}"><i class="fas {{ $project->is_active ? 'fa-minus' : 'fa-check' }}" aria-hidden="true"></i></button>
                                    <button class="btn btn-sm btn-danger" type="button" data-project-delete="{{ $project->id }}" title="DELETE PROJECT" aria-label="Delete {{ $project->name }}"><i class="fas fa-trash" aria-hidden="true"></i></button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-muted">No Workspace projects have been added.</td></tr>
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
            const status = document.getElementById('workspace-project-status');
            const form = document.getElementById('workspace-project-form');
            const save = document.getElementById('workspace-project-save');
            const request = async (url, method = 'POST', body = {}) => {
                const response = await fetch(url, {method, headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf}, body: method === 'GET' ? undefined : JSON.stringify(body)});
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || payload.error || Object.values(payload.errors || {}).flat().join(' ') || 'Request failed.');
                return payload;
            };
            const showStatus = (message, ok = true) => { status.textContent = message; status.className = `alert ${ok ? 'alert-success' : 'alert-danger'}`; };
            const busy = (button, value) => { button.disabled = value; button.setAttribute('aria-busy', String(value)); };
            const dialog = async (options) => { const modal = window.Swal || window.swal; return modal?.fire ? modal.fire(options) : {isConfirmed: window.confirm(options.text || options.title || 'Continue?')}; };

            document.getElementById('workspace-project-refresh').addEventListener('click', () => window.location.reload());

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                busy(save, true);
                try {
                    const result = await request(`${base}/projects`, 'POST', {
                        name: document.getElementById('workspace-admin-email').value.trim(),
                        service_account_json: document.getElementById('workspace-service-account').value,
                    });
                    showStatus(`${result.message} ${result.accounts ?? 0} account(s) synced.`);
                    window.location.reload();
                } catch (error) {
                    showStatus(error.message, false);
                    busy(save, false);
                }
            });

            document.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-project-sync], [data-project-status], [data-project-delete]');
                if (!button) return;
                const id = encodeURIComponent(button.dataset.projectSync || button.dataset.projectStatus || button.dataset.projectDelete);
                const isDelete = button.hasAttribute('data-project-delete');
                if (isDelete) {
                    const decision = await dialog({icon:'question',title:'Are you sure?',text:'Delete this project and its synced accounts?',showCancelButton:true,confirmButtonText:'DELETE PROJECT'});
                    if (!decision.isConfirmed && !decision.value) return;
                }
                busy(button, true);
                try {
                    const endpoint = `${base}/projects/${id}`;
                    const result = button.hasAttribute('data-project-sync')
                        ? await request(`${endpoint}/sync`)
                        : button.hasAttribute('data-project-status')
                            ? await request(`${endpoint}/status`, 'PATCH')
                            : await request(endpoint, 'DELETE');
                    showStatus(result.message || 'Project updated.');
                    window.location.reload();
                } catch (error) {
                    showStatus(error.message, false);
                    busy(button, false);
                }
            });

            if (window.jQuery?.fn?.DataTable) window.jQuery('#workspace-project-table').DataTable({pageLength:50, order:[[0,'desc']]});
        })();
    </script>
@endpush
