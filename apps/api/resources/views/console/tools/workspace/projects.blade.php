@extends('layouts.admin-shell')

@section('title', 'GSend Projects')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Header -->
        <header class="command-os-report-hero" aria-labelledby="gsend-projects-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-project-diagram"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-project-diagram"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">CAMPAIGN STUDIO // GMAIL PROJECTS</p>
                <h1 id="gsend-projects-title">GSEND PROJECTS</h1>
                <p>Configure Google Cloud service projects, API client access, authorization keys, and user permissions.</p>
            </div>
            <div class="command-os-hero-actions">
                <a class="btn-command-primary mr-2" href="{{ route('clean.console.tools.workspace.projects.create', $workspace->id) }}" data-workspace-project-create aria-label="Add Gmail project">
                    <i class="fas fa-plus mr-1" aria-hidden="true"></i>ADD PROJECT
                </a>
                <button class="command-os-btn-option" type="button" id="workspace-project-refresh" title="Refresh projects" aria-label="Refresh projects">
                    <i class="fas fa-sync-alt" aria-hidden="true"></i>
                </button>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand" aria-hidden="true"></i>
                </button>
            </div>
        </header>

        <!-- 2. KPI Overview Cards -->
        <section class="command-os-all-stats-overview" aria-label="Projects Overview">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-project-diagram"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Total Projects</span>
                    <strong>{{ $projects->count() }}</strong>
                    <small>Configured clusters</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-emerald" aria-hidden="true"><i class="fas fa-check-circle"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Active Projects</span>
                    <strong>{{ $projects->where('is_active', true)->count() }}</strong>
                    <small>Enabled integration</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-pause-circle"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Disabled</span>
                    <strong>{{ $projects->where('is_active', false)->count() }}</strong>
                    <small>Paused integration</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-blue" aria-hidden="true"><i class="fas fa-at"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Total Accounts</span>
                    <strong>{{ $projects->sum('accounts_count') }}</strong>
                    <small>Synced senders</small>
                </span>
            </article>
        </section>

        <!-- 3. Status Alert -->
        <div id="workspace-project-status" class="alert d-none" role="status" aria-live="polite"></div>

        <div class="d-none" aria-hidden="true">
            <button id="workspace-projects-status" type="button">ENABLE / DISABLE PROJECTS</button>
            @if ($canManageProjects)
                <button id="workspace-projects-users" type="button">ALLOWED USERS</button>
                <button id="workspace-projects-delete" type="button">DELETE SELECTED</button>
            @endif
            <button id="workspace-project-refresh" type="button">REFRESH PROJECTS</button>
            <button id="workspace-project-add" type="button">ADD PROJECT</button>
            <span>SELECT NONE</span>
            <span>SELECT ALL</span>
            <span>ADD NEW PROJECT</span>
        </div>

        <!-- 4. Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-3" style="gap: 12px;">
                <div class="d-flex align-items-center">
                    <i class="fas fa-project-diagram text-purple font-size-lg mr-2"></i>
                    <h2 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">WORKSPACE PROJECTS DIRECTORY</h2>
                    <span class="badge badge-purple ml-2">{{ $projects->count() }}</span>
                </div>
                <div class="d-flex flex-wrap align-items-center" style="gap: 8px;">
                    <button class="btn-command-action" type="button" onclick="document.getElementById('workspace-projects-status').click()">
                        <i class="fas fa-toggle-on mr-1"></i>STATUS TOGGLE
                    </button>
                    @if ($canManageProjects)
                        <button class="btn-command-action" type="button" onclick="document.getElementById('workspace-projects-users').click()">
                            <i class="fas fa-users mr-1"></i>PERMISSIONS
                        </button>
                        <button class="btn-command-danger" type="button" onclick="document.getElementById('workspace-projects-delete').click()">
                            <i class="fas fa-trash-alt mr-1"></i>DELETE SELECTED
                        </button>
                    @endif
                </div>
            </div>

            <div class="table-responsive">
                <table id="workspace-project-table" class="table table-sm table-hover table-striped">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <label class="command-table-checkbox-label">
                                    <input type="checkbox" id="workspace-projects-all" aria-label="Select all projects on this page">
                                    <span class="command-checkbox-custom-box"></span>
                                </label>
                            </th>
                            <th style="width: 60px;">#</th>
                            <th style="width: 120px;">TYPE</th>
                            <th>NAME</th>
                            <th style="width: 150px;">TOTAL ACCOUNTS</th>
                            <th style="width: 160px;">CREATED AT</th>
                            <th class="text-right" style="width: 150px;">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($projects as $project)
                            <tr>
                                <td>
                                    <label class="command-table-checkbox-label">
                                        <input type="checkbox" value="{{ $project->id }}" class="workspace-project-select" aria-label="Select project {{ $project->name }}">
                                        <span class="command-checkbox-custom-box"></span>
                                    </label>
                                </td>
                                <td><span class="font-w700 text-muted">#{{ $project->id }}</span></td>
                                <td><span class="badge badge-light border text-muted">Workspace</span></td>
                                <td>
                                    <div class="font-w600 text-ink">{{ $project->name }}</div>
                                    @if ($project->sync_error)
                                        <div class="small text-danger mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>{{ $project->sync_error }}</div>
                                    @endif
                                </td>
                                <td>
                                    <a href="{{ route('console.gcampaigns.accounts', [$workspace->id, 'project' => $project->id]) }}" class="badge badge-purple-subtle" title="View accounts in this project">
                                        <i class="fas fa-at mr-1 text-purple"></i>{{ $project->accounts_count }} Accounts
                                    </a>
                                </td>
                                <td>
                                    <span class="text-muted small"><i class="far fa-clock mr-1"></i>{{ $project->created_at?->format('Y-m-d H:i') ?? '—' }}</span>
                                </td>
                                <td class="text-right text-nowrap">
                                    <div class="command-action-btn-group justify-content-end">
                                        <button class="command-action-btn action-info" type="button" data-project-sync="{{ $project->id }}" title="SYNC PROJECT{{ $project->synced_at ? ' — Last synced '.$project->synced_at->format('Y-m-d H:i:s') : ' — Not synced yet' }}" aria-label="Sync {{ $project->name }}">
                                            <i class="fas fa-sync-alt" aria-hidden="true"></i>
                                        </button>
                                        <button class="command-action-btn {{ $project->is_active ? 'action-warning' : 'action-success' }}" type="button" data-project-status="{{ $project->id }}" title="{{ $project->is_active ? 'DISABLE' : 'ENABLE' }} PROJECT" aria-label="{{ $project->is_active ? 'Disable' : 'Enable' }} {{ $project->name }}">
                                            <i class="fas {{ $project->is_active ? 'fa-pause' : 'fa-play' }}" aria-hidden="true"></i>
                                        </button>
                                        <button class="command-action-btn action-primary" type="button" data-project-edit="{{ $project->id }}" title="EDIT PROJECT" aria-label="Edit {{ $project->name }}">
                                            <i class="fas fa-pencil-alt" aria-hidden="true"></i>
                                        </button>
                                        @if ($canManageProjects)
                                            <button class="command-action-btn action-danger" type="button" data-project-delete="{{ $project->id }}" title="DELETE PROJECT" aria-label="Delete {{ $project->name }}">
                                                <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="fas fa-project-diagram font-size-h3 d-block mb-2 text-muted"></i>
                                    No Workspace projects have been added. <a href="{{ route('clean.console.tools.workspace.projects.create', $workspace->id) }}" data-workspace-project-create>Add the first project</a>.
                                </td>
                            </tr>
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

            document.getElementById('workspace-project-add')?.addEventListener('click', () => openFrame(`${base}/projects/create`));
            document.querySelectorAll('[data-workspace-project-create]').forEach((link) => {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                    openFrame(link.href);
                });
            });

            document.getElementById('workspace-project-refresh')?.addEventListener('click', () => window.location.reload());

            document.getElementById('workspace-projects-all')?.addEventListener('change', (event) => {
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
            document.getElementById('workspace-projects-status')?.addEventListener('click', async (event) => {
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
                window.jQuery('#workspace-project-table').DataTable({
                    pageLength: 50,
                    order: [[1, 'desc']],
                    language: {
                        search: "_INPUT_",
                        searchPlaceholder: "Search projects...",
                        lengthMenu: "Show _MENU_ rows"
                    }
                });
            }
        })();
    </script>
@endpush
