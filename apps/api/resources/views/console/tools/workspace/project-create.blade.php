@extends('layouts.standalone')

@section('title', 'Add Project')

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title">ADD PROJECT</h3>
            <div class="block-options">
                <div class="custom-control custom-switch custom-control-inline">
                    <input type="checkbox" class="custom-control-input" id="workspace-project-create-s3" aria-controls="workspace-project-create-s3-panel">
                    <label class="custom-control-label" for="workspace-project-create-s3">S3</label>
                </div>
            </div>
        </div>
        <div class="block-content">
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    <div id="workspace-project-create-result" class="alert alert-danger d-none" role="alert" aria-live="assertive"></div>
                </div>
                <div id="workspace-project-create-manual" class="col-sm-10">
                    <form id="workspace-project-create-form">
                        <div class="form-group">
                            <label for="workspace-project-create-type">Type:</label>
                            <select id="workspace-project-create-type" class="form-control selectpicker" title="Type" disabled>
                                <option value="workspace" selected>WORKSPACE</option>
                            </select>
                            <small class="form-text text-muted">AlphaLink workspace projects use Google Workspace domain-wide delegation.</small>
                        </div>
                        <div class="form-group">
                            <label for="workspace-project-create-name">Name:</label>
                            <input type="text" class="form-control form-control-alt" id="workspace-project-create-name" maxlength="255" placeholder="Enter Project Name ..." required autocomplete="off">
                        </div>
                        <div class="alert alert-info" role="alert">
                            <div>ADD THESE SCOPES IN WIDE DELEGATION <a href="https://admin.google.com/ac/owl/domainwidedelegation" target="_blank" rel="noopener noreferrer" aria-label="Open Google Workspace domain-wide delegation"><i class="fa fa-link" aria-hidden="true"></i></a></div>
                            <ul class="mb-2">
                                <li>https://mail.google.com/</li>
                                <li>https://www.googleapis.com/auth/gmail.settings.basic</li>
                                <li>https://www.googleapis.com/auth/gmail.settings.sharing</li>
                                <li>https://www.googleapis.com/auth/gmail.modify</li>
                                <li>https://www.googleapis.com/auth/gmail.readonly</li>
                                <li>https://www.googleapis.com/auth/admin.directory.user</li>
                                <li>https://www.googleapis.com/auth/admin.directory.group</li>
                                <li>https://www.googleapis.com/auth/admin.directory.domain</li>
                                <li>https://www.googleapis.com/auth/admin.directory.orgunit</li>
                                <li>https://www.googleapis.com/auth/admin.directory.customer</li>
                                <li>https://www.googleapis.com/auth/admin.directory.user.alias</li>
                            </ul>
                            <p class="mb-0">ENABLE ADMIN SDK AND GMAIL API</p>
                        </div>
                        <div class="form-group">
                            <label for="workspace-project-create-service-account">Secret Admin:</label>
                            <textarea id="workspace-project-create-service-account" class="form-control form-control-alt" rows="10" spellcheck="false" autocomplete="off" placeholder="Enter Secret Admin ..." required></textarea>
                            <small class="form-text text-muted">Credential is stored encrypted. Saving queues account synchronization from Google Admin Directory.</small>
                        </div>
                        <div class="form-group">
                            <label>Allowed Mailers: <button id="workspace-project-create-check-users" class="btn btn-link btn-sm" type="button" aria-pressed="false">CHECK <span class="fas fa-check" aria-hidden="true"></span></button></label>
                            <div class="row">
                                @forelse ($operators->chunk(2) as $chunk)
                                    <div class="col-sm-12 col-md-6">
                                        @foreach ($chunk as $operator)
                                            <div class="custom-control custom-checkbox mb-1">
                                                <input type="checkbox" value="{{ $operator->id }}" class="custom-control-input workspace-project-create-user" id="workspace-project-create-user-{{ $operator->id }}">
                                                <label class="custom-control-label" for="workspace-project-create-user-{{ $operator->id }}">{{ $operator->name }} | {{ $operator->email }}</label>
                                            </div>
                                        @endforeach
                                    </div>
                                @empty
                                    <div class="col-12"><small class="form-text text-muted">There are no active operators in this workspace to assign.</small></div>
                                @endforelse
                            </div>
                        </div>
                        <div class="form-group"><button id="workspace-project-create-save" class="btn btn-block btn-info" type="submit">SAVE</button></div>
                    </form>
                </div>
                <div id="workspace-project-create-s3-panel" class="col-sm-10 d-none">
                    <div class="form-group">
                        <label for="workspace-project-create-s3-link">Link:</label>
                        <input type="url" class="form-control form-control-alt" id="workspace-project-create-s3-link" maxlength="2048" placeholder="Enter Link ..." value="{{ $s3Link }}" autocomplete="url" required>
                        <small class="form-text text-muted">SYNC imports every project file in the S3 folder. New and changed projects queue account syncs; unchanged projects remain untouched.</small>
                    </div>
                    <div class="form-group"><button id="workspace-project-create-s3-sync" class="btn btn-block btn-info" type="button">SYNC</button></div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const base = @json(url('/app/workspaces/'.$workspace->id.'/tools/workspace'));
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const result = document.getElementById('workspace-project-create-result');
            const manual = document.getElementById('workspace-project-create-manual');
            const s3Panel = document.getElementById('workspace-project-create-s3-panel');
            const s3Toggle = document.getElementById('workspace-project-create-s3');
            const form = document.getElementById('workspace-project-create-form');
            const save = document.getElementById('workspace-project-create-save');
            const sync = document.getElementById('workspace-project-create-s3-sync');
            const users = [...document.querySelectorAll('.workspace-project-create-user')];
            const showError = (message) => { result.textContent = message; result.classList.remove('d-none'); };
            const busy = (button, value, label) => { button.disabled = value; button.textContent = value ? `${label}ING…` : label; };
            const send = async (endpoint, method, data) => {
                const response = await fetch(endpoint, {method,headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(data)});
                const body = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(Object.values(body.errors ?? {}).flat().join(' ') || body.message || body.data || 'Project request failed.');
                return body;
            };
            const finish = (message) => {
                if (window.parent !== window && typeof window.parent.closeModal === 'function') {
                    if (window.parent.App?.swalPromise) window.parent.App.swalPromise({icon:'success',title:message,confirmButtonText:'close'}).then(() => window.parent.closeModal());
                    else window.parent.closeModal();
                } else window.location.assign(@json(route('console.gcampaigns.projects', $workspace->id)));
            };

            s3Toggle.addEventListener('change', () => {
                manual.classList.toggle('d-none', s3Toggle.checked);
                s3Panel.classList.toggle('d-none', !s3Toggle.checked);
                result.classList.add('d-none');
            });
            document.getElementById('workspace-project-create-check-users').addEventListener('click', (event) => {
                const checked = users.some((input) => !input.checked);
                users.forEach((input) => { input.checked = checked; });
                event.currentTarget.setAttribute('aria-pressed', String(checked));
                event.currentTarget.innerHTML = checked ? 'UNCHECK <span class="fas fa-times" aria-hidden="true"></span>' : 'CHECK <span class="fas fa-check" aria-hidden="true"></span>';
            });
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                result.classList.add('d-none');
                if (!form.reportValidity()) return;
                busy(save, true, 'SAV');
                try {
                    const body = await send(`${base}/projects`, 'POST', {
                        name: document.getElementById('workspace-project-create-name').value.trim(),
                        service_account_json: document.getElementById('workspace-project-create-service-account').value,
                        users: users.filter((input) => input.checked).map((input) => Number(input.value)),
                    });
                    finish(body.message || 'Project saved. Account synchronization queued.');
                } catch (error) {
                    showError(error.message);
                    busy(save, false, 'SAVE');
                }
            });
            sync.addEventListener('click', async () => {
                const link = document.getElementById('workspace-project-create-s3-link');
                result.classList.add('d-none');
                if (!link.reportValidity()) return;
                busy(sync, true, 'SYNC');
                try {
                    const body = await send(`${base}/projects/sync-s3`, 'POST', {link:link.value.trim()});
                    const summary = `${body.message || 'S3 project sync complete.'}. ${body.unchanged ?? 0} unchanged.`;
                    finish(summary);
                } catch (error) {
                    showError(error.message);
                    busy(sync, false, 'SYNC');
                }
            });
        })();
    </script>
@endpush
