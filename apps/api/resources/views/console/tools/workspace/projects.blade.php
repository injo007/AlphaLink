@extends('layouts.dms-admin')

@section('title', 'Gmail Projects - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">GMAIL PROJECTS</h3>
            <div class="block-options d-flex align-items-center">
                <a class="btn btn-sm btn-alt-primary mr-2" href="{{ route('clean.console.tools.workspace.projects.create', $workspace->id) }}" data-workspace-project-create aria-label="Add Gmail project">
                    <i class="fas fa-plus mr-1" aria-hidden="true"></i>ADD PROJECT
                </a>
                <button class="btn-block-option" type="button" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen"></button>
            </div>
        </div>
        <div class="block-content">
            <div id="workspace-project-status" class="alert d-none" role="status" aria-live="polite"></div>
            <div class="d-none" aria-hidden="true">
                <button id="workspace-projects-status" type="button">ENABLE / DISABLE PROJECTS</button>
                @if ($canManageProjects)
                    <button id="workspace-projects-users" type="button">ALLOWED USERS</button>
                    <button id="workspace-projects-delete" type="button">DELETE SELECTED</button>
                @endif
                <button id="workspace-project-refresh" type="button">REFRESH PROJECTS</button>
                <button id="workspace-project-add" type="button">ADD PROJECT</button>
            </div>
            <div class="table-responsive">
                <table id="workspace-project-table" class="table table-sm table-hover table-striped">
                    <thead><tr><th><input type="checkbox" id="workspace-projects-all" aria-label="Select all projects on this page"></th><th>#</th><th>TYPE</th><th>NAME</th><th>TOTAL ACCOUNTS</th><th>CREATED AT</th><th>ACTIONS</th></tr></thead>
                    <tbody>
                        @forelse ($projects as $project)
                            <tr>
                                <td><input type="checkbox" value="{{ $project->id }}" class="workspace-project-select" aria-label="Select project {{ $project->name }}"></td>
                                <td>{{ $project->id }}</td>
                                <td>Workspace</td>
                                <td>{{ $project->name }}@if ($project->sync_error)<div class="small text-danger">{{ $project->sync_error }}</div>@endif</td>
                                <td><a href="{{ route('console.gcampaigns.accounts', [$workspace->id, 'project' => $project->id]) }}">{{ $project->accounts_count }}</a></td>
                                <td>{{ $project->created_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
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
                            <tr><td colspan="7" class="text-muted">No Workspace projects have been added. <a href="{{ route('clean.console.tools.workspace.projects.create', $workspace->id) }}" data-workspace-project-create>Add the first project</a>.</td></tr>
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

            document.getElementById('workspace-project-add').addEventListener('click', () => openFrame(`${base}/projects/create`));
            document.querySelectorAll('[data-workspace-project-create]').forEach((link) => {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    openFrame(link.href);
                });
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

            if (window.jQuery?.fn?.DataTable) {
                const grid = window.jQuery('#workspace-project-table').DataTable({
                    dom:'<"dms-table-tools"B>frtip',
                    pageLength:50,
                    order:[[1,'desc']],
                    buttons:[
                        {extend:'pageLength'},
                        {text:'<i class="far fa-square"></i>',titleAttr:'SELECT NONE',action:(event,table)=>{table.rows({page:'current'}).nodes().to$().find('.workspace-project-select').prop('checked',false);document.getElementById('workspace-projects-all').checked=false;}},
                        {text:'<i class="far fa-check-square"></i>',titleAttr:'SELECT ALL',action:(event,table)=>{table.rows({page:'current'}).nodes().to$().find('.workspace-project-select').prop('checked',true);document.getElementById('workspace-projects-all').checked=true;}},
                        {text:'<i class="fa fa-plus"></i>',titleAttr:'ADD NEW PROJECT',action:()=>document.getElementById('workspace-project-add').click()},
                        {text:'<i class="fas fa-check"></i><i class="fas fa-times"></i>',titleAttr:'ENABLE / DISABLE PROJECTS',action:()=>document.getElementById('workspace-projects-status').click()},
                        @if ($canManageProjects)
                            {text:'<i class="fas fa-users"></i>',titleAttr:'ALLOWED USERS',action:()=>document.getElementById('workspace-projects-users').click()},
                            {text:'<i class="fas fa-trash"></i>',titleAttr:'DELETE PROJECTS',action:()=>document.getElementById('workspace-projects-delete').click()},
                        @endif
                        {text:'<i class="fas fa-sync"></i>',titleAttr:'REFRESH PROJECTS',action:()=>document.getElementById('workspace-project-refresh').click()},
                    ],
                });
            }
        })();
    </script>
@endpush
