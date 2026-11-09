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
    <div class="command-input-wrap">
        <span class="command-input-leading-icon"><i class="{{ $icon }}"></i></span>
        <select id="{{ $field }}" name="settings[{{ $field }}]" class="form-control selectpicker command-input-with-icon" data-width="100%" @if ($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $field }}-error" @endif>
            <option value="1" data-icon="fas fa-check-circle text-success" @selected($selected === '1')>YES &mdash; Active / Enabled</option>
            <option value="0" data-icon="fas fa-times-circle text-danger" @selected($selected === '0')>NO &mdash; Inactive / Disabled</option>
        </select>
    </div>
    @if ($errors->has($errorKey))
        <small class="text-danger" id="{{ $field }}-error">{{ $errors->first($errorKey) }}</small>
    @endif
</div>
