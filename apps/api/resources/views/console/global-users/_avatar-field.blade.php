@php
    $avatarLabel = trim((string) ($avatarUser?->name ?: $avatarUser?->username ?: 'User'));
    $avatarInitials = mb_strtoupper(mb_substr(preg_replace('/\s+/', '', $avatarLabel) ?: 'U', 0, 2));
@endphp

<section class="global-user-photo-editor" aria-labelledby="global-user-photo-title">
    <div class="global-user-photo-preview" aria-hidden="true">
        <img id="global-user-photo-image" class="{{ $avatarUser?->avatar_path ? '' : 'd-none' }}" @if ($avatarUser?->avatar_path) src="{{ route('console.global-users.avatar', $avatarUser->id) }}" @endif alt="">
        <span id="global-user-photo-initials" class="{{ $avatarUser?->avatar_path ? 'd-none' : '' }}">{{ $avatarInitials }}</span>
    </div>
    <div class="global-user-photo-content">
        <h4 id="global-user-photo-title">Profile image</h4>
        <p id="global-user-photo-help">JPG, PNG, or WebP · square images work best · maximum size 5 MB.</p>
        <div class="global-user-photo-actions">
            <input class="global-user-photo-input" id="global-user-photo-input" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" aria-describedby="global-user-photo-help global-user-photo-status">
            <label class="btn btn-sm btn-outline-info mb-0" for="global-user-photo-input"><i class="fas fa-image mr-1" aria-hidden="true"></i>Choose image</label>
            <button class="btn btn-sm btn-light d-none" id="global-user-photo-clear" type="button">Clear selection</button>
            @if ($avatarUser?->avatar_path)
                <label class="global-user-photo-remove mb-0"><input type="checkbox" name="remove_avatar" value="1" id="remove-user-photo"> Remove current image</label>
            @endif
        </div>
        <p class="global-user-photo-status mb-0" id="global-user-photo-status" role="status" aria-live="polite">{{ $avatarUser?->avatar_path ? 'Current profile image' : 'No image selected' }}</p>
    </div>
</section>

@push('styles')
    <style>
        .global-user-photo-editor { align-items: center; background: var(--co-surface, #f8f9fc); border: 1px solid var(--co-border, #dfe4f1); border-radius: 12px; display: flex; gap: 16px; margin: 0 0 1.25rem; min-width: 0; padding: 16px; }
        .global-user-photo-preview { align-items: center; background: linear-gradient(145deg, #9147ff, #397fff); border: 1px solid rgba(255,255,255,.7); border-radius: 50%; box-shadow: 0 5px 14px rgba(110,69,229,.2); color: #fff; display: flex; flex: 0 0 72px; font-size: 21px; font-weight: 800; height: 72px; justify-content: center; overflow: hidden; width: 72px; }
        .global-user-photo-preview img { height: 100%; object-fit: cover; width: 100%; }
        .global-user-photo-content { min-width: 0; }
        .global-user-photo-content h4 { color: var(--co-ink, #1f2852); font-size: 13px; font-weight: 750; margin: 0 0 4px; }
        .global-user-photo-content > p:not(.global-user-photo-status) { color: var(--co-muted, #66708f); font-size: 12px; margin: 0 0 9px; }
        .global-user-photo-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; }
        .global-user-photo-input { height: 1px; opacity: 0; overflow: hidden; position: absolute; width: 1px; }
        .global-user-photo-input:focus-visible + label { box-shadow: 0 0 0 3px rgba(112,72,255,.25); outline: 2px solid #7048ff; outline-offset: 2px; }
        .global-user-photo-remove { align-items: center; color: var(--co-muted, #66708f); display: inline-flex; font-size: 12px; gap: 6px; }
        .global-user-photo-remove input { accent-color: #7048ff; }
        .global-user-photo-status { color: var(--co-muted, #66708f); font-size: 11px; margin-top: 7px; overflow-wrap: anywhere; }
        @media (max-width: 575.98px) { .global-user-photo-editor { align-items: flex-start; gap: 12px; padding: 13px; } .global-user-photo-preview { flex-basis: 60px; height: 60px; width: 60px; } }
    </style>
@endpush

@push('scripts')
    <script>
        (() => {
            const input = document.getElementById('global-user-photo-input');
            const image = document.getElementById('global-user-photo-image');
            const initials = document.getElementById('global-user-photo-initials');
            const status = document.getElementById('global-user-photo-status');
            const clear = document.getElementById('global-user-photo-clear');
            const remove = document.getElementById('remove-user-photo');
            if (!input || !image || !initials || !status || !clear) return;

            const savedSource = image.getAttribute('src');
            let previewSource = null;
            const clearPreview = () => {
                if (previewSource) URL.revokeObjectURL(previewSource);
                previewSource = null;
                input.value = '';
                clear.classList.add('d-none');
                if (savedSource) {
                    image.src = savedSource;
                    image.classList.remove('d-none');
                    initials.classList.add('d-none');
                } else {
                    image.removeAttribute('src');
                    image.classList.add('d-none');
                    initials.classList.remove('d-none');
                }
                status.textContent = remove?.checked ? 'Image will be removed when changes are saved' : (savedSource ? 'Current profile image' : 'No image selected');
            };

            input.addEventListener('change', () => {
                const file = input.files?.[0];
                if (!file) return;
                const extension = file.name.split('.').pop()?.toLowerCase();
                if (!['jpg', 'jpeg', 'png', 'webp'].includes(extension) || file.size === 0 || file.size > 5 * 1024 * 1024) {
                    status.textContent = 'Choose a JPG, PNG, or WebP image up to 5 MB.';
                    input.value = '';
                    return;
                }
                if (previewSource) URL.revokeObjectURL(previewSource);
                previewSource = URL.createObjectURL(file);
                image.src = previewSource;
                image.classList.remove('d-none');
                initials.classList.add('d-none');
                clear.classList.remove('d-none');
                if (remove) remove.checked = false;
                status.textContent = file.name + ' · ' + Math.max(1, Math.round(file.size / 1024)) + ' KB';
            });
            clear.addEventListener('click', clearPreview);
            remove?.addEventListener('change', () => {
                if (!input.files?.length) status.textContent = remove.checked ? 'Image will be removed when changes are saved' : (savedSource ? 'Current profile image' : 'No image selected');
            });
        })();
    </script>
@endpush
