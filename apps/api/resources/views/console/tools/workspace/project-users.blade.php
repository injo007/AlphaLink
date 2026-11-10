@extends('layouts.standalone')

@section('title', 'Allowed Mailers')

@section('content')
    <div class="command-os-entity-page p-3">
        <div class="command-os-table-card">
            <!-- Modal Header -->
            <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom">
                <div class="d-flex align-items-center">
                    <span class="command-os-report-hero-icon mr-2" style="width: 36px; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: rgba(99, 102, 241, 0.12); color: #6366f1;">
                        <i class="fas fa-users-cog"></i>
                    </span>
                    <div>
                        <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">ALLOWED MAILERS FOR {{ $projects->count() }} PROJECTS</h3>
                        <small class="text-muted">Control operator access and sending permissions across selected Google projects</small>
                    </div>
                </div>
                <span class="badge badge-purple-subtle">{{ $projects->count() }} Projects</span>
            </div>

            <!-- Info Note -->
            <div class="command-os-surface p-3 mb-3 rounded" style="background: rgba(99, 102, 241, 0.05); border: 1px solid rgba(99, 102, 241, 0.16); font-size: 0.8125rem;">
                <i class="fas fa-info-circle text-purple mr-1"></i> This assignment controls which Workspace operators can view and use these projects, their accounts, and their SMTP routes.
            </div>

            <!-- Error Banner -->
            <div id="workspace-project-users-result" class="alert alert-danger d-none mb-3" role="alert" aria-live="assertive"></div>

            <!-- Selected Projects Summary -->
            <div class="mb-3">
                <span class="command-form-label font-w700 d-block mb-1">
                    <i class="fas fa-folder mr-1 text-purple"></i> Selected projects:
                </span>
                <div class="d-flex flex-wrap" style="gap: 6px;">
                    @foreach ($projects as $project)
                        <span class="badge badge-light border text-ink p-2">
                            <i class="fab fa-google mr-1 text-muted"></i>{{ $project->name }}
                        </span>
                    @endforeach
                </div>
            </div>

            <!-- Operator Checkboxes -->
            <div class="form-group mb-4">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="command-form-label font-w700 mb-0">
                        <i class="fas fa-user-check mr-1 text-purple"></i> Allowed Mailers:
                    </label>
                    <button id="workspace-project-users-toggle" class="btn-command-action" type="button" aria-pressed="false" style="padding: 2px 10px; font-size: 0.75rem;">
                        CHECK ALL
                    </button>
                </div>
                <div class="p-3 rounded" style="background: var(--command-os-card-bg, #ffffff); border: 1px solid var(--command-os-border, rgba(99, 102, 241, 0.16)); max-height: 200px; overflow-y: auto;">
                    <div class="row">
                        @forelse ($operators as $operator)
                            @php($allowedForEveryProject = $projects->every(fn ($project) => $project->allowed_users === null || in_array((int) $operator->id, array_map('intval', $project->allowed_users ?? []), true)))
                            <div class="col-sm-12 col-md-6 mb-2">
                                <label class="command-table-checkbox-label d-inline-flex align-items-center" for="workspace-project-bulk-user-{{ $operator->id }}" style="cursor: pointer;">
                                    <input class="workspace-project-bulk-user" type="checkbox" id="workspace-project-bulk-user-{{ $operator->id }}" value="{{ $operator->id }}" @checked($allowedForEveryProject)>
                                    <span class="command-checkbox-custom-box mr-2"></span>
                                    <span class="font-w600" style="font-size: 0.8125rem;">
                                        {{ $operator->name }} <span class="text-muted font-w400">({{ $operator->email }})</span>
                                    </span>
                                </label>
                            </div>
                        @empty
                            <div class="col-12"><small class="text-muted"><i class="fas fa-info-circle mr-1"></i> There are no active operators in this workspace to assign.</small></div>
                        @endforelse
                    </div>
                </div>
            </div>

            <!-- Action Bar -->
            <div class="d-flex align-items-center justify-content-end pt-3 border-top" style="gap: 10px;">
                <button type="button" class="btn-command-action" onclick="window.parent?.closeModal ? window.parent.closeModal() : window.history.back()">CANCEL</button>
                <button id="workspace-project-users-save" class="btn-command-primary" type="button">
                    <i class="fas fa-check mr-1"></i> UPDATE ALLOWED USERS
                </button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const save = document.getElementById('workspace-project-users-save');
            const result = document.getElementById('workspace-project-users-result');
            const endpoint = @json(route('console.tools.workspace.projects.access.update', $workspace->id));
            const projects = @json($projects->pluck('id')->map(fn ($id) => (int) $id)->values());
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const checks = [...document.querySelectorAll('.workspace-project-bulk-user')];
            document.getElementById('workspace-project-users-toggle').addEventListener('click', (event) => {
                const checked = checks.some((input) => !input.checked);
                checks.forEach((input) => { input.checked = checked; });
                event.currentTarget.setAttribute('aria-pressed', String(checked));
                event.currentTarget.textContent = checked ? 'UNCHECK ALL' : 'CHECK ALL';
            });
            save.addEventListener('click', async () => {
                save.disabled = true;
                result.classList.add('d-none');
                try {
                    const response = await fetch(endpoint, {
                        method: 'PUT',
                        headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},
                        body: JSON.stringify({projects, users:checks.filter((input) => input.checked).map((input) => Number(input.value))}),
                    });
                    const body = await response.json().catch(() => ({}));
                    if (!response.ok) throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.message || 'Project access could not be updated.');
                    if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                        window.parent.App?.swalPromise ? window.parent.App.swalPromise({icon:'success',title:body.message,confirmButtonText:'close'}).then(() => window.parent.closeModal()) : window.parent.closeModal();
                    } else window.location.assign(@json(route('console.gcampaigns.projects', $workspace->id)));
                } catch (error) {
                    result.textContent = error.message;
                    result.classList.remove('d-none');
                    save.disabled = false;
                }
            });
        })();
    </script>
@endpush
