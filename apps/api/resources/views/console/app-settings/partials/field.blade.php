@php
    $errorKey = 'settings.' . $field;
    $inputValue = $secret ? '' : (!empty($readonly) ? ($settings[$field] ?? '') : old($errorKey, $settings[$field] ?? ''));
    $icons = [
        'app_name' => 'fas fa-cube',
        'optizmo_token' => 'fas fa-key',
        'tracking_ingest_key' => 'fas fa-fingerprint',
        'ip_api_key' => 'fas fa-globe',
        'offer_suppression_update' => 'fas fa-clock',
        'hash_token' => 'fas fa-hashtag',
        'dkim_key' => 'fas fa-file-signature',
        'extension_id' => 'fas fa-puzzle-piece',
        'campaign_expire' => 'fas fa-hourglass-half',
        'cloud_servers_password' => 'fas fa-lock',
        'app_redirect' => 'fas fa-external-link-alt',
        'bot_open' => 'fas fa-eye',
        'bot_click' => 'fas fa-mouse-pointer',
        'bot_unsubscribe' => 'fas fa-user-times',
        'pmta_port' => 'fas fa-plug',
        'pmta_admin_port' => 'fas fa-desktop',
        'proxy_port' => 'fas fa-random',
        'proxy_username' => 'fas fa-user',
        'proxy_password' => 'fas fa-lock',
        'move_emails_to_dormant_interval' => 'fas fa-calendar-alt',
        'webmail_username' => 'fas fa-envelope',
        'webmail_password' => 'fas fa-key',
        'backup_path' => 'fas fa-folder',
        'backup_server' => 'fas fa-server',
        'backup_username' => 'fas fa-user-shield',
        'backup_port' => 'fas fa-ethernet',
        'backup_password' => 'fas fa-lock',
        'telegram_bot_name' => 'fab fa-telegram-plane',
        'telegram_bot_token' => 'fas fa-robot',
        'telegram_channel' => 'fas fa-bullhorn',
        'telegram_channel_it' => 'fas fa-code-branch',
        'bitly_client_id' => 'fas fa-link',
        'bitly_secret_key' => 'fas fa-key',
        'imgur_client_id' => 'far fa-image',
        'imgur_secret_key' => 'fas fa-key',
        'ns_hn_api' => 'fas fa-key',
    ];
    $icon = $icons[$field] ?? 'fas fa-cog';
@endphp
<div class="form-group command-os-field-group">
    <label for="{{ $field }}">
        <span><i class="{{ $icon }} command-field-icon"></i> {{ $label }}:</span>
    </label>
    <div class="command-input-wrap">
        <span class="command-input-leading-icon"><i class="{{ $icon }}"></i></span>
        <input
            id="{{ $field }}"
            name="settings[{{ $field }}]"
            type="{{ $secret ? 'password' : ($type ?? 'text') }}"
            class="form-control command-input-with-icon"
            value="{{ $inputValue }}"
            @if (!empty($readonly)) readonly aria-readonly="true" @endif
            @if ($secret) autocomplete="new-password" @endif
            @if ($secret && ($secretConfigured[$field] ?? false) && ($canManageGeneralSettings ?? false)) data-secret-input="{{ $field }}" data-secret-url="{{ route('clean.console.app-settings.secret', ['field' => $field]) }}" @endif
            @if (!empty($placeholder) || ($secret && ($secretConfigured[$field] ?? false))) placeholder="{{ $secret && ($secretConfigured[$field] ?? false) ? 'Saved; leave blank to keep' : ($placeholder ?? '') }}" @endif
            @if ($errors->has($errorKey)) aria-invalid="true" aria-describedby="{{ $field }}-error" @endif
        >
    </div>
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
