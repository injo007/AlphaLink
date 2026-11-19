@extends('layouts.standalone')

@section('title', 'Upload Emails')

@section('content')
    <div class="command-modal-surface p-3 p-md-4 list-import-page" style="min-height: 100vh;">
        <header class="command-modal-hero mb-3">
            <div class="command-modal-hero-left">
                <span class="command-modal-hero-icon" aria-hidden="true"><i class="fas fa-file-upload"></i></span>
                <div>
                    <p class="command-os-report-eyebrow mb-1">AUDIENCE MANAGEMENT / IMPORT</p>
                    <h1 class="m-0 font-w700 font-size-base">Import recipients</h1>
                    <p class="text-muted font-size-xs mb-0 mt-1">Primary destination: <strong class="text-ink">{{ $list->name }}</strong></p>
                </div>
            </div>
            <a class="btn-command-action" href="{{ route('console.infrastructure.lists.import-example', $workspace->id) }}" aria-label="Download a synthetic example CSV">
                <i class="fas fa-download mr-1" aria-hidden="true"></i> EXAMPLE CSV
            </a>
        </header>

        @if (session('info'))
            <div class="alert alert-info py-2 px-3 mb-3 font-size-sm" role="status" aria-live="polite">{{ session('info') }}</div>
        @endif
        <div id="list-import-progress" class="alert alert-secondary py-2 px-3 mb-3 font-size-sm d-flex align-items-center" role="status" aria-live="polite" @if (!session('import_batch_id')) hidden @endif
            data-status-url="{{ session('import_batch_id') ? route('console.infrastructure.lists.import-status', [$workspace->id, $list->id, session('import_batch_id')]) : '' }}">
            <i class="fas fa-spinner fa-spin mr-2 text-purple" aria-hidden="true"></i>
            <span id="list-import-progress-text">Checking import status…</span>
        </div>
        <div id="list-file-upload-progress" class="alert alert-secondary py-2 px-3 mb-3" role="status" aria-live="polite" hidden>
            <div class="d-flex align-items-center justify-content-between mb-1">
                <span id="list-file-upload-text">Preparing upload…</span>
                <span id="list-file-upload-percent" class="font-mono">0%</span>
            </div>
            <div class="progress" style="height: 8px; border-radius: 99px;">
                <div id="list-file-upload-bar" class="progress-bar bg-primary" role="progressbar" style="width: 0%" aria-label="File upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
            </div>
        </div>
        @if ($errors->any())
            <div class="alert alert-danger py-2 px-3 mb-3 font-size-sm" role="alert" aria-live="assertive">
                <ul class="mb-0 pl-3">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        <div id="list-upload-validation-error" class="alert alert-danger py-2 px-3 mb-3 font-size-sm" role="alert" hidden></div>

        <div class="row align-items-start">
            <main class="col-12 col-lg-8">
                <form id="list-email-upload" enctype="multipart/form-data" action="{{ route('console.infrastructure.lists.upload.save', [$workspace->id, $list->id]) }}" method="post" novalidate>
                    @csrf
                    <section class="command-os-filter-card mb-3" aria-labelledby="list-import-source-heading">
                        <div class="d-flex align-items-start justify-content-between mb-3">
                            <div>
                                <p class="command-os-report-eyebrow mb-1">STEP 1 / SOURCE DATA</p>
                                <h2 id="list-import-source-heading" class="h5 mb-1">Choose file and segment</h2>
                                <p class="text-muted font-size-xs mb-0">The selected segment type is assigned to imported recipients.</p>
                            </div>
                            <span class="badge badge-light border text-muted">TXT / CSV</span>
                        </div>

                        <div class="form-group mb-3">
                            <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="email-file"><span class="text-danger mr-1" aria-hidden="true">*</span>Source file</label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" name="file" id="email-file" accept=".txt,.csv,text/plain,text/csv" required aria-describedby="email-file-help email-file-error">
                                <label class="custom-file-label" for="email-file">Choose a TXT or CSV file…</label>
                            </div>
                            <small id="email-file-help" class="form-text text-muted mt-2">CSV may include a header row (email, first_name, last_name, country, age, sex/gender, vertical); extra columns are preserved as tags. Large imports run in resumable background chunks. Invalid optional values are counted and skipped without cancelling valid rows.</small>
                            <small id="email-file-error" class="form-text text-danger" hidden>Choose a TXT or CSV file to continue.</small>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-6 mb-3">
                                <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="email-type"><span class="text-danger mr-1" aria-hidden="true">*</span>Segment type</label>
                                <select id="email-type" name="type" class="form-control" required aria-describedby="email-type-error">
                                    <option value="">Select segment type…</option>
                                    @foreach ($types as $type)
                                        <option value="{{ $type }}" @selected(old('type') === $type)>{{ ucfirst(str_replace('_', ' ', $type)) }}</option>
                                    @endforeach
                                </select>
                                <small id="email-type-error" class="form-text text-danger" hidden>Choose the segment type for this import.</small>
                            </div>
                            <div class="form-group col-md-6 mb-3">
                                <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="email-filter">Email keyword filter <span class="text-muted font-weight-normal">(optional)</span></label>
                                <input type="text" class="form-control" name="filter" id="email-filter" maxlength="255" placeholder="Only include addresses containing…" value="{{ old('filter') }}">
                                <small class="form-text text-muted">Rows that do not match are counted as filtered.</small>
                            </div>
                        </div>
                    </section>

                    <section class="command-os-filter-card mb-3" aria-labelledby="list-import-copy-heading">
                        <div class="d-flex align-items-start justify-content-between">
                            <div>
                                <p class="command-os-report-eyebrow mb-1">STEP 2 / OPTIONAL</p>
                                <h2 id="list-import-copy-heading" class="h5 mb-1">Create a second list</h2>
                                <p class="text-muted font-size-xs mb-0">Make an independent copy from these same valid rows.</p>
                            </div>
                            <span class="badge badge-light border text-purple">OFF BY DEFAULT</span>
                        </div>
                        <div class="custom-control custom-checkbox mt-3">
                            <input type="checkbox" class="custom-control-input" id="create-duplicate" name="create_duplicate" value="1" @checked(old('create_duplicate')) aria-controls="duplicate-list-options" aria-expanded="false">
                            <label class="custom-control-label font-w600" for="create-duplicate">Create a separately named list during this upload</label>
                        </div>
                        <div id="duplicate-list-options" class="list-import-copy-options mt-3" hidden>
                            <div class="form-row">
                                <div class="form-group col-md-6 mb-2">
                                    <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="duplicate-name"><span class="text-danger mr-1" aria-hidden="true">*</span>New list name</label>
                                    <input type="text" class="form-control" name="duplicate_name" id="duplicate-name" maxlength="160" value="{{ old('duplicate_name') }}" placeholder="For example, D4GOBE retail" aria-describedby="duplicate-name-help duplicate-name-error" disabled>
                                    <small id="duplicate-name-help" class="form-text text-muted">This new list is separate from “{{ $list->name }}”.</small>
                                    <small id="duplicate-name-error" class="form-text text-danger" hidden>Enter a different name for the second list.</small>
                                </div>
                                <div class="form-group col-md-6 mb-2">
                                    <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="duplicate-niche">Niche / vertical <span class="text-muted font-weight-normal">(optional)</span></label>
                                    <input type="text" class="form-control" name="duplicate_niche" id="duplicate-niche" maxlength="160" value="{{ old('duplicate_niche') }}" placeholder="For example, retail" aria-describedby="duplicate-niche-help" disabled>
                                    <small id="duplicate-niche-help" class="form-text text-muted">Adds a visible list label and sets the copied recipients’ vertical tag. Other CSV tags remain unchanged.</small>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="d-flex justify-content-end pt-3 border-top">
                        <button id="upload-emails" type="submit" class="btn-command-primary" style="padding: 10px 24px;">
                            <i class="fas fa-upload mr-1" aria-hidden="true"></i> UPLOAD EMAILS
                        </button>
                    </div>
                </form>
            </main>

            <aside class="col-12 col-lg-4">
                <section class="command-os-filter-card list-import-guide" aria-labelledby="list-import-guide-heading">
                    <p class="command-os-report-eyebrow mb-1">IMPORT PLAN</p>
                    <h2 id="list-import-guide-heading" class="h5 mb-3">Where your data goes</h2>
                    <div class="list-import-destination mb-3">
                        <span class="text-muted font-size-xs text-uppercase">Primary list</span>
                        <strong class="d-block mt-1">{{ $list->name }}</strong>
                        <span class="badge badge-light border text-purple mt-2">{{ $list->country ?: 'GLOBAL' }}</span>
                        @if ($list->niche)<span class="badge badge-light border text-purple mt-2">{{ $list->niche }}</span>@endif
                    </div>
                    <ul class="list-import-points mb-0 pl-3">
                        <li>By default, recipients are added only to the primary list.</li>
                        <li>The optional second list reuses the same valid CSV rows; it does not create automatic country/type projections.</li>
                        <li>Progress, skipped rows, and duplicates are reported while the background import runs.</li>
                    </ul>
                </section>
            </aside>
        </div>
    </div>
@endsection

@push('styles')
    <style>
        .list-import-page .command-modal-hero { padding: 0 0 1rem; background: transparent; border-bottom: 1px solid var(--co-border, #e2e8f0); }
        .list-import-page .command-modal-hero-icon { flex: 0 0 auto; }
        .list-import-page .command-os-filter-card { height: auto; }
        .list-import-page .list-import-copy-options { border-top: 1px solid var(--co-border, #e2e8f0); padding-top: 1rem; }
        .list-import-page .list-import-destination { border: 1px solid var(--co-border, #e2e8f0); border-radius: .65rem; padding: .85rem; }
        .list-import-page .list-import-points li { margin: 0 0 .75rem; }
        .list-import-page .list-import-points li:last-child { margin-bottom: 0; }
        .list-import-page [aria-invalid="true"] { border-color: #dc3545; }
        @media (max-width: 991.98px) { .list-import-page .list-import-guide { margin-top: .25rem; } }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('#list-email-upload');
            const button = document.querySelector('#upload-emails');
            const fileInput = document.querySelector('#email-file');
            const customLabel = fileInput?.nextElementSibling;
            const typeInput = document.querySelector('#email-type');
            const duplicateToggle = document.querySelector('#create-duplicate');
            const duplicateOptions = document.querySelector('#duplicate-list-options');
            const duplicateName = document.querySelector('#duplicate-name');
            const validationError = document.querySelector('#list-upload-validation-error');
            const clearFieldError = (input, error) => {
                input?.setAttribute('aria-invalid', 'false');
                if (error) error.hidden = true;
            };

            const toggleDuplicateOptions = () => {
                const enabled = Boolean(duplicateToggle?.checked);
                duplicateOptions.hidden = !enabled;
                duplicateToggle.setAttribute('aria-expanded', String(enabled));
                duplicateName.disabled = !enabled;
                duplicateName.required = enabled;
                document.querySelector('#duplicate-niche').disabled = !enabled;
            };
            duplicateToggle?.addEventListener('change', toggleDuplicateOptions);
            toggleDuplicateOptions();

            fileInput?.addEventListener('change', () => {
                if (customLabel) customLabel.textContent = fileInput.files?.[0]?.name || 'Choose file...';
                clearFieldError(fileInput, document.querySelector('#email-file-error'));
            });
            typeInput?.addEventListener('change', () => clearFieldError(typeInput, document.querySelector('#email-type-error')));
            duplicateName?.addEventListener('input', () => clearFieldError(duplicateName, document.querySelector('#duplicate-name-error')));

            const uploadProgress = document.querySelector('#list-file-upload-progress');
            const uploadText = document.querySelector('#list-file-upload-text');
            const uploadPercent = document.querySelector('#list-file-upload-percent');
            const uploadBar = document.querySelector('#list-file-upload-bar');
            const importProgress = document.querySelector('#list-import-progress');
            const importText = document.querySelector('#list-import-progress-text');

            const updateUploadProgress = (percent, message) => {
                const value = Math.max(0, Math.min(100, percent));
                uploadProgress.hidden = false;
                uploadText.textContent = message;
                uploadPercent.textContent = `${value}%`;
                uploadBar.style.width = `${value}%`;
                uploadBar.setAttribute('aria-valuenow', String(value));
            };

            const showImportStatus = (status) => {
                importProgress.hidden = false;
                importProgress.dataset.statusUrl = status;
                importText.textContent = 'Import queued. Checking progress…';
                pollImportStatus();
            };

            const pollImportStatus = async () => {
                const statusUrl = importProgress?.dataset.statusUrl;
                if (!importProgress || !importText || !statusUrl) return;
                try {
                    const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                    if (!response.ok) throw new Error('status unavailable');
                    const { data } = await response.json();
                    importText.textContent = data.status === 'completed'
                        ? `Import complete: ${data.imported_count.toLocaleString()} imported, ${data.duplicate_count.toLocaleString()} duplicates, ${data.invalid_count.toLocaleString()} invalid, ${data.profile_warning_count.toLocaleString()} optional profile values skipped.${data.duplicate_list_name ? ` Second list created: ${data.duplicate_list_name}.` : ''}`
                        : data.status === 'failed'
                            ? `Import failed: ${data.failure_message || 'Upload the source file again.'}`
                            : `${data.phase === 'validation' ? 'Validating' : 'Importing'}… ${data.progress_percent}% — ${data.processed_count.toLocaleString()} processed, ${data.imported_count.toLocaleString()} imported.`;
                    if (['completed', 'failed'].includes(data.status)) {
                        button.disabled = false;
                        button.removeAttribute('aria-busy');
                        button.innerHTML = '<i class="fas fa-upload mr-1"></i> UPLOAD EMAILS';
                        return;
                    }
                } catch (_) {
                    importText.textContent = 'Import status is temporarily unavailable; the background import will continue.';
                }
                window.setTimeout(pollImportStatus, 2500);
            };

            form?.addEventListener('submit', (event) => {
                event.preventDefault();
                const checks = [
                    [fileInput, document.querySelector('#email-file-error'), !fileInput.files?.length, 'Choose a TXT or CSV file to continue.'],
                    [typeInput, document.querySelector('#email-type-error'), !typeInput.value, 'Choose the segment type for this import.'],
                    [duplicateName, document.querySelector('#duplicate-name-error'), Boolean(duplicateToggle.checked && (!duplicateName.value.trim() || duplicateName.value.trim() === @json($list->name))), 'Enter a different name for the second list.'],
                ];
                let firstInvalid = null;
                checks.forEach(([input, error, invalid, message]) => {
                    input.setAttribute('aria-invalid', String(invalid));
                    error.hidden = !invalid;
                    error.textContent = message;
                    if (invalid && firstInvalid === null) firstInvalid = input;
                });
                if (firstInvalid) {
                    validationError.textContent = 'Review the highlighted fields before starting the import.';
                    validationError.hidden = false;
                    firstInvalid.focus();
                    return;
                }
                validationError.hidden = true;
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> UPLOADING...';
                updateUploadProgress(0, 'Uploading file…');

                const request = new XMLHttpRequest();
                request.open('POST', form.action);
                request.setRequestHeader('Accept', 'application/json');
                request.upload.addEventListener('progress', (progressEvent) => {
                    if (progressEvent.lengthComputable) updateUploadProgress(Math.round((progressEvent.loaded / progressEvent.total) * 100), 'Uploading file…');
                });
                request.addEventListener('load', () => {
                    let payload = {};
                    try { payload = JSON.parse(request.responseText); } catch (_) {}
                    if (request.status === 202 && payload.status_url) {
                        updateUploadProgress(100, 'File received. Importing rows in background…');
                        showImportStatus(payload.status_url);
                        return;
                    }
                    const message = payload.message || payload.errors?.file?.[0] || 'The file upload failed. Check the file and retry.';
                    const serverErrors = payload.errors || {};
                    const serverError = Object.values(serverErrors).flat()[0];
                    const fieldErrors = {
                        file: [fileInput, document.querySelector('#email-file-error')],
                        type: [typeInput, document.querySelector('#email-type-error')],
                        duplicate_name: [duplicateName, document.querySelector('#duplicate-name-error')],
                    };
                    const firstFieldError = Object.entries(fieldErrors).find(([key]) => serverErrors[key]?.length);
                    if (firstFieldError) {
                        const [input, error] = firstFieldError[1];
                        input.setAttribute('aria-invalid', 'true');
                        error.textContent = serverErrors[firstFieldError[0]][0];
                        error.hidden = false;
                        validationError.textContent = 'Review the highlighted fields before starting the import.';
                        validationError.hidden = false;
                        input.focus();
                    }
                    updateUploadProgress(0, serverError || message);
                    uploadProgress.classList.remove('alert-secondary');
                    uploadProgress.classList.add('alert-danger');
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                    button.innerHTML = '<i class="fas fa-upload mr-1"></i> RETRY UPLOAD';
                });
                request.addEventListener('error', () => {
                    updateUploadProgress(0, 'Network error while uploading. The list was not changed; retry the upload.');
                    uploadProgress.classList.remove('alert-secondary');
                    uploadProgress.classList.add('alert-danger');
                    button.disabled = false;
                    button.removeAttribute('aria-busy');
                    button.innerHTML = '<i class="fas fa-upload mr-1"></i> RETRY UPLOAD';
                });
                request.send(new FormData(form));
            });

            if (importProgress?.dataset.statusUrl) pollImportStatus();
        })();
    </script>
@endpush
