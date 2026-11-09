@php
    $errorKey = 'settings.' . $field;
    $inputValue = $secret ? '' : (!empty($readonly) ? ($settings[$field] ?? '') : old($errorKey, $settings[$field] ?? ''));
@endphp
<div class="form-group command-os-field-group">
    <label for="{{ $field }}">{{ $label }}:</label>
    <input
        id="{{ $field }}"
        name="settings[{{ $field }}]"
        type="{{ $secret ? 'password' : ($type ?? 'text') }}"
        class="form-control"
        value="{{ $inputValue }}"
        @if (!empty($readonly)) readonly aria-readonly="true" @endif
        @if ($secret) autocomplete="new-password" @endif
        @if ($secret && ($secretConfigured[$field] ?? false) && ($canManageGeneralSettings ?? false)) data-secret-input="{{ $field }}" data-secret-url="{{ route('clean.console.app-settings.secret', ['field' => $field]) }}" @endif
        @if (!empty($placeholder) || ($secret && ($secretConfigured[$field] ?? false))) placeholder="{{ $secret && ($secretConfigured[$field] ?? false) ? 'Saved; leave blank to keep' : ($placeholder ?? '') }}" @endif
        @if ($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $field }}-error" @endif
    >
    @if ($secret && ($secretConfigured[$field] ?? false))
        <small class="form-text text-muted">Saved encrypted. Leave blank to keep the current value.</small>
        @if ($canManageGeneralSettings ?? false)
            <div class="btn-group btn-group-sm command-secret-actions" role="group" aria-label="{{ $label }} controls">
                <button type="button" class="btn btn-outline-secondary command-secret-btn" data-secret-reveal="{{ $field }}" aria-controls="{{ $field }}">REVEAL</button>
                <button type="button" class="btn btn-outline-secondary command-secret-btn d-none" data-secret-copy="{{ $field }}">COPY</button>
            </div>
            <small class="form-text text-danger d-none" data-secret-error="{{ $field }}" role="status"></small>
        @endif
        @if ($secretStored[$field] ?? false)
            <div class="custom-control custom-checkbox command-secret-clear-wrap">
                <input type="checkbox" class="custom-control-input" id="clear_{{ $field }}" name="settings_clear[{{ $field }}]" value="1">
                <label class="custom-control-label text-danger" for="clear_{{ $field }}">Clear saved value</label>
            </div>
        @endif
    @endif
    @if ($field === 'optizmo_token')
        <small class="form-text text-muted">Used by this workspace’s suppression downloads when an offer provides an Optizmo mailer access key.</small>
    @endif
    @if ($field === 'tracking_ingest_key')
        <small class="form-text text-muted">{{ ($secretConfigured[$field] ?? false) ? 'Generated automatically the first time TRACKING is selected during server installation. Use Reveal to view this workspace’s saved key.' : 'Not generated yet. AlphaLink creates and stores this key the first time TRACKING is selected during server installation.' }}</small>
    @endif
    @if ($field === 'app_redirect')
        <small class="form-text text-muted" id="app_redirect-policy-note">Unknown campaign redirect targets always use HTTPS and go to Google. This destination is fixed.</small>
    @endif
    @if ($errors->has($errorKey))
        <small class="text-danger" id="{{ $field }}-error">{{ $errors->first($errorKey) }}</small>
    @endif
</div>
