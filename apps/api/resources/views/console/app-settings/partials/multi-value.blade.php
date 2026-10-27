@php
    $selectedValues = old('settings.' . $field, $settings[$field] ?? []);
    if (!is_array($selectedValues)) {
        $selectedValues = is_string($selectedValues) && trim($selectedValues) !== ''
            ? array_map('trim', explode(',', $selectedValues))
            : [];
    }
    $errorKey = 'settings.' . $field;
@endphp
<div class="form-group">
    <label for="{{ $field }}">{{ $label }}:</label>
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
