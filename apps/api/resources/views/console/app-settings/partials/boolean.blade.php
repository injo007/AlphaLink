@php
    $selected = (string) old('settings.' . $field, (int) ($settings[$field] ?? false));
    $errorKey = 'settings.' . $field;
    $icons = [
        'apply_firewall' => 'fas fa-shield-alt',
        'block_proxies' => 'fas fa-user-shield',
        'filter_geo' => 'fas fa-globe-americas',
        'allow_all_monitor' => 'fas fa-users',
        'move_emails_to_dormant' => 'fas fa-archive',
        'allow_backup' => 'fas fa-database',
    ];
    $icon = $icons[$field] ?? 'fas fa-toggle-on';
@endphp
<div class="form-group command-os-field-group">
    <label for="{{ $field }}">
        <span><i class="{{ $icon }} command-field-icon"></i> {{ $label }}:</span>
    </label>
    <div class="command-os-seg-toggle" data-toggle-field="{{ $field }}">
        <input type="hidden" id="{{ $field }}" name="settings[{{ $field }}]" value="{{ $selected }}" @if ($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $field }}-error" @endif>
        <div class="command-os-seg-track">
            <button type="button" class="command-os-seg-btn is-yes {{ $selected === '1' ? 'is-active' : '' }}" data-val="1">
                <i class="fas fa-check-circle mr-1"></i> YES &bull; ACTIVE
            </button>
            <button type="button" class="command-os-seg-btn is-no {{ $selected === '0' ? 'is-active' : '' }}" data-val="0">
                <i class="fas fa-times-circle mr-1"></i> NO &bull; INACTIVE
            </button>
        </div>
    </div>
    @if ($errors->has($errorKey))
        <small class="text-danger" id="{{ $field }}-error">{{ $errors->first($errorKey) }}</small>
    @endif
</div>
