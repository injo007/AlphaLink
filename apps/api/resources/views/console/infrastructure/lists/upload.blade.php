@extends('layouts.standalone')

@section('title', 'Upload Emails')

@section('content')
    <div class="command-modal-surface p-4" style="min-height: 100vh;">
        <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
            <div class="d-flex align-items-center">
                <i class="fas fa-file-upload text-purple mr-2" style="font-size: 1.25rem;"></i>
                <h3 class="m-0 font-w700 font-size-base text-uppercase" style="letter-spacing: 0.04em;">
                    Upload Emails <span class="badge-command-pill ml-2">{{ $list->name }}</span>
                </h3>
            </div>
            <a class="btn-command-action" href="{{ route('console.infrastructure.lists.import-example', $workspace->id) }}" style="padding: 4px 12px; font-size: 11px;">
                <i class="fas fa-download mr-1"></i> DOWNLOAD EXAMPLE CSV
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-12 col-md-11">
                @if (session('info'))
                    <div class="alert alert-info py-2 px-3 mb-3 font-size-sm" role="status" aria-live="polite" style="border-radius: 10px;">{{ session('info') }}</div>
                @endif
                <div id="list-import-progress" class="alert alert-secondary py-2 px-3 mb-3 font-size-sm d-flex align-items-center" role="status" aria-live="polite" style="border-radius: 10px;" @if (!session('import_batch_id')) hidden @endif
                    data-status-url="{{ session('import_batch_id') ? route('console.infrastructure.lists.import-status', [$workspace->id, $list->id, session('import_batch_id')]) : '' }}">
                    <i class="fas fa-spinner fa-spin mr-2 text-purple"></i>
                    <span id="list-import-progress-text">Checking import status…</span>
                </div>
                <div id="list-file-upload-progress" class="alert alert-secondary py-2 px-3 mb-3" role="status" aria-live="polite" hidden>
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span id="list-file-upload-text">Preparing upload…</span>
                        <span id="list-file-upload-percent" class="font-mono">0%</span>
                    </div>
                    <div class="progress" style="height: 8px; border-radius: 99px;">
                        <div id="list-file-upload-bar" class="progress-bar bg-primary" role="progressbar" style="width: 0%" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
                    </div>
                </div>
                @if ($errors->any())
                    <div class="alert alert-danger py-2 px-3 mb-3 font-size-sm" role="alert" aria-live="assertive" style="border-radius: 10px;">
                        <ul class="mb-0 pl-3">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                <form id="list-email-upload" enctype="multipart/form-data" action="{{ route('console.infrastructure.lists.upload.save', [$workspace->id, $list->id]) }}" method="post">
                    @csrf
                    <div class="form-group mb-3">
                        <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="email-file">
                            <span class="text-danger mr-1">*</span>Emails Source File (.txt, .csv):
                        </label>
                        <div class="custom-file">
                            <input type="file" class="custom-file-input" name="file" id="email-file" accept=".txt,.csv,text/plain,text/csv" required aria-describedby="email-file-help" style="border-radius: 10px;">
                            <label class="custom-file-label" for="email-file" style="border-radius: 10px; font-size: 12.5px;">Choose file...</label>
                        </div>
                        <small id="email-file-help" class="form-text text-muted mt-2">TXT or CSV. CSV may have a header row (email, first_name, last_name, country, age, sex/gender, vertical) and extra columns are preserved as tags. Imports run in resumable chunks; invalid optional ages are skipped and reported, not allowed to cancel valid rows. The selected type is saved on each recipient in this source list; importing does not create duplicate lists.</small>
                    </div>

                    <div class="form-group mb-3">
                        <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="email-type">
                            <span class="text-danger mr-1">*</span>Save To Segment Type:
                        </label>
                        <select id="email-type" name="type" class="form-control" required style="height: 42px; border-radius: 10px; font-size: 12.5px; font-weight: 600;">
                            <option value="">Select Segment Type...</option>
                            @foreach ($types as $type)
                                <option value="{{ $type }}" @selected(old('type') === $type)>{{ strtoupper($type) }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-group mb-4">
                        <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="email-filter">
                            Save Only Emails That Contain (Optional Keyword):
                        </label>
                        <input type="text" class="form-control" name="filter" id="email-filter" maxlength="255" placeholder="Filter emails by keyword..." value="{{ old('filter') }}" style="height: 42px; border-radius: 10px; font-size: 12.5px;">
                    </div>

                    <div class="d-flex justify-content-end pt-3 border-top" style="gap: 12px;">
                        <button id="upload-emails" type="submit" class="btn-command-primary" style="padding: 10px 24px;">
                            <i class="fas fa-upload mr-1"></i> UPLOAD EMAILS
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const form = document.querySelector('#list-email-upload');
            const button = document.querySelector('#upload-emails');
            const fileInput = document.querySelector('#email-file');
            const customLabel = fileInput?.nextElementSibling;

            fileInput?.addEventListener('change', () => {
                if (customLabel) customLabel.textContent = fileInput.files?.[0]?.name || 'Choose file...';
            });

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
                        ? `Import complete: ${data.imported_count.toLocaleString()} imported, ${data.duplicate_count.toLocaleString()} duplicates, ${data.invalid_count.toLocaleString()} invalid, ${data.profile_warning_count.toLocaleString()} optional profile values skipped.`
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
                if (!form.reportValidity()) return;
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
                    updateUploadProgress(0, message);
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
