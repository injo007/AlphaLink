@php
    $selected = (string) old('settings.' . $field, (int) ($settings[$field] ?? false));
    $errorKey = 'settings.' . $field;
@endphp
<div class="form-group">
    <label for="{{ $field }}">{{ $label }}:</label>
    <select id="{{ $field }}" name="settings[{{ $field }}]" class="form-control" @if ($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $field }}-error" @endif>
        <option value="1" @selected($selected === '1')>YES</option>
        <option value="0" @selected($selected === '0')>NO</option>
    </select>
    @if ($errors->has($errorKey))
        <small class="text-danger" id="{{ $field }}-error">{{ $errors->first($errorKey) }}</small>
    @endif
</div>
