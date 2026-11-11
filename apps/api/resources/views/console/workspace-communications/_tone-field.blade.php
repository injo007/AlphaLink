@php($selectedTone = $selectedTone ?? 'standard')
<fieldset class="workspace-comms-field workspace-comms-tone-field">
    <legend>Priority color</legend>
    <div class="workspace-comms-tone-options">
        @foreach (\App\Models\WorkspaceCommunication::TONE_LABELS as $tone => $label)
            <label class="workspace-comms-tone-option">
                <input type="radio" name="tone" value="{{ $tone }}" @checked($selectedTone === $tone) required>
                <span class="workspace-comms-tone-card tone-{{ $tone }}">
                    <span class="workspace-comms-tone-swatch" aria-hidden="true"></span>
                    <span>{{ $label }}</span>
                </span>
            </label>
        @endforeach
    </div>
    <small>Choose a labeled accent that appears with the item in the ticker, inbox, notification bell, and history.</small>
</fieldset>
