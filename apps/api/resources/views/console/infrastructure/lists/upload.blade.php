@extends('layouts.dms-reference')

@section('title', 'Upload Emails - DMS')

@section('content')
    <div class="block block-rounded mb-0">
        <div class="block-header block-header-default">
            <h3 class="block-title">Upload Emails To List <span class="text-info">{{ $list->name }}</span></h3>
        </div>
        <div class="block-content">
            @if (session('info'))
                <div class="alert alert-info" role="status" aria-live="polite">{{ session('info') }}</div>
            @endif
            @if (session('import_batch_id'))
                <div id="list-import-progress" class="alert alert-secondary" role="status" aria-live="polite"
                    data-status-url="{{ route('console.infrastructure.lists.import-status', [$workspace->id, $list->id, session('import_batch_id')]) }}">
                    <span id="list-import-progress-text">Checking import status…</span>
                </div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger" role="alert" aria-live="assertive">
                    <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            <div class="row justify-content-center py-1">
                <div class="col-sm-10">
                    <form id="list-email-upload" enctype="multipart/form-data" action="{{ route('console.infrastructure.lists.upload.save', [$workspace->id, $list->id]) }}" method="post" novalidate>
                        @csrf
                        <div class="form-group">
                            <label for="email-file">Emails Csv File: <a href="{{ route('console.infrastructure.lists.import-example', $workspace->id) }}">DOWNLOAD EXAMPLE <i class="fas fa-download" aria-hidden="true"></i></a></label>
                            <div class="custom-file">
                                <input type="file" class="custom-file-input" name="file" id="email-file" accept=".txt,.csv,text/plain,text/csv" required aria-describedby="email-file-help @if($errors->has('file')) email-file-error @endif" @if($errors->has('file')) aria-invalid="true" @endif>
                                <label class="custom-file-label" for="email-file">Choose file</label>
                            </div>
                            <small id="email-file-help" class="form-text text-muted">TXT or CSV with no application file-size or row-count limit. Imports run in resumable background chunks. First column is the email address; optional columns are first name, last name, country, age, sex, and vertical.</small>
                            @error('file')<div id="email-file-error" class="text-danger" role="alert">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label for="email-type">Save To Type:</label>
                            <select id="email-type" name="type" class="form-control selectpicker" required @if($errors->has('type')) aria-describedby="email-type-error" aria-invalid="true" @endif>
                                <option value="">Select Type Upload</option>
                                @foreach ($types as $type)
                                    <option value="{{ $type }}" @selected(old('type') === $type)>{{ strtoupper($type) }}</option>
                                @endforeach
                            </select>
                            @error('type')<div id="email-type-error" class="text-danger" role="alert">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <label for="email-filter">Save Only Emails That Contain:</label>
                            <input type="text" class="form-control form-control-alt" name="filter" id="email-filter" maxlength="255" placeholder="Filter Emails By keyword ..." value="{{ old('filter') }}" @if($errors->has('filter')) aria-describedby="email-filter-error" aria-invalid="true" @endif>
                            @error('filter')<div id="email-filter-error" class="text-danger" role="alert">{{ $message }}</div>@enderror
                        </div>
                        <div class="form-group">
                            <button id="upload-emails" type="submit" class="btn btn-info btn-block">UPLOAD</button>
                        </div>
                    </form>
                </div>
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
                if (customLabel) customLabel.textContent = fileInput.files?.[0]?.name || 'Choose file';
            });

            form?.addEventListener('submit', () => {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
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
