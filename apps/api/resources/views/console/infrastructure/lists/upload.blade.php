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
                @if (session('import_batch_id'))
                    <div id="list-import-progress" class="alert alert-secondary py-2 px-3 mb-3 font-size-sm d-flex align-items-center" role="status" aria-live="polite" style="border-radius: 10px;"
                        data-status-url="{{ route('console.infrastructure.lists.import-status', [$workspace->id, $list->id, session('import_batch_id')]) }}">
                        <i class="fas fa-spinner fa-spin mr-2 text-purple"></i>
                        <span id="list-import-progress-text">Checking import status…</span>
                    </div>
                @endif
                @if ($errors->any())
                    <div class="alert alert-danger py-2 px-3 mb-3 font-size-sm" role="alert" aria-live="assertive" style="border-radius: 10px;">
                        <ul class="mb-0 pl-3">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                <form id="list-email-upload" enctype="multipart/form-data" action="{{ route('console.infrastructure.lists.upload.save', [$workspace->id, $list->id]) }}" method="post" novalidate>
                    @csrf
                    <div class="form-group mb-3">
                        <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1" for="email-file">
                            <span class="text-danger mr-1">*</span>Emails Source File (.txt, .csv):
                        </label>
                        <div class="custom-file">
                            <input type="file" class="custom-file-input" name="file" id="email-file" accept=".txt,.csv,text/plain,text/csv" required aria-describedby="email-file-help" style="border-radius: 10px;">
                            <label class="custom-file-label" for="email-file" style="border-radius: 10px; font-size: 12.5px;">Choose file...</label>
                        </div>
                        <small id="email-file-help" class="form-text text-muted mt-2">TXT or CSV with no application file-size or row-count limit. Imports run in resumable background chunks. First column is the email address; optional columns: first name, last name, country, age, sex, vertical.</small>
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

            form?.addEventListener('submit', () => {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> UPLOADING...';
            });

            const progress = document.querySelector('#list-import-progress');
            const progressText = document.querySelector('#list-import-progress-text');
            const statusUrl = progress?.dataset.statusUrl;
            if (progress && progressText && statusUrl) {
                const poll = async () => {
                    try {
                        const response = await fetch(statusUrl, { headers: { Accept: 'application/json' } });
                        if (!response.ok) throw new Error('status unavailable');
                        const { data } = await response.json();
                        progressText.textContent = data.status === 'completed'
                            ? `Import complete: ${data.imported_count.toLocaleString()} imported, ${data.duplicate_count.toLocaleString()} duplicates, ${data.invalid_count.toLocaleString()} invalid.`
                            : data.status === 'failed'
                                ? `Import failed: ${data.failure_message || 'Upload the source file again.'}`
                                : `${data.status === 'validating' ? 'Validating' : 'Importing'}… ${data.progress_percent}% — ${data.processed_count.toLocaleString()} processed, ${data.imported_count.toLocaleString()} imported.`;
                        if (['completed', 'failed'].includes(data.status)) return;
                    } catch (_) {
                        progressText.textContent = 'Import status is temporarily unavailable; the background import will continue.';
                    }
                    window.setTimeout(poll, 3000);
                };
                poll();
            }
        })();
    </script>
@endpush
