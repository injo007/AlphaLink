@extends('layouts.dms-reference')

@section('title', 'Add Gmail Accounts - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default"><h3 class="block-title">ADD GMAIL ACCOUNTS</h3></div>
        <div class="block-content">
            <div class="row justify-content-center py-1"><div class="col-sm-10">
                <div id="workspace-account-create-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
                <div class="form-group">
                    <label for="workspace-account-create-project">Project:</label>
                    <select id="workspace-account-create-project" class="form-control" required>
                        <option value="">Select Project</option>
                        @foreach ($projects as $project)
                            <option value="{{ $project->id }}" @selected((string) $selectedProjectId === (string) $project->id)>{{ $project->name }}</option>
                        @endforeach
                    </select>
                    @if ($projects->isEmpty())
                        <small class="form-text text-danger">No active Workspace project is available to this user.</small>
                    @endif
                </div>
                <div class="form-group">
                    <label for="workspace-account-create-emails">Accounts:</label>
                    <textarea id="workspace-account-create-emails" class="form-control" rows="10" placeholder="One email address per line"></textarea>
                </div>
                <div class="form-group"><button id="workspace-account-create-save" class="btn btn-block btn-info" type="button" @disabled($projects->isEmpty())>SAVE</button></div>
            </div></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const save = document.getElementById('workspace-account-create-save');
            const result = document.getElementById('workspace-account-create-result');
            const endpoint = @json(route('console.tools.workspace.accounts.store', $workspace->id));
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            save.addEventListener('click', async () => {
                save.disabled = true;
                result.classList.add('d-none');
                const accounts = document.getElementById('workspace-account-create-emails').value
                    .split(/\r?\n/).map((email) => email.trim()).filter(Boolean);
                try {
                    if (!accounts.length) throw new Error('Enter at least one email address.');
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers: {'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},
                        body: JSON.stringify({project_id:document.getElementById('workspace-account-create-project').value, accounts}),
                    });
                    const body = await response.json().catch(() => ({}));
                    if (!response.ok) throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.message || body.data || 'Accounts could not be added.');
                    if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                        window.parent.App?.swalPromise ? window.parent.App.swalPromise({icon:'success',title:body.message || body.data,confirmButtonText:'close'}).then(() => window.parent.closeModal()) : window.parent.closeModal();
                    } else window.location.assign(@json(route('console.gcampaigns.accounts', $workspace->id)));
                } catch (error) {
                    result.textContent = error.message;
                    result.classList.remove('d-none');
                    save.disabled = false;
                }
            });
        })();
    </script>
@endpush
