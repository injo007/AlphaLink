<?php

namespace App\Services\Telegram;

use App\Models\AuditEvent;
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
        ];
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

        // Fallback if user selected 'it' or 'main' and only the other exists when choosing default
        if (empty($targets) && $channelType === 'it' && $channelMain !== '') {
            $targets['main'] = $channelMain;
        }

        return $targets;
    }

    /**
     * @return array{success: bool, error: ?string}
     */
    private function postTelegramMessage(string $token, string $chatId, string $htmlMessage): array
    {
        try {
            $endpoint = "https://api.telegram.org/bot{$token}/sendMessage";
            $response = Http::timeout(10)->post($endpoint, [
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
