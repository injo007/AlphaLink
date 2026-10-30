@extends('layouts.dms-reference')

@section('title', 'Allowed Mailers - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default"><h3 class="block-title">ALLOWED MAILERS FOR {{ $projects->count() }} PROJECTS</h3></div>
        <div class="block-content">
            <div class="row justify-content-center py-2"><div class="col-sm-10">
                <div class="alert alert-info" role="status">This assignment controls which Workspace operators can view and use these projects, their accounts, and their SMTP routes.</div>
                <div id="workspace-project-users-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
                <p class="mb-2">Selected projects: {{ $projects->pluck('name')->join(', ') }}</p>
                <div class="form-group">
                    <label>Allowed Mailers: <button id="workspace-project-users-toggle" class="btn btn-link btn-sm" type="button" aria-pressed="false">CHECK ALL</button></label>
                    @forelse ($operators as $operator)
                        @php($allowedForEveryProject = $projects->every(fn ($project) => $project->allowed_users === null || in_array((int) $operator->id, array_map('intval', $project->allowed_users ?? []), true)))
                        <div class="custom-control custom-checkbox mb-1">
                            <input class="custom-control-input workspace-project-bulk-user" type="checkbox" id="workspace-project-bulk-user-{{ $operator->id }}" value="{{ $operator->id }}" @checked($allowedForEveryProject)>
                            <label class="custom-control-label" for="workspace-project-bulk-user-{{ $operator->id }}">{{ $operator->name }} | {{ $operator->email }}</label>
                        </div>
                    @empty
                        <p class="text-muted">There are no active operators in this workspace to assign.</p>
                    @endforelse
                </div>
                <button id="workspace-project-users-save" class="btn btn-block btn-info" type="button">UPDATE ALLOWED USERS</button>
            </div></div>
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
