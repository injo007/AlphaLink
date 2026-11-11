<?php

namespace App\Services\Telegram;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAppSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TelegramAlertService
{
    public const DEFAULT_TEMPLATE = "🚨 <b>[{app_name}] {level} Alert ({module})</b>\n\n{custom_note}\n\n<b>Issue:</b> <code>{summary}</code>\n<b>Origin:</b> <code>{file}</code>\n<b>Incidents:</b> {occurrences}x\n<b>Request ID:</b> <code>{request_id}</code>";

    /**
     * Supported operational event alert definitions and default templates.
     *
     * @var array<string, array<string, mixed>>
     */
    public const EVENT_DEFINITIONS = [
        'login' => [
            'label' => 'User Login Alerts',
            'icon' => 'fas fa-sign-in-alt',
            'description' => 'Sent immediately when an operator, admin, or owner signs in to AlphaLink.',
            'default_enabled' => true,
            'default_channel' => 'main',
            'default_template' => "🔐 <b>[{app_name}] User Sign-In</b>\n\n<b>User:</b> {user_name} ({user_email})\n<b>Role:</b> {role}\n<b>IP Address:</b> <code>{ip}</code>\n<b>Workspace:</b> {workspace}\n<b>Time:</b> <code>{timestamp}</code>",
            'placeholders' => ['{app_name}', '{workspace}', '{user_name}', '{user_email}', '{username}', '{role}', '{ip}', '{timestamp}'],
        ],
        'lead' => [
            'label' => 'New Lead Conversions',
            'icon' => 'fas fa-dollar-sign',
            'description' => 'Sent when affiliate sponsor conversions attribute a new campaign or drop lead.',
            'default_enabled' => true,
            'default_channel' => 'main',
            'default_template' => "💰 <b>[{app_name}] New Lead Recorded</b>\n\n<b>Sponsor:</b> {sponsor}\n<b>Offer:</b> {offer}\n<b>Source:</b> {source} ({source_type})\n<b>Payout:</b> <b>{payout} {currency}</b>\n<b>Country:</b> {country}\n<b>Conversion ID:</b> <code>{conversion_id}</code>\n<b>Time:</b> <code>{timestamp}</code>",
            'placeholders' => ['{app_name}', '{workspace}', '{sponsor}', '{offer}', '{source}', '{source_type}', '{payout}', '{currency}', '{country}', '{conversion_id}', '{ip}', '{timestamp}'],
        ],
        'backup' => [
            'label' => 'Workspace Backup Events',
            'icon' => 'fas fa-database',
            'description' => 'Sent after scheduled or manual workspace database and snapshot backups complete or fail.',
            'default_enabled' => true,
            'default_channel' => 'it',
            'default_template' => "💾 <b>[{app_name}] Backup {status}</b>\n\n<b>Workspace:</b> {workspace}\n<b>Archive:</b> <code>{filename}</code>\n<b>Size:</b> {size}\n<b>Destination:</b> {destination}\n<b>Trigger:</b> {triggered_by}\n<b>Details:</b> {details}\n<b>Time:</b> <code>{timestamp}</code>",
            'placeholders' => ['{app_name}', '{workspace}', '{status}', '{filename}', '{size}', '{destination}', '{triggered_by}', '{details}', '{timestamp}'],
        ],
        'offer_status' => [
            'label' => 'Offer Status Changes',
            'icon' => 'fas fa-tags',
            'description' => 'Sent when an offer changes status (e.g., live ↔ paused) manually or via sponsor feed sync.',
            'default_enabled' => true,
            'default_channel' => 'main',
            'default_template' => "📢 <b>[{app_name}] Offer Status Changed</b>\n\n<b>Offer:</b> #{offer_id} — {offer_name}\n<b>Sponsor:</b> {sponsor}\n<b>Status:</b> <code>{old_status}</code> → <b>{new_status}</b>\n<b>Actor:</b> {actor}\n<b>Reason:</b> {reason}\n<b>Time:</b> <code>{timestamp}</code>",
            'placeholders' => ['{app_name}', '{workspace}', '{offer_id}', '{offer_name}', '{sponsor}', '{old_status}', '{new_status}', '{actor}', '{reason}', '{timestamp}'],
        ],
    ];

    /**
     * Get sanitized Telegram configuration status for the workspace UI (NEVER leaks bot token).
     *
     * @return array<string, mixed>
     */
    public function getConfigForWorkspace(Workspace $workspace): array
    {
        $raw = $workspace->appSettings?->settings ?? [];
        $hasToken = is_array($raw) && filled($raw['telegram_bot_token'] ?? null);
        $channelMain = is_array($raw) ? trim((string) ($raw['telegram_channel'] ?? '')) : '';
        $channelIt = is_array($raw) ? trim((string) ($raw['telegram_channel_it'] ?? '')) : '';
        $botName = is_array($raw) ? trim((string) ($raw['telegram_bot_name'] ?? '')) : '';

        $rules = is_array($raw['error_alert_rules'] ?? null) ? $raw['error_alert_rules'] : [];
        $rawEventRules = is_array($raw['event_alert_rules'] ?? null) ? $raw['event_alert_rules'] : [];

        $eventRules = [];
        foreach (self::EVENT_DEFINITIONS as $eventKey => $def) {
            $savedRule = is_array($rawEventRules[$eventKey] ?? null) ? $rawEventRules[$eventKey] : [];
            $eventRules[$eventKey] = [
                'key' => $eventKey,
                'label' => $def['label'],
                'icon' => $def['icon'],
                'description' => $def['description'],
                'enabled' => array_key_exists('enabled', $savedRule)
                    ? filter_var($savedRule['enabled'], FILTER_VALIDATE_BOOLEAN)
                    : (bool) $def['default_enabled'],
                'target_channel' => in_array($savedRule['target_channel'] ?? $def['default_channel'], ['it', 'main', 'both'], true)
                    ? (string) ($savedRule['target_channel'] ?? $def['default_channel'])
                    : $def['default_channel'],
                'custom_template' => trim((string) ($savedRule['custom_template'] ?? '')) ?: $def['default_template'],
                'default_template' => $def['default_template'],
                'placeholders' => $def['placeholders'],
            ];
        }

        return [
            'has_token' => $hasToken,
            'bot_name' => $botName,
            'has_main_channel' => $channelMain !== '',
            'has_it_channel' => $channelIt !== '',
            'channel_main_masked' => $this->maskChannel($channelMain),
            'channel_it_masked' => $this->maskChannel($channelIt),
            'ready' => $hasToken && ($channelMain !== '' || $channelIt !== ''),
            'rules' => [
                'enabled' => (bool) ($rules['enabled'] ?? false),
                'target_channel' => in_array($rules['target_channel'] ?? 'it', ['it', 'main', 'both'], true) ? ($rules['target_channel'] ?? 'it') : 'it',
                'severity_threshold' => in_array($rules['severity_threshold'] ?? 'CRITICAL', ['CRITICAL', 'ERROR'], true) ? ($rules['severity_threshold'] ?? 'CRITICAL') : 'CRITICAL',
                'cooldown_minutes' => max(1, min(1440, (int) ($rules['cooldown_minutes'] ?? 30))),
                'custom_template' => trim((string) ($rules['custom_template'] ?? '')) ?: self::DEFAULT_TEMPLATE,
                'custom_prefix' => trim((string) ($rules['custom_prefix'] ?? '')),
            ],
            'event_rules' => $eventRules,
        ];
    }

    /**
     * Save event alert toggles and custom per-event templates safely in WorkspaceAppSettings.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveEventRules(Workspace $workspace, array $input): array
    {
        $existing = $workspace->appSettings?->settings;
        if (! is_array($existing)) {
            $existing = [];
        }

        $savedEvents = is_array($existing['event_alert_rules'] ?? null) ? $existing['event_alert_rules'] : [];

        foreach (self::EVENT_DEFINITIONS as $eventKey => $def) {
            if (! array_key_exists($eventKey, $input) || ! is_array($input[$eventKey])) {
                continue;
            }

            $entry = $input[$eventKey];
            $channel = (string) ($entry['target_channel'] ?? ($savedEvents[$eventKey]['target_channel'] ?? $def['default_channel']));
            if (! in_array($channel, ['main', 'it', 'both'], true)) {
                $channel = $def['default_channel'];
            }

            $template = trim((string) ($entry['custom_template'] ?? ''));
            $savedEvents[$eventKey] = [
                'enabled' => filter_var($entry['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'target_channel' => $channel,
                'custom_template' => Str::limit($template !== '' ? $template : $def['default_template'], 1500, ''),
            ];
        }

        $existing['event_alert_rules'] = $savedEvents;

        WorkspaceAppSettings::query()->updateOrCreate(
            ['workspace_id' => $workspace->getKey()],
            ['settings' => $existing]
        );

        $workspace->unsetRelation('appSettings');

        return $this->getConfigForWorkspace($workspace);
    }

    /**
     * Update automated alert rules & custom message template safely inside WorkspaceAppSettings.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function saveRules(Workspace $workspace, array $input): array
    {
        $existing = $workspace->appSettings?->settings;
        if (! is_array($existing)) {
            $existing = [];
        }

        $rules = [
            'enabled' => (bool) ($input['enabled'] ?? false),
            'target_channel' => in_array($input['target_channel'] ?? 'it', ['it', 'main', 'both'], true) ? ($input['target_channel'] ?? 'it') : 'it',
            'severity_threshold' => in_array($input['severity_threshold'] ?? 'CRITICAL', ['CRITICAL', 'ERROR'], true) ? ($input['severity_threshold'] ?? 'CRITICAL') : 'CRITICAL',
            'cooldown_minutes' => max(1, min(1440, (int) ($input['cooldown_minutes'] ?? 30))),
            'custom_template' => Str::limit(trim((string) ($input['custom_template'] ?? '')) ?: self::DEFAULT_TEMPLATE, 1500),
            'custom_prefix' => Str::limit(trim((string) ($input['custom_prefix'] ?? '')), 255),
        ];

        $existing['error_alert_rules'] = $rules;

        WorkspaceAppSettings::query()->updateOrCreate(
            ['workspace_id' => $workspace->getKey()],
            ['settings' => $existing]
        );

        $workspace->unsetRelation('appSettings');

        return $this->getConfigForWorkspace($workspace);
    }

    /**
     * Dispatch a custom per-error Telegram alert written by an operator/admin.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, sent_channels: list<string>, message_preview: string, error: ?string}
     */
    public function sendCustomAlert(Workspace $workspace, array $payload, ?int $actorUserId = null, ?string $requestId = null): array
    {
        $raw = $workspace->appSettings?->settings ?? [];
        $token = is_array($raw) ? trim((string) ($raw['telegram_bot_token'] ?? '')) : '';

        if ($token === '') {
            return [
                'success' => false,
                'sent_channels' => [],
                'message_preview' => '',
                'error' => 'Telegram Bot Token is not configured. Add it in Workspace Settings first.',
            ];
        }

        $channelType = $payload['channel_type'] ?? 'it';
        $targetChatIds = $this->resolveChatIds($raw, $channelType);

        if (empty($targetChatIds)) {
            return [
                'success' => false,
                'sent_channels' => [],
                'message_preview' => '',
                'error' => 'Selected Telegram Channel ID is empty. Configure Channel IDs in Workspace Settings.',
            ];
        }

        $formattedMessage = $this->buildSafeAlertMessage($workspace, $payload);

        $sentChannels = [];
        $errors = [];

        foreach ($targetChatIds as $label => $chatId) {
            $res = $this->postTelegramMessage($token, $chatId, $formattedMessage);
            if ($res['success']) {
                $sentChannels[] = $label;
            } else {
                $errors[] = "{$label}: {$res['error']}";
            }
        }

        if (! empty($sentChannels)) {
            try {
                AuditEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'actor_user_id' => $actorUserId,
                    'action' => 'workspace.telegram_error_alert_sent',
                    'subject_type' => 'error_intelligence',
                    'subject_id' => (string) ($payload['fingerprint'] ?? $workspace->getKey()),
                    'request_id' => $requestId,
                    'metadata' => [
                        'channels' => $sentChannels,
                        'level' => strtoupper((string) ($payload['level'] ?? 'ERROR')),
                        'module' => strtolower((string) ($payload['module'] ?? 'system')),
                    ],
                ]);
            } catch (Throwable) {}
        }

        return [
            'success' => ! empty($sentChannels),
            'sent_channels' => $sentChannels,
            'message_preview' => strip_tags($formattedMessage),
            'error' => empty($errors) ? null : implode('; ', $errors),
        ];
    }

    /**
     * Send a test connectivity ping to verify Bot Token & Channel ID.
     *
     * @return array{success: bool, sent_channels: list<string>, error: ?string}
     */
    public function sendTestPing(Workspace $workspace, string $channelType = 'it'): array
    {
        $raw = $workspace->appSettings?->settings ?? [];
        $token = is_array($raw) ? trim((string) ($raw['telegram_bot_token'] ?? '')) : '';

        if ($token === '') {
            return [
                'success' => false,
                'sent_channels' => [],
                'error' => 'Telegram Bot Token is missing in Settings.',
            ];
        }

        $targetChatIds = $this->resolveChatIds($raw, $channelType);
        if (empty($targetChatIds)) {
            return [
                'success' => false,
                'sent_channels' => [],
                'error' => 'Target Telegram Channel ID is missing in Settings.',
            ];
        }

        $appName = $this->escapeHtml((string) config('app.name', 'AlphaLink'));
        $wsName = $this->escapeHtml($workspace->name);
        $time = now()->format('Y-m-d H:i:s T');

        $message = "✅ <b>[{$appName}] Telegram Alert Connectivity Verified</b>\n\n"
            ."Workspace: <b>{$wsName}</b>\n"
            ."Timestamp: <code>{$time}</code>\n"
            ."Security: PII &amp; Credential Redaction Active.";

        $sentChannels = [];
        $errors = [];

        foreach ($targetChatIds as $label => $chatId) {
            $res = $this->postTelegramMessage($token, $chatId, $message);
            if ($res['success']) {
                $sentChannels[] = $label;
            } else {
                $errors[] = "{$label}: {$res['error']}";
            }
        }

        return [
            'success' => ! empty($sentChannels),
            'sent_channels' => $sentChannels,
            'error' => empty($errors) ? null : implode('; ', $errors),
        ];
    }

    /**
     * Automated rate-limited threshold dispatch (with strict cooldown per fingerprint).
     *
     * @param  array<string, mixed>  $errorRecord
     */
    public function dispatchAutomatedAlertIfEligible(Workspace $workspace, array $errorRecord): bool
    {
        $config = $this->getConfigForWorkspace($workspace);
        if (! $config['ready'] || ! ($config['rules']['enabled'] ?? false)) {
            return false;
        }

        $level = strtoupper((string) ($errorRecord['level'] ?? 'ERROR'));
        $threshold = $config['rules']['severity_threshold'] ?? 'CRITICAL';

        if ($threshold === 'CRITICAL' && ! in_array($level, ['CRITICAL', 'ALERT', 'EMERGENCY', 'FATAL'], true)) {
            return false;
        }

        $fingerprint = (string) ($errorRecord['fingerprint'] ?? md5($level.($errorRecord['summary'] ?? '')));
        $cooldown = max(1, (int) ($config['rules']['cooldown_minutes'] ?? 30));
        $lockKey = "telegram_alert_cooldown:{$workspace->getKey()}:{$fingerprint}";

        if (! Cache::add($lockKey, now()->timestamp, now()->addMinutes($cooldown))) {
            return false; // Rate-limited during cooldown window
        }

        $template = $config['rules']['custom_template'] ?? self::DEFAULT_TEMPLATE;
        $prefix = $config['rules']['custom_prefix'] ?? '';

        $res = $this->sendCustomAlert($workspace, [
            'channel_type' => $config['rules']['target_channel'] ?? 'it',
            'custom_message' => $prefix !== '' ? $prefix : 'Automated threshold alert triggered.',
            'custom_template' => $template,
            'error_summary' => $errorRecord['summary'] ?? $errorRecord['message'] ?? 'Application Error',
            'level' => $level,
            'module' => $errorRecord['module'] ?? 'system',
            'file' => $errorRecord['file'] ?? 'laravel.log',
            'request_id' => $errorRecord['request_id'] ?? 'N/A',
            'occurrences_count' => $errorRecord['occurrences_count'] ?? 1,
            'fingerprint' => $fingerprint,
            'include_summary' => true,
            'include_request_id' => true,
            'include_link' => true,
        ]);

        return $res['success'];
    }

    /**
     * Format safe HTML payload for Telegram with PII/secret sanitization.
     *
     * @param  array<string, mixed>  $payload
     */
    public function buildSafeAlertMessage(Workspace $workspace, array $payload): string
    {
        $appName = $this->escapeHtml((string) config('app.name', 'AlphaLink'));
        $level = $this->escapeHtml(strtoupper((string) ($payload['level'] ?? 'ERROR')));
        $module = $this->escapeHtml(strtoupper((string) ($payload['module'] ?? 'SYSTEM')));
        $customNoteRaw = trim((string) ($payload['custom_message'] ?? ''));
        $customNote = $customNoteRaw !== '' ? $this->escapeHtml($this->sanitizeText($customNoteRaw, 800)) : '';

        $summary = $this->escapeHtml($this->sanitizeText((string) ($payload['error_summary'] ?? 'Runtime exception recorded'), 240));
        $file = $this->escapeHtml($this->sanitizeText((string) ($payload['file'] ?? 'laravel.log'), 120));
        $reqId = $this->escapeHtml($this->sanitizeText((string) ($payload['request_id'] ?? 'None'), 64));
        $occurrences = max(1, (int) ($payload['occurrences_count'] ?? 1));

        // If custom template passed explicitly (automated rule)
        if (! empty($payload['custom_template'])) {
            $rendered = strtr((string) $payload['custom_template'], [
                '{app_name}' => $appName,
                '{level}' => $level,
                '{module}' => $module,
                '{summary}' => $summary,
                '{occurrences}' => (string) $occurrences,
                '{file}' => $file,
                '{request_id}' => $reqId,
                '{custom_note}' => $customNote !== '' ? "💬 <b>Note:</b> {$customNote}" : '',
            ]);

            if (! empty($payload['include_link'])) {
                $url = route('console.miscellaneous.app-errors', ['workspace' => $workspace->getKey()]);
                $rendered .= "\n\n🔗 <a href=\"{$url}\">Open Error Center</a>";
            }

            return trim($rendered);
        }

        // Standard operator on-demand alert composition
        $lines = [];
        $icon = in_array($level, ['CRITICAL', 'ALERT', 'EMERGENCY', 'FATAL'], true) ? '🚨' : '⚠️';
        $lines[] = "{$icon} <b>[{$appName}] {$level} — {$module}</b>";

        if ($customNote !== '') {
            $lines[] = '';
            $lines[] = "💬 <b>Operator Note:</b>\n{$customNote}";
        }

        if (! isset($payload['include_summary']) || filter_var($payload['include_summary'], FILTER_VALIDATE_BOOLEAN)) {
            $lines[] = '';
            $lines[] = "<b>Issue:</b> <code>{$summary}</code>";
            $lines[] = "<b>Origin:</b> <code>{$file}</code>";
            if ($occurrences > 1) {
                $lines[] = "<b>Occurrences:</b> {$occurrences}x";
            }
        }

        if (! empty($payload['include_request_id']) && $reqId !== '' && $reqId !== 'None') {
            $lines[] = "<b>Correlation ID:</b> <code>{$reqId}</code>";
        }

        if (! isset($payload['include_link']) || filter_var($payload['include_link'], FILTER_VALIDATE_BOOLEAN)) {
            $url = route('console.miscellaneous.app-errors', ['workspace' => $workspace->getKey()]);
            $lines[] = '';
            $lines[] = "🔗 <a href=\"{$url}\">Inspect in Error Intelligence Center</a>";
        }

        return implode("\n", $lines);
    }

    /**
     * Dispatch a formatted operational event alert (login, lead, backup, offer_status) for a workspace.
     *
     * @param  array<string, mixed>  $context
     * @return array{success: bool, sent_channels: list<string>, skipped: bool, error: ?string}
     */
    public function notifyEvent(
        Workspace $workspace,
        string $eventType,
        array $context = [],
        ?int $actorUserId = null,
        bool $ignoreDisabled = false,
        ?string $overrideTemplate = null,
        ?string $overrideChannel = null,
    ): array {
        $def = self::EVENT_DEFINITIONS[$eventType] ?? null;
        if ($def === null) {
            return ['success' => false, 'sent_channels' => [], 'skipped' => true, 'error' => 'Unknown Telegram event type.'];
        }

        $config = $this->getConfigForWorkspace($workspace);
        $rule = $config['event_rules'][$eventType] ?? [];

        if (! $ignoreDisabled && empty($rule['enabled'])) {
            return ['success' => false, 'sent_channels' => [], 'skipped' => true, 'error' => null];
        }

        $raw = $workspace->appSettings?->settings ?? [];
        $token = is_array($raw) ? trim((string) ($raw['telegram_bot_token'] ?? '')) : '';
        if ($token === '') {
            return ['success' => false, 'sent_channels' => [], 'skipped' => true, 'error' => 'Telegram Bot Token is not configured in Settings.'];
        }

        $channelType = $overrideChannel && in_array($overrideChannel, ['main', 'it', 'both'], true)
            ? $overrideChannel
            : (string) ($rule['target_channel'] ?? $def['default_channel']);
        $targetChatIds = $this->resolveChatIds($raw, $channelType);

        if (empty($targetChatIds)) {
            return ['success' => false, 'sent_channels' => [], 'skipped' => true, 'error' => 'Telegram Channel ID is not configured in Settings.'];
        }

        $template = trim((string) ($overrideTemplate ?? '')) ?: (string) ($rule['custom_template'] ?? $def['default_template']);
        $formatted = $this->buildEventMessage($workspace, $eventType, $template, $context);

        $sentChannels = [];
        $errors = [];

        foreach ($targetChatIds as $label => $chatId) {
            $res = $this->postTelegramMessage($token, $chatId, $formatted);
            if ($res['success']) {
                $sentChannels[] = $label;
            } else {
                $errors[] = "{$label}: {$res['error']}";
            }
        }

        try {
            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $actorUserId,
                'action' => ! empty($sentChannels) ? 'workspace.telegram_event_sent' : 'workspace.telegram_event_failed',
                'subject_type' => 'telegram_event',
                'subject_id' => $eventType,
                'occurred_at' => now(),
                'properties' => [
                    'event' => $eventType,
                    'channels' => $sentChannels,
                    'error' => empty($errors) ? null : implode('; ', $errors),
                ],
            ]);
        } catch (Throwable) {
        }

        return [
            'success' => ! empty($sentChannels),
            'sent_channels' => $sentChannels,
            'skipped' => false,
            'error' => empty($errors) ? null : implode('; ', $errors),
        ];
    }

    /**
     * Send a synthetic test preview notification for one of the 4 operational event types.
     *
     * @return array{success: bool, sent_channels: list<string>, skipped: bool, error: ?string}
     */
    public function sendTestEvent(Workspace $workspace, string $eventType, ?string $overrideTemplate = null, ?string $overrideChannel = null): array
    {
        $samples = [
            'login' => [
                'user_name' => 'AlphaLink Operator',
                'user_email' => 'operator@example.test',
                'username' => 'operator',
                'role' => 'owner',
                'ip' => '203.0.113.10',
            ],
            'lead' => [
                'sponsor' => 'Demo Affiliate Network',
                'offer' => '#1042 — Sample Offer',
                'source' => 'Autumn Warmup Campaign',
                'source_type' => 'Campaign',
                'payout' => '45.00',
                'currency' => 'USD',
                'country' => 'United States',
                'conversion_id' => 'CONV-TEST-001',
                'ip' => '198.51.100.25',
            ],
            'backup' => [
                'status' => 'Completed',
                'filename' => 'alphalink-backup-test.json.gz',
                'size' => '1.42 MB',
                'destination' => 'Local Private Archive',
                'triggered_by' => 'Manual Test',
                'details' => 'Verified snapshot archive created cleanly.',
            ],
            'offer_status' => [
                'offer_id' => '1042',
                'offer_name' => 'Sample CPA Offer',
                'sponsor' => 'Demo Affiliate Network',
                'old_status' => 'live',
                'new_status' => 'paused',
                'actor' => 'System Test',
                'reason' => 'Manual test alert preview',
            ],
        ];

        return $this->notifyEvent(
            $workspace,
            $eventType,
            $samples[$eventType] ?? [],
            null,
            ignoreDisabled: true,
            overrideTemplate: $overrideTemplate,
            overrideChannel: $overrideChannel,
        );
    }

    /**
     * Dispatch login Telegram notifications across the user's Telegram-configured workspaces (deduplicated per chat destination).
     *
     * @return array{sent: int, workspaces: int}
     */
    public function notifyLogin(User $user, ?string $ipAddress = null): array
    {
        $workspaces = $user->workspaces()->with('appSettings')->get();
        if ($workspaces->isEmpty() && $user->hasFullAccess()) {
            $workspaces = Workspace::query()->with('appSettings')->orderBy('id')->get();
        }

        $dispatchedHashes = [];
        $sent = 0;

        foreach ($workspaces as $workspace) {
            if (! $workspace instanceof Workspace) {
                continue;
            }

            $raw = $workspace->appSettings?->settings ?? [];
            $token = is_array($raw) ? trim((string) ($raw['telegram_bot_token'] ?? '')) : '';
            if ($token === '') {
                continue;
            }

            $config = $this->getConfigForWorkspace($workspace);
            $rule = $config['event_rules']['login'] ?? [];
            if (empty($rule['enabled'])) {
                continue;
            }

            $chatIds = $this->resolveChatIds($raw, (string) ($rule['target_channel'] ?? 'main'));
            if (empty($chatIds)) {
                continue;
            }

            $destHash = hash('sha256', $token.'|'.implode(',', array_values($chatIds)));
            if (isset($dispatchedHashes[$destHash])) {
                continue;
            }
            $dispatchedHashes[$destHash] = true;

            $result = $this->notifyEvent($workspace, 'login', [
                'user_name' => $user->name ?: $user->username ?: 'User',
                'user_email' => $user->email ?: 'N/A',
                'username' => $user->username ?: $user->email ?: 'user',
                'role' => strtoupper((string) ($user->access_role ?: $user->type ?: 'member')),
                'ip' => $ipAddress ?: 'unknown',
            ], $user->getKey());

            if ($result['success']) {
                $sent++;
            }
        }

        return ['sent' => $sent, 'workspaces' => count($dispatchedHashes)];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function buildEventMessage(Workspace $workspace, string $eventType, string $template, array $context): string
    {
        $configuredAppName = trim((string) ($workspace->appSettings?->settings['app_name'] ?? ''));
        $appName = $configuredAppName !== '' ? $configuredAppName : (string) config('app.name', 'AlphaLink');
        $timestamp = now()->format('Y-m-d H:i:s T');

        $replacements = [
            '{app_name}' => $this->escapeHtml($this->redactSecretsOnly($appName, 80)),
            '{workspace}' => $this->escapeHtml($this->redactSecretsOnly($workspace->name, 120)),
            '{timestamp}' => $this->escapeHtml($timestamp),
        ];

        $placeholders = self::EVENT_DEFINITIONS[$eventType]['placeholders'] ?? [];
        foreach ($placeholders as $placeholder) {
            $key = trim($placeholder, '{}');
            if (array_key_exists($placeholder, $replacements)) {
                continue;
            }
            $rawVal = (string) ($context[$key] ?? 'N/A');
            $replacements[$placeholder] = $this->escapeHtml($this->redactSecretsOnly($rawVal, 400));
        }

        return trim(strtr($template, $replacements));
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string>
     */
    private function resolveChatIds(array $raw, string $channelType): array
    {
        $channelMain = trim((string) ($raw['telegram_channel'] ?? ''));
        $channelIt = trim((string) ($raw['telegram_channel_it'] ?? ''));

        $targets = [];
        if ($channelType === 'it' || $channelType === 'both') {
            if ($channelIt !== '') {
                $targets['it'] = $channelIt;
            }
        }
        if ($channelType === 'main' || $channelType === 'both') {
            if ($channelMain !== '') {
                $targets['main'] = $channelMain;
            }
        }

        // Smart fallback when only one of Main / IT Channel ID is configured in Settings
        if (empty($targets)) {
            if ($channelMain !== '') {
                $targets['main'] = $channelMain;
            } elseif ($channelIt !== '') {
                $targets['it'] = $channelIt;
            }
        }

        // Deduplicate identical chat IDs if main and IT point to the same channel
        $unique = [];
        $seenChatIds = [];
        foreach ($targets as $label => $chatId) {
            if (! isset($seenChatIds[$chatId])) {
                $seenChatIds[$chatId] = true;
                $unique[$label] = $chatId;
            }
        }

        return $unique;
    }

    /**
     * @return array{success: bool, error: ?string}
     */
    private function postTelegramMessage(string $token, string $chatId, string $htmlMessage): array
    {
        try {
            $endpoint = "https://api.telegram.org/bot{$token}/sendMessage";
            $response = Http::timeout(4)->post($endpoint, [
                'chat_id' => $chatId,
                'text' => $htmlMessage,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            if ($response->successful()) {
                $body = $response->json();
                if (! empty($body['ok'])) {
                    return ['success' => true, 'error' => null];
                }
            }

            $desc = $response->json('description') ?? "HTTP {$response->status()}";
            return ['success' => false, 'error' => $this->sanitizeText((string) $desc, 200)];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $this->sanitizeText($e->getMessage(), 200)];
        }
    }

    /**
     * Redact secrets/tokens/credentials for operational notifications while keeping operational metadata (IP, email, offer) readable.
     */
    private function redactSecretsOnly(string $text, int $limit = 400): string
    {
        $text = preg_replace('/\bBearer\s+\S+/i', 'Bearer [redacted]', $text) ?? $text;
        $text = preg_replace('/\b(password|passwd|secret|token|authorization|api[_-]?key|bot\d+:[A-Za-z0-9_-]+)\b(\s*[=:]\s*)([^\s,;]+)/i', '$1$2[redacted]', $text) ?? $text;
        $text = preg_replace('/bot\d+:[A-Za-z0-9_-]{10,}/i', '[bot_token_redacted]', $text) ?? $text;

        return Str::limit(trim($text), $limit);
    }

    /**
     * Strictly redact tokens, emails, IPs, URLs, and SQL before external transmission.
     */
    private function sanitizeText(string $text, int $limit = 240): string
    {
        $text = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $text) ?? $text;
        $text = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[ip]', $text) ?? $text;
        $text = preg_replace('/\bBearer\s+\S+/i', 'Bearer [redacted]', $text) ?? $text;
        $text = preg_replace('/\b(password|passwd|secret|token|authorization|api[_-]?key|bot\d+:[A-Za-z0-9_-]+)\b(\s*[=:]\s*)([^\s,;]+)/i', '$1$2[redacted]', $text) ?? $text;
        $text = preg_replace('/bot\d+:[A-Za-z0-9_-]{10,}/i', '[bot_token_redacted]', $text) ?? $text;
        $text = preg_replace('/\bSQL:\s*.*$/i', 'SQL: [redacted]', $text) ?? $text;
        $text = preg_replace('/https?:\/\/[^\s"\']+/i', '[url]', $text) ?? $text;

        return Str::limit(trim($text), $limit);
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function maskChannel(string $channel): string
    {
        if ($channel === '') {
            return '';
        }
        $len = strlen($channel);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($channel, 0, 3) . str_repeat('*', max(2, $len - 6)) . substr($channel, -3);
    }
}
