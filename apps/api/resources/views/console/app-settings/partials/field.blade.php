@php
    $errorKey = 'settings.' . $field;
    $inputValue = $secret ? '' : old($errorKey, $settings[$field] ?? '');
@endphp
<div class="form-group">
    <label for="{{ $field }}">{{ $label }}:</label>
    <input
        id="{{ $field }}"
        name="settings[{{ $field }}]"
        type="{{ $secret ? 'password' : ($type ?? 'text') }}"
        class="form-control"
        value="{{ $inputValue }}"
        @if ($secret) autocomplete="new-password" @endif
        @if (!empty($placeholder) || ($secret && ($secretConfigured[$field] ?? false))) placeholder="{{ $secret && ($secretConfigured[$field] ?? false) ? 'Saved; leave blank to keep' : ($placeholder ?? '') }}" @endif
        @if ($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $field }}-error" @endif
    >
    @if ($secret && ($secretConfigured[$field] ?? false))
        <small class="form-text text-muted">Saved encrypted. Leave blank to keep the current value.</small>
        @if ($secretStored[$field] ?? false)
            <div class="custom-control custom-checkbox mt-1">
                <input type="checkbox" class="custom-control-input" id="clear_{{ $field }}" name="settings_clear[{{ $field }}]" value="1">
                <label class="custom-control-label" for="clear_{{ $field }}">Clear saved value</label>
            </div>
        @endif
    @endif
    @if ($errors->has($errorKey))
        <small class="text-danger" id="{{ $field }}-error">{{ $errors->first($errorKey) }}</small>
    @endif
</div>
