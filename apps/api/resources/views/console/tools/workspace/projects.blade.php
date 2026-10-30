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
            <div class="mb-2" role="group" aria-label="Selected project actions">
                <button class="btn btn-sm btn-outline-secondary" id="workspace-projects-status" type="button">ENABLE / DISABLE PROJECTS</button>
                @if ($canManageProjects)
                    <button class="btn btn-sm btn-info" id="workspace-projects-users" type="button">ALLOWED MAILERS</button>
                    <button class="btn btn-sm btn-danger" id="workspace-projects-delete" type="button">DELETE SELECTED</button>
                @endif
            </div>
            <div class="collapse mb-4" id="workspace-project-create">
                <div class="custom-control custom-switch mb-3">
                    <input class="custom-control-input" id="workspace-project-s3-toggle" type="checkbox" aria-controls="workspace-project-s3-panel">
                    <label class="custom-control-label" for="workspace-project-s3-toggle">S3</label>
                </div>
                <div class="border rounded p-3 mb-3 d-none" id="workspace-project-s3-panel">
                    <h4 class="font-size-base mb-3">SYNC PROJECTS FROM S3</h4>
                    <div class="form-group">
                        <label for="workspace-project-s3-link">Public S3 folder URL</label>
                        <input class="form-control" id="workspace-project-s3-link" type="url" maxlength="2048" autocomplete="url" value="{{ $s3Link }}" placeholder="https://bucket.s3.region.amazonaws.com/project-folder" required>
                        <small class="form-text text-muted">Each non-empty file becomes a Workspace project named from its filename. New or changed projects queue an account sync.</small>
                    </div>
                    <button class="btn btn-primary" type="button" id="workspace-project-s3-sync">SYNC</button>
                </div>
                <form id="workspace-project-form" class="border rounded p-3">
                    <h4 class="font-size-base mb-3">ADD WORKSPACE PROJECT</h4>
                    <div class="form-group">
                        <label for="workspace-project-name">Project name</label>
                        <input class="form-control" id="workspace-project-name" type="text" maxlength="255" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label for="workspace-service-account">Service account JSON</label>
                        <textarea class="form-control" id="workspace-service-account" rows="8" spellcheck="false" autocomplete="off" required placeholder="Paste the Google service-account JSON with Workspace domain-wide delegation enabled"></textarea>
                        <small class="form-text text-muted">Stored encrypted. Saving reads accounts from Google Admin Directory.</small>
                    </div>
                    <div class="form-group">
                        <label>Allowed Mailers</label>
                        @forelse ($operators as $operator)
                            <div class="custom-control custom-checkbox mb-1">
                                <input class="custom-control-input workspace-project-user" type="checkbox" id="workspace-project-user-{{ $operator->id }}" value="{{ $operator->id }}">
                                <label class="custom-control-label" for="workspace-project-user-{{ $operator->id }}">{{ $operator->name }} | {{ $operator->email }}</label>
                            </div>
                        @empty
                            <small class="form-text text-muted">There are no active operators in this workspace to assign.</small>
                        @endforelse
                    </div>
                    <button class="btn btn-primary" type="submit" id="workspace-project-save">SAVE</button>
                </form>
            </div>
            <div class="table-responsive">
                <table id="workspace-project-table" class="table table-sm table-hover table-striped">
                    <thead><tr><th><input type="checkbox" id="workspace-projects-all" aria-label="Select all projects on this page"></th><th>#</th><th>type</th><th>name</th><th>total accounts</th><th>created at</th><th>Allowed Mailers</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($projects as $project)
                            <tr>
                                <td><input type="checkbox" value="{{ $project->id }}" class="workspace-project-select" aria-label="Select project {{ $project->name }}"></td>
                                <td>{{ $project->id }}</td>
                                <td>Workspace</td>
                                <td>{{ $project->name }}@if ($project->sync_error)<div class="small text-danger">{{ $project->sync_error }}</div>@endif</td>
                                <td><a href="{{ route('console.gcampaigns.accounts', [$workspace->id, 'project' => $project->id]) }}">{{ $project->accounts_count }}</a></td>
                                <td>{{ $project->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                                <td>{{ $project->allowed_users === null ? 'Legacy access' : count($project->allowed_users) }}</td>
                                <td class="text-nowrap">
                                    <button class="btn btn-sm btn-info" type="button" data-project-sync="{{ $project->id }}" title="SYNC PROJECT{{ $project->synced_at ? ' — Last synced '.$project->synced_at->format('Y-m-d H:i:s') : ' — Not synced yet' }}" aria-label="Sync {{ $project->name }}"><i class="fas fa-sync" aria-hidden="true"></i></button>
                                    <button class="btn btn-sm {{ $project->is_active ? 'btn-warning' : 'btn-success' }}" type="button" data-project-status="{{ $project->id }}" title="{{ $project->is_active ? 'DISABLE' : 'ENABLE' }} PROJECT" aria-label="{{ $project->is_active ? 'Disable' : 'Enable' }} {{ $project->name }}"><i class="fas {{ $project->is_active ? 'fa-minus' : 'fa-check' }}" aria-hidden="true"></i></button>
                                    <button class="btn btn-sm btn-primary" type="button" data-project-edit="{{ $project->id }}" title="EDIT PROJECT" aria-label="Edit {{ $project->name }}"><i class="fas fa-pencil-alt" aria-hidden="true"></i></button>
                                    @if ($canManageProjects)
                                        <button class="btn btn-sm btn-danger" type="button" data-project-delete="{{ $project->id }}" title="DELETE PROJECT" aria-label="Delete {{ $project->name }}"><i class="fas fa-trash" aria-hidden="true"></i></button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-muted">No Workspace projects have been added.</td></tr>
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
            const s3Toggle = document.getElementById('workspace-project-s3-toggle');
            const s3Panel = document.getElementById('workspace-project-s3-panel');
            const s3Sync = document.getElementById('workspace-project-s3-sync');
            let frameModal = null;
            const request = async (url, method = 'POST', body = {}) => {
                const response = await fetch(url, {method, headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf}, body: method === 'GET' ? undefined : JSON.stringify(body)});
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(payload.message || payload.error || Object.values(payload.errors || {}).flat().join(' ') || 'Request failed.');
                return payload;
            };
            const showStatus = (message, ok = true) => { status.textContent = message; status.className = `alert ${ok ? 'alert-success' : 'alert-danger'}`; };
            const busy = (button, value) => { button.disabled = value; button.setAttribute('aria-busy', String(value)); };
            const dialog = async (options) => { const modal = window.Swal || window.swal; return modal?.fire ? modal.fire(options) : {isConfirmed: window.confirm(options.text || options.title || 'Continue?')}; };

            const openFrame = (url) => {
                if (!window.bootbox?.dialog) return window.location.assign(url);
                frameModal = window.bootbox.dialog({scrollable:true,closeButton:true,size:'lg',message:`<iframe src="${url}" width="100%" scrolling="no" frameborder="0"></iframe>`,onEscape:true,centerVertical:true})
                    .on('shown.bs.modal', function () { if (window.iFrameResize) window.iFrameResize({}, 'iframe'); })
                    .on('hidden.bs.modal', function () { window.jQuery?.('iframe').remove(); });
            };
            window.closeModal = function () { frameModal?.modal('hide'); window.location.reload(); };

            s3Toggle.addEventListener('change', () => {
                s3Panel.classList.toggle('d-none', !s3Toggle.checked);
                form.classList.toggle('d-none', s3Toggle.checked);
            });

            document.getElementById('workspace-project-refresh').addEventListener('click', () => window.location.reload());

            document.getElementById('workspace-projects-all').addEventListener('change', (event) => {
                document.querySelectorAll('#workspace-project-table tbody .workspace-project-select').forEach((checkbox) => { checkbox.checked = event.target.checked; });
            });

            const selectedProjectIds = () => [...document.querySelectorAll('#workspace-project-table tbody .workspace-project-select:checked')].map((checkbox) => Number(checkbox.value));
            const updateSelectedProjects = async (statusValue, button) => {
                const ids = selectedProjectIds();
                if (!ids.length) return showStatus('Select at least one project.', false);
                busy(button, true);
                try {
                    const result = await request(`${base}/projects/status`, 'PATCH', {ids, status: statusValue});
                    showStatus(result.message || 'Selected project status updated.');
                    window.location.reload();
                } catch (error) {
                    showStatus(error.message, false);
                    busy(button, false);
                }
            };
            document.getElementById('workspace-projects-status').addEventListener('click', async (event) => {
                if (!selectedProjectIds().length) return showStatus('Select at least one project.', false);
                const modal = window.Swal || window.swal;
                let statusValue = null;
                if (modal?.fire) {
                    const choice = await modal.fire({icon:'question',title:'Enable or disable selected projects?',showDenyButton:true,showCancelButton:true,confirmButtonText:'ENABLE',denyButtonText:'DISABLE',cancelButtonText:'CANCEL'});
                    if (choice.isConfirmed) statusValue = true;
                    if (choice.isDenied) statusValue = false;
                } else if (window.confirm('Enable the selected projects?')) {
                    statusValue = true;
                } else if (window.confirm('Disable the selected projects?')) {
                    statusValue = false;
                }
                if (statusValue !== null) await updateSelectedProjects(statusValue, event.currentTarget);
            });
            document.getElementById('workspace-projects-users')?.addEventListener('click', () => {
                const ids = selectedProjectIds();
                if (!ids.length) return showStatus('Select at least one project.', false);
                const query = new URLSearchParams();
                ids.forEach((id) => query.append('ids[]', String(id)));
                openFrame(`${base}/projects/access?${query.toString()}`);
            });
            document.getElementById('workspace-projects-delete')?.addEventListener('click', async (event) => {
                const ids = selectedProjectIds();
                if (!ids.length) return showStatus('Select at least one project.', false);
                const decision = await dialog({icon:'question',title:'Are you sure?',text:'Delete the selected Workspace projects and their synced accounts?',showCancelButton:true,confirmButtonText:'DELETE PROJECTS'});
                if (!decision.isConfirmed && !decision.value) return;
                busy(event.currentTarget, true);
                try {
                    const result = await request(`${base}/projects`, 'DELETE', {ids});
                    showStatus(result.message || 'Selected projects deleted.');
                    window.location.reload();
                } catch (error) {
                    showStatus(error.message, false);
                    busy(event.currentTarget, false);
                }
            });

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                busy(save, true);
                try {
                    const result = await request(`${base}/projects`, 'POST', {
                        name: document.getElementById('workspace-project-name').value.trim(),
                        service_account_json: document.getElementById('workspace-service-account').value,
                        users: [...document.querySelectorAll('.workspace-project-user:checked')].map((checkbox) => Number(checkbox.value)),
                    });
                    showStatus(result.message);
                    window.setTimeout(() => window.location.reload(), 900);
                } catch (error) {
                    showStatus(error.message, false);
                    busy(save, false);
                }
            });

            s3Sync.addEventListener('click', async () => {
                const input = document.getElementById('workspace-project-s3-link');
                if (!input.reportValidity()) return;
                busy(s3Sync, true);
                try {
                    const result = await request(`${base}/projects/sync-s3`, 'POST', {link: input.value.trim()});
                    showStatus(`${result.message}. ${result.unchanged ?? 0} unchanged.`);
                    window.setTimeout(() => window.location.reload(), 1400);
                } catch (error) {
                    showStatus(error.message, false);
                    busy(s3Sync, false);
                }
            });

            document.addEventListener('click', async (event) => {
                const button = event.target.closest('[data-project-sync], [data-project-status], [data-project-delete], [data-project-edit]');
                if (!button) return;
                const id = encodeURIComponent(button.dataset.projectSync || button.dataset.projectStatus || button.dataset.projectDelete || button.dataset.projectEdit);
                if (button.hasAttribute('data-project-edit')) return openFrame(`${base}/projects/${id}/edit`);
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

            if (window.jQuery?.fn?.DataTable) window.jQuery('#workspace-project-table').DataTable({pageLength:50, order:[[1,'desc']]});
        })();
    </script>
@endpush
