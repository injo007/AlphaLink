@extends('layouts.standalone')

@section('title', 'Edit Workspace Project')

@section('content')
    <div class="command-os-entity-page p-3">
        <div class="command-os-table-card">
            <!-- Modal Header -->
            <div class="d-flex align-items-center justify-content-between mb-3 pb-3 border-bottom">
                <div class="d-flex align-items-center">
                    <span class="command-os-report-hero-icon mr-2" style="width: 36px; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: rgba(99, 102, 241, 0.12); color: #6366f1;">
                        <i class="fas fa-pencil-alt"></i>
                    </span>
                    <div>
                        <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">EDIT PROJECT</h3>
                        <small class="text-muted">Update service account credentials and operator permissions for this project</small>
                    </div>
                </div>
                <span class="badge badge-purple-subtle">#{{ $project->id }}</span>
            </div>

            <!-- Error Banner -->
            <div id="workspace-project-edit-result" class="alert alert-danger d-none mb-3" role="alert" aria-live="assertive"></div>

            <!-- Form -->
            <div class="form-group mb-3">
                <label class="command-form-label font-w700" for="workspace-project-edit-name">
                    <i class="fas fa-heading mr-1 text-purple"></i> Project Name:
                </label>
                <input id="workspace-project-edit-name" class="form-control" type="text" maxlength="255" value="{{ $project->name }}" required>
            </div>

            <div class="form-group mb-3">
                <label class="command-form-label font-w700" for="workspace-project-edit-json">
                    <i class="fas fa-key mr-1 text-purple"></i> Service account JSON:
                </label>
                <textarea id="workspace-project-edit-json" class="form-control font-monospace" rows="8" spellcheck="false" required style="font-size: 0.8125rem;">{{ $project->service_account_json }}</textarea>
                <small class="form-text text-muted mt-1"><i class="fas fa-lock mr-1"></i> The credential is stored encrypted. Operators assigned to this project can edit its settings.</small>
            </div>

            <div class="form-group mb-4">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <label class="command-form-label font-w700 mb-0">
                        <i class="fas fa-users mr-1 text-purple"></i> Allowed Mailers:
                    </label>
                    <button id="workspace-project-edit-toggle" class="btn-command-action" type="button" aria-pressed="false" style="padding: 2px 10px; font-size: 0.75rem;">
                        CHECK ALL
                    </button>
                </div>
                @if ($project->allowed_users === null)
                    <div class="command-os-surface p-2 mb-2 rounded" style="background: rgba(99, 102, 241, 0.05); border: 1px solid rgba(99, 102, 241, 0.16); font-size: 0.75rem;">
                        <i class="fas fa-info-circle text-purple mr-1"></i> This legacy project has no saved assignment list. Existing workspace operators currently retain access; saving below makes the assignment explicit.
                    </div>
                @endif
                <div class="p-3 rounded" style="background: var(--command-os-card-bg, #ffffff); border: 1px solid var(--command-os-border, rgba(99, 102, 241, 0.16)); max-height: 160px; overflow-y: auto;">
                    <div class="row">
                        @forelse ($operators->chunk(2) as $chunk)
                            <div class="col-sm-12 col-md-6">
                                @foreach ($chunk as $operator)
                                    <div class="mb-2">
                                        <label class="command-table-checkbox-label d-inline-flex align-items-center" for="workspace-project-edit-user-{{ $operator->id }}" style="cursor: pointer;">
                                            <input class="workspace-project-edit-user" type="checkbox" id="workspace-project-edit-user-{{ $operator->id }}" value="{{ $operator->id }}" @checked($project->allowed_users === null || in_array((int) $operator->id, array_map('intval', $project->allowed_users ?? []), true))>
                                            <span class="command-checkbox-custom-box mr-2"></span>
                                            <span class="font-w600" style="font-size: 0.8125rem;">
                                                {{ $operator->name }} <span class="text-muted font-w400">({{ $operator->email }})</span>
                                            </span>
                                        </label>
                                    </div>
                                @endforeach
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
                <button id="workspace-project-edit-save" class="btn-command-primary" type="button">
                    <i class="fas fa-check mr-1"></i> UPDATE PROJECT
                </button>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const save = document.getElementById('workspace-project-edit-save');
            const result = document.getElementById('workspace-project-edit-result');
            const endpoint = @json(route('console.tools.workspace.projects.update', [$workspace->id, $project->id]));
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const checks = [...document.querySelectorAll('.workspace-project-edit-user')];
            document.getElementById('workspace-project-edit-toggle').addEventListener('click', (event) => {
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
                        body: JSON.stringify({
                            name: document.getElementById('workspace-project-edit-name').value.trim(),
                            service_account_json: document.getElementById('workspace-project-edit-json').value,
                            users: checks.filter((input) => input.checked).map((input) => Number(input.value)),
                        }),
                    });
                    const body = await response.json().catch(() => ({}));
                    if (!response.ok) throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.message || 'Project could not be updated.');
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
