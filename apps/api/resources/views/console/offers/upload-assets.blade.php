@extends('layouts.standalone')

@section('title', "Offer {$offer->id} Assets")

@section('content')
<div class="command-os-settings-card m-2 p-3 p-md-4">
    <header class="command-os-card-header mb-3">
        <div class="command-os-card-title">
            <span class="command-os-card-icon icon-purple" aria-hidden="true"><i class="fas fa-cloud-upload-alt"></i></span>
            <div>
                <h2>UPLOAD {{ strtoupper($type) }} ASSETS</h2>
                <p>Offer #{{ $offer->id }} &middot; {{ $offer->name }} (OID: {{ $offer->offer_id }})</p>
            </div>
        </div>
    </header>

    <form id="asset-upload-form" enctype="multipart/form-data" data-upload-url="{{ route('console.offers.assets.upload', [$workspace->id, $offer->id, $type]) }}">
        <div class="form-group mb-3">
            <label for="asset-files" class="font-size-xs font-w700 text-uppercase text-muted">
                <i class="fas fa-images text-purple mr-1"></i> {{ $type === 'creative' ? 'Creative Images' : 'Unsubscribe Image' }}
            </label>
            <div class="p-3 border rounded text-center" style="border-style: dashed !important; border-color: var(--cv-border-strong) !important; background: var(--cv-surface-2);">
                <i class="fas fa-file-image fa-2x text-purple mb-2"></i>
                <input class="form-control-file d-block mx-auto" type="file" id="asset-files" name="files[]" accept="image/jpeg,image/png,image/gif" @if ($type === 'creative') multiple @endif required>
                <small class="form-text text-muted mt-2">{{ $type === 'creative' ? "Maximum 20 images. " : 'Uploading replaces the previous unsubscribe image. ' }}JPEG, PNG or GIF; maximum 2 MB per file.</small>
            </div>
        </div>
        <div class="d-flex justify-content-end">
            <button class="btn-command-primary px-4 py-2 font-w700" type="submit" style="height: 38px; display: inline-flex; align-items: center; border-radius: 10px; font-size: 11.5px; letter-spacing: .06em;">
                <i class="fas fa-upload mr-2"></i>START UPLOAD
            </button>
        </div>
    </form>
    <div id="asset-upload-result" class="alert mt-3 d-none py-2 font-size-sm" role="status" aria-live="polite"></div>
</div>
@endsection

@push('scripts')
    <script>
        document.querySelector('#asset-upload-form').addEventListener('submit', async (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const input = document.querySelector('#asset-files');
            const result = document.querySelector('#asset-upload-result');
            const button = form.querySelector('button[type="submit"]');
            if (!input.files.length) return;
            const body = new FormData();
            [...input.files].forEach((file) => body.append('files[]', file));
            button.disabled = true;
            result.classList.add('d-none');
            try {
                const response = await fetch(form.dataset.uploadUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body,
                });
                const data = await response.json();
                if (!response.ok) throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Offer images could not be uploaded.');
                result.classList.remove('alert-danger');
                result.classList.add('alert-success');
                result.textContent = data.count === 1 ? 'Asset uploaded.' : `${data.count} assets uploaded.`;
                result.classList.remove('d-none');
                input.value = '';
                window.parent.closeAssetUpload?.();
            } catch (error) {
                result.classList.remove('alert-success');
                result.classList.add('alert-danger');
                result.textContent = error.message;
                result.classList.remove('d-none');
            } finally {
                button.disabled = false;
            }
        });
    </script>
@endpush
