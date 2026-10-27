@extends('layouts.dms-reference')

@section('title', "Offer {$offer->id} Assets")

@section('content')
    <div class="block block-rounded">
        <div class="block-header block-header-default">
            <h3 class="block-title text-uppercase">UPLOAD {{ $type }} ASSETS FOR OFFER <span class="text-info">{{ $offer->id }} | {{ $offer->offer_id }} | {{ $offer->name }}</span></h3>
        </div>
        <div class="block-content">
            <form id="asset-upload-form" enctype="multipart/form-data" data-upload-url="{{ route('console.offers.assets.upload', [$workspace->id, $offer->id, $type]) }}">
                <div class="form-group">
                    <label for="asset-files">{{ $type === 'creative' ? 'Creative images' : 'Unsubscribe image' }}</label>
                    <input class="form-control-file" type="file" id="asset-files" name="files[]" accept="image/jpeg,image/png,image/gif" @if ($type === 'creative') multiple @endif required>
                    <small class="form-text text-muted">{{ $type === 'creative' ? "Maximum 20 images. " : 'Uploading replaces the previous unsubscribe image. ' }}JPEG, PNG or GIF; maximum 2 MB per file.</small>
                </div>
                <div class="d-flex justify-content-end">
                    <button class="btn btn-primary" type="submit">UPLOAD</button>
                </div>
            </form>
            <div id="asset-upload-result" class="alert mt-3 d-none" role="status" aria-live="polite"></div>
        </div>
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
