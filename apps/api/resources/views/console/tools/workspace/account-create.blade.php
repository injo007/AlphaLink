@extends('layouts.standalone')

@section('title', 'Add Gmail Accounts')

@section('content')
    <div class="command-os-entity-page p-3">
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <div class="d-flex align-items-center">
                    <i class="fab fa-google text-purple font-size-lg mr-2"></i>
                    <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">ADD GMAIL DISPATCH ACCOUNTS</h3>
                </div>
                <span class="badge badge-purple-subtle">
                    <i class="fas fa-plus mr-1"></i> Batch Import
                </span>
            </div>
            
            <div id="workspace-account-create-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
            
            <div class="form-group mb-3">
                <label class="command-form-label font-weight-bold" for="workspace-account-create-project">
                    <i class="fas fa-folder mr-1 text-purple"></i> Select Google Project:
                </label>
                <select id="workspace-account-create-project" class="form-control" required>
                    <option value="">Select Project</option>
                    @foreach ($projects as $project)
                        <option value="{{ $project->id }}" @selected((string) $selectedProjectId === (string) $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
                @if ($projects->isEmpty())
                    <small class="form-text text-danger mt-1"><i class="fas fa-exclamation-circle mr-1"></i>No active Workspace project is available to this user.</small>
                @endif
            </div>

            <div class="form-group mb-4">
                <label class="command-form-label font-weight-bold" for="workspace-account-create-emails">
                    <i class="fas fa-at mr-1 text-purple"></i> Accounts (One email address per line):
                </label>
                <textarea id="workspace-account-create-emails" class="form-control font-monospace resize-none" rows="10" placeholder="account1@domain.com&#10;account2@domain.com&#10;account3@domain.com"></textarea>
            </div>

            <div class="d-flex align-items-center justify-content-end" style="gap: 8px;">
                <button type="button" class="btn btn-command-action" onclick="window.parent?.closeModal ? window.parent.closeModal() : window.history.back()">CANCEL</button>
                <button id="workspace-account-create-save" class="btn btn-command-primary" type="button" @disabled($projects->isEmpty())>
                    <i class="fas fa-save mr-1"></i>SAVE ACCOUNTS
                </button>
            </div>
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
