@extends('layouts.standalone')

@section('title', 'Add Project')

@section('content')
    <div class="command-os-entity-page p-3">
        <div class="command-os-table-card">
            <!-- Modal Header -->
            <div class="d-flex flex-wrap align-items-center justify-content-between mb-3 pb-3 border-bottom" style="gap: 12px;">
                <div class="d-flex align-items-center">
                    <span class="command-os-report-hero-icon mr-2" style="width: 36px; height: 36px; border-radius: 8px; display: inline-flex; align-items: center; justify-content: center; background: rgba(99, 102, 241, 0.12); color: #6366f1;">
                        <i class="fas fa-folder-plus"></i>
                    </span>
                    <div>
                        <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">ADD PROJECT</h3>
                        <small class="text-muted">Connect a Google Cloud Workspace service project via JSON or S3 bucket</small>
                    </div>
                </div>

                <!-- Advanced S3 Import Trigger -->
                <div class="command-s3-trigger-card d-inline-flex align-items-center" id="workspace-s3-trigger-wrapper">
                    <div class="command-s3-trigger-indicator" id="workspace-s3-trigger-indicator">
                        <i class="fas fa-file-code mr-1 text-purple" id="workspace-trigger-icon"></i>
                        <span id="workspace-trigger-status-text" class="font-w700" style="font-size: 0.75rem; letter-spacing: 0.04em;">DIRECT JSON</span>
                    </div>
                    <div class="custom-control custom-switch custom-control-inline m-0 pl-2">
                        <input type="checkbox" class="custom-control-input" id="workspace-project-create-s3" aria-controls="workspace-project-create-s3-panel">
                        <label class="custom-control-label" for="workspace-project-create-s3" style="cursor: pointer; font-weight: 700; font-size: 0.75rem; text-transform: uppercase;">
                            <span class="d-inline-flex align-items-center">
                                <i class="fab fa-aws text-warning mr-1"></i> S3
                            </span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Error / Notification Banner -->
            <div id="workspace-project-create-result" class="alert alert-danger d-none mb-3" role="alert" aria-live="assertive"></div>

            <!-- 1. Direct JSON Configuration Panel -->
            <div id="workspace-project-create-manual">
                <form id="workspace-project-create-form">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="command-form-label font-w700" for="workspace-project-create-type">
                                <i class="fas fa-tag mr-1 text-purple"></i> Type:
                            </label>
                            <select id="workspace-project-create-type" class="form-control" title="Type" disabled>
                                <option value="workspace" selected>WORKSPACE</option>
                            </select>
                            <small class="form-text text-muted mt-1">AlphaLink workspace projects use Google Workspace domain-wide delegation.</small>
                        </div>
                        <div class="col-md-8 mb-3">
                            <label class="command-form-label font-w700" for="workspace-project-create-name">
                                <i class="fas fa-heading mr-1 text-purple"></i> Project Name:
                            </label>
                            <input type="text" class="form-control" id="workspace-project-create-name" maxlength="255" placeholder="Enter Project Name (e.g. workspace-admin@domain.com)..." required autocomplete="off">
                        </div>
                    </div>

                    <!-- Domain-Wide Delegation Scopes Callout -->
                    <div class="command-os-surface p-3 mb-3" style="border-radius: 10px; background: rgba(99, 102, 241, 0.04); border: 1px solid rgba(99, 102, 241, 0.18);">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="font-w700 text-uppercase" style="font-size: 0.8125rem; color: #6366f1; letter-spacing: 0.04em;">
                                <i class="fas fa-shield-alt mr-1"></i> ADD THESE SCOPES IN WIDE DELEGATION
                            </div>
                            <a href="https://admin.google.com/ac/owl/domainwidedelegation" target="_blank" rel="noopener noreferrer" class="btn-command-action" style="padding: 2px 10px; font-size: 0.75rem; text-decoration: none;" aria-label="Open Google Workspace domain-wide delegation">
                                Google Admin <i class="fa fa-external-link-alt ml-1" aria-hidden="true"></i>
                            </a>
                        </div>
                        <div class="p-2 mb-2 rounded" style="background: rgba(15, 23, 42, 0.05); font-family: monospace; font-size: 0.75rem; line-height: 1.6; max-height: 120px; overflow-y: auto;">
                            https://mail.google.com/<br>
                            https://www.googleapis.com/auth/gmail.settings.basic<br>
                            https://www.googleapis.com/auth/gmail.settings.sharing<br>
                            https://www.googleapis.com/auth/gmail.modify<br>
                            https://www.googleapis.com/auth/gmail.readonly<br>
                            https://www.googleapis.com/auth/admin.directory.user<br>
                            https://www.googleapis.com/auth/admin.directory.group<br>
                            https://www.googleapis.com/auth/admin.directory.domain<br>
                            https://www.googleapis.com/auth/admin.directory.orgunit<br>
                            https://www.googleapis.com/auth/admin.directory.customer<br>
                            https://www.googleapis.com/auth/admin.directory.user.alias
                        </div>
                        <div class="font-w700 text-uppercase small" style="color: var(--command-os-text, #1e293b); letter-spacing: 0.04em;">
                            <i class="fas fa-check-circle mr-1 text-emerald"></i> ENABLE ADMIN SDK AND GMAIL API
                        </div>
                    </div>

                    <!-- Secret Admin JSON -->
                    <div class="form-group mb-3">
                        <label class="command-form-label font-w700" for="workspace-project-create-service-account">
                            <i class="fas fa-key mr-1 text-purple"></i> Secret Admin (Service Account JSON):
                        </label>
                        <textarea id="workspace-project-create-service-account" class="form-control font-monospace" rows="8" spellcheck="false" autocomplete="off" placeholder="Paste Google Cloud Service Account JSON credentials here..." required style="font-size: 0.8125rem;"></textarea>
                        <small class="form-text text-muted mt-1"><i class="fas fa-lock mr-1"></i> Credential is stored encrypted. Saving queues account synchronization from Google Admin Directory.</small>
                    </div>

                    <!-- Allowed Mailers -->
                    <div class="form-group mb-3">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <label class="command-form-label font-w700 mb-0">
                                <i class="fas fa-users mr-1 text-purple"></i> Allowed Mailers:
                            </label>
                            <button id="workspace-project-create-check-users" class="btn-command-action" type="button" aria-pressed="false" style="padding: 2px 10px; font-size: 0.75rem;">
                                CHECK <span class="fas fa-check ml-1" aria-hidden="true"></span>
                            </button>
                        </div>
                        <div class="p-3 rounded" style="background: var(--command-os-card-bg, #ffffff); border: 1px solid var(--command-os-border, rgba(99, 102, 241, 0.16)); max-height: 160px; overflow-y: auto;">
                            <div class="row">
                                @forelse ($operators->chunk(2) as $chunk)
                                    <div class="col-sm-12 col-md-6">
                                        @foreach ($chunk as $operator)
                                            <div class="mb-2">
                                                <label class="command-table-checkbox-label d-inline-flex align-items-center" for="workspace-project-create-user-{{ $operator->id }}" style="cursor: pointer;">
                                                    <input type="checkbox" value="{{ $operator->id }}" class="workspace-project-create-user" id="workspace-project-create-user-{{ $operator->id }}">
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
                        <button id="workspace-project-create-save" class="btn-command-primary" type="submit">
                            <i class="fas fa-save mr-1"></i> SAVE
                        </button>
                    </div>
                </form>
            </div>

            <!-- 2. S3 Sync Panel -->
            <div id="workspace-project-create-s3-panel" class="d-none">
                <div class="command-os-surface p-3 mb-3" style="border-radius: 10px; background: rgba(245, 158, 11, 0.06); border: 1px solid rgba(245, 158, 11, 0.25);">
                    <div class="d-flex align-items-center mb-2">
                        <i class="fab fa-aws text-warning font-size-lg mr-2"></i>
                        <h4 class="font-w700 mb-0" style="font-size: 0.875rem; letter-spacing: 0.03em;">AWS S3 BUCKET PROJECT SYNCHRONIZATION</h4>
                    </div>
                    <p class="small text-muted mb-0">SYNC imports every project file in the S3 folder. New and changed projects queue account syncs; unchanged projects remain untouched.</p>
                </div>

                <div class="form-group mb-4">
                    <label class="command-form-label font-w700" for="workspace-project-create-s3-link">
                        <i class="fas fa-link mr-1 text-purple"></i> S3 Folder HTTP/S3 Link:
                    </label>
                    <input type="url" class="form-control" id="workspace-project-create-s3-link" maxlength="2048" placeholder="https://bucket.s3.region.amazonaws.com/prefix" value="{{ $s3Link }}" autocomplete="url" required>
                    <small class="form-text text-muted mt-1">Provide the accessible HTTPS or authenticated bucket endpoint URL containing project JSON manifests.</small>
                </div>

                <div class="d-flex align-items-center justify-content-end pt-3 border-top" style="gap: 10px;">
                    <button type="button" class="btn-command-action" onclick="window.parent?.closeModal ? window.parent.closeModal() : window.history.back()">CANCEL</button>
                    <button id="workspace-project-create-s3-sync" class="btn-command-primary" type="button">
                        <i class="fas fa-sync-alt mr-1"></i> SYNC
                    </button>
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
            const triggerStatus = document.getElementById('workspace-trigger-status-text');
            const triggerIcon = document.getElementById('workspace-trigger-icon');
            const triggerWrapper = document.getElementById('workspace-s3-trigger-wrapper');
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

            const updateTriggerState = () => {
                const isS3 = s3Toggle.checked;
                manual.classList.toggle('d-none', isS3);
                s3Panel.classList.toggle('d-none', !isS3);
                result.classList.add('d-none');
                if (triggerStatus) triggerStatus.textContent = isS3 ? 'S3 FOLDER' : 'DIRECT JSON';
                if (triggerIcon) {
                    triggerIcon.className = isS3 ? 'fab fa-aws mr-1 text-warning' : 'fas fa-file-code mr-1 text-purple';
                }
                if (triggerWrapper) {
                    triggerWrapper.classList.toggle('active-s3', isS3);
                }
            };

            s3Toggle.addEventListener('change', updateTriggerState);

            document.getElementById('workspace-project-create-check-users').addEventListener('click', (event) => {
                const checked = users.some((input) => !input.checked);
                users.forEach((input) => { input.checked = checked; });
                event.currentTarget.setAttribute('aria-pressed', String(checked));
                event.currentTarget.innerHTML = checked ? 'UNCHECK <span class="fas fa-times ml-1" aria-hidden="true"></span>' : 'CHECK <span class="fas fa-check ml-1" aria-hidden="true"></span>';
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
                    const skippedSuffix = body.skipped > 0 && Array.isArray(body.failed_files) && body.failed_files.length
                        ? ` Skipped: ${body.failed_files.slice(0, 3).join(', ')}${body.skipped > 3 ? ` (+${body.skipped - 3} more)` : ''}.`
                        : '';
                    const summary = `${body.message || 'S3 project sync complete.'}. ${body.unchanged ?? 0} unchanged.${skippedSuffix}`;
                    finish(summary);
                } catch (error) {
                    showError(error.message);
                    busy(sync, false, 'SYNC');
                }
            });
        })();
    </script>
@endpush
