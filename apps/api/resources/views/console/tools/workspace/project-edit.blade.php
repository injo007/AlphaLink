@extends('layouts.dms-reference')

@section('title', 'Edit Workspace Project - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default"><h3 class="block-title">EDIT PROJECT</h3></div>
        <div class="block-content">
            <div class="row justify-content-center py-2"><div class="col-sm-10">
                <div id="workspace-project-edit-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
                <div class="form-group">
                    <label for="workspace-project-edit-name">Name:</label>
                    <input id="workspace-project-edit-name" class="form-control" type="text" maxlength="255" value="{{ $project->name }}" required>
                </div>
                <div class="form-group">
                    <label for="workspace-project-edit-json">Service account JSON:</label>
                    <textarea id="workspace-project-edit-json" class="form-control" rows="10" spellcheck="false" required>{{ $project->service_account_json }}</textarea>
                    <small class="form-text text-muted">The credential is stored encrypted. DMS permits operators assigned to this project to edit its settings.</small>
                </div>
                <div class="form-group">
                    <label>Allowed Mailers: <button id="workspace-project-edit-toggle" class="btn btn-link btn-sm" type="button" aria-pressed="false">CHECK ALL</button></label>
                    @if ($project->allowed_users === null)
                        <div class="alert alert-info">This legacy project has no saved assignment list. Existing workspace operators currently retain access; saving below makes the assignment explicit.</div>
                    @endif
                    @forelse ($operators as $operator)
                        <div class="custom-control custom-checkbox mb-1">
                            <input class="custom-control-input workspace-project-edit-user" type="checkbox" id="workspace-project-edit-user-{{ $operator->id }}" value="{{ $operator->id }}" @checked($project->allowed_users === null || in_array((int) $operator->id, array_map('intval', $project->allowed_users ?? []), true))>
                            <label class="custom-control-label" for="workspace-project-edit-user-{{ $operator->id }}">{{ $operator->name }} | {{ $operator->email }}</label>
                        </div>
                    @empty
                        <p class="text-muted">There are no active operators in this workspace to assign.</p>
                    @endforelse
                </div>
                <button id="workspace-project-edit-save" class="btn btn-block btn-info" type="button">UPDATE PROJECT</button>
            </div></div>
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
