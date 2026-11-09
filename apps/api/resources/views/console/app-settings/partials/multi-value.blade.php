@php
    $selectedValues = old('settings.' . $field, $settings[$field] ?? []);
    if (!is_array($selectedValues)) {
        $selectedValues = is_string($selectedValues) && trim($selectedValues) !== ''
            ? array_map('trim', explode(',', $selectedValues))
            : [];
    }
    $errorKey = 'settings.' . $field;
    $icons = [
        'dev_ips' => 'fas fa-laptop-code',
        'pmta_relay_ips' => 'fas fa-server',
        'production_ips' => 'fas fa-network-wired',
        'rdp_ips' => 'fas fa-desktop',
        'app_ips' => 'fas fa-check-circle',
        'allowed_proxies' => 'fas fa-filter',
        'blocked_open_traffic' => 'fas fa-ban',
    ];
    $icon = $icons[$field] ?? 'fas fa-tags';
@endphp
<div class="form-group command-os-field-group">
    <label for="{{ $field }}">
        <span><i class="{{ $icon }} command-field-icon"></i> {{ $label }}:</span>
    </label>
    <select id="{{ $field }}" name="settings[{{ $field }}][]" class="form-control app-settings-tag-list" multiple>
        @foreach ($selectedValues as $value)
            <option value="{{ $value }}" selected>{{ $value }}</option>
        @endforeach
    </select>
    <input type="hidden" name="settings_present[{{ $field }}]" value="1">
    @if ($errors->has($errorKey))
        <small class="text-danger" id="{{ $field }}-error">{{ $errors->first($errorKey) }}</small>
    @endif
</div>
