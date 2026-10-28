<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AppSettingsConsoleController extends Controller
{
    private const GENERIC_FIELDS = [
        'app_name',
        'optizmo_token',
        'ip_api_key',
        'offer_suppression_update',
        'hash_token',
        'extension_id',
        'campaign_expire',
        'app_ips',
        'app_redirect',
        'block_proxies',
        'filter_geo',
        'allowed_proxies',
        'blocked_open_traffic',
        'bot_open',
        'bot_click',
        'bot_unsubscribe',
        'move_emails_to_dormant',
        'move_emails_to_dormant_interval',
        'allow_backup',
        'backup_path',
        'backup_server',
        'backup_username',
        'backup_port',
        'backup_password',
        'telegram_bot_name',
        'telegram_bot_token',
        'telegram_channel',
        'telegram_channel_it',
        'bitly_client_id',
        'bitly_secret_key',
        'imgur_client_id',
        'imgur_secret_key',
        'ns_hn_api',
    ];

    private const INFRASTRUCTURE_FIELDS = [
        'pmta_port',
        'pmta_admin_port',
        'proxy_port',
        'apply_firewall',
        'allow_all_monitor',
        'dev_ips',
        'pmta_relay_ips',
        'production_ips',
        'rdp_ips',
        'dkim_key',
        'webmail_username',
        'webmail_password',
        'proxy_username',
        'proxy_password',
        'mini_app_key',
        'cloud_servers_password',
    ];

    private const GENERIC_SECRET_FIELDS = [
        'optizmo_token',
        'ip_api_key',
        'hash_token',
        'backup_password',
        'telegram_bot_token',
        'bitly_client_id',
        'bitly_secret_key',
        'imgur_client_id',
        'imgur_secret_key',
        'ns_hn_api',
    ];

    private const INFRASTRUCTURE_SECRET_FIELDS = [
        'dkim_key',
        'webmail_username',
        'webmail_password',
        'proxy_username',
        'proxy_password',
        'mini_app_key',
        'cloud_servers_password',
    ];

    private const MULTI_VALUE_FIELDS = [
        'app_ips',
        'allowed_proxies',
        'blocked_open_traffic',
        'dev_ips',
        'pmta_relay_ips',
        'production_ips',
        'rdp_ips',
    ];

    private const BOOLEAN_FIELDS = [
        'block_proxies',
        'filter_geo',
        'move_emails_to_dormant',
        'allow_backup',
        'apply_firewall',
        'allow_all_monitor',
    ];

    public function index(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, true);
        $canManageGeneralSettings = in_array($workspace->pivot->role, ['owner', 'admin'], true);
        $genericSettings = $workspace->appSettings?->settings ?? [];
        $infrastructureSettings = $workspace->infrastructureSettings;
        $configured = config('infrastructure.bulk_install_settings', []);
        $configured = is_array($configured) ? $configured : [];
        $setting = fn (string $key, mixed $fallback = null): mixed => $infrastructureSettings?->getAttribute($key)
            ?? ($configured[$key] ?? $fallback);

        $settings = [
            'app_name' => $genericSettings['app_name'] ?? '',
            'optizmo_token' => '',
            'mini_app_key' => '',
            'ip_api_key' => '',
            'offer_suppression_update' => $genericSettings['offer_suppression_update'] ?? 1,
            'hash_token' => '',
            'dkim_key' => '',
            'extension_id' => $genericSettings['extension_id'] ?? '',
            'campaign_expire' => $genericSettings['campaign_expire'] ?? 2,
            'cloud_servers_password' => '',
            'apply_firewall' => filter_var($setting('apply_firewall', false), FILTER_VALIDATE_BOOLEAN),
            'dev_ips' => $this->arrayValue($setting('dev_ips', [])),
            'pmta_relay_ips' => $this->arrayValue($setting('pmta_relay_ips', [])),
            'production_ips' => $this->arrayValue($setting('production_ips', [])),
            'rdp_ips' => $this->arrayValue($setting('rdp_ips', [])),
            'app_ips' => $this->arrayValue($genericSettings['app_ips'] ?? []),
            'app_redirect' => $genericSettings['app_redirect'] ?? 'https://www.google.com',
            'block_proxies' => $genericSettings['block_proxies'] ?? false,
            'filter_geo' => $genericSettings['filter_geo'] ?? true,
            'allowed_proxies' => $this->arrayValue($genericSettings['allowed_proxies'] ?? []),
            'blocked_open_traffic' => $this->arrayValue($genericSettings['blocked_open_traffic'] ?? []),
            'pmta_port' => $infrastructureSettings?->pmta_port ?? config('infrastructure.pmta_port', 1212),
            'pmta_admin_port' => $setting('pmta_admin_port', 1213),
            'allow_all_monitor' => filter_var($setting('allow_all_monitor', false), FILTER_VALIDATE_BOOLEAN),
            'proxy_port' => $setting('proxy_port', 1214),
            'proxy_username' => '',
            'proxy_password' => '',
            'bot_open' => $genericSettings['bot_open'] ?? 20,
            'bot_click' => $genericSettings['bot_click'] ?? 5,
            'bot_unsubscribe' => $genericSettings['bot_unsubscribe'] ?? 5,
            'move_emails_to_dormant' => $genericSettings['move_emails_to_dormant'] ?? false,
            'move_emails_to_dormant_interval' => $genericSettings['move_emails_to_dormant_interval'] ?? 3,
            'webmail_username' => '',
            'webmail_password' => '',
            'allow_backup' => $genericSettings['allow_backup'] ?? false,
            // DMS's settings view currently hard-codes this visible value to /home.
            'backup_path' => '/home',
            'backup_server' => $genericSettings['backup_server'] ?? '',
            'backup_username' => $genericSettings['backup_username'] ?? '',
            'backup_port' => $genericSettings['backup_port'] ?? '',
            'backup_password' => '',
            'telegram_bot_name' => $genericSettings['telegram_bot_name'] ?? '',
            'telegram_bot_token' => '',
            'telegram_channel' => $genericSettings['telegram_channel'] ?? '',
            'telegram_channel_it' => $genericSettings['telegram_channel_it'] ?? '',
            'bitly_client_id' => '',
            'bitly_secret_key' => '',
            'imgur_client_id' => '',
            'imgur_secret_key' => '',
            'ns_hn_api' => '',
        ];

        $secretConfigured = [];
        $secretStored = [];
        foreach (self::GENERIC_SECRET_FIELDS as $field) {
            $secretStored[$field] = filled($genericSettings[$field] ?? null);
            $fallbackConfigured = $field === 'optizmo_token' && filled(config('services.optizmo.token'));
            $secretConfigured[$field] = $secretStored[$field] || $fallbackConfigured;
        }
        foreach (self::INFRASTRUCTURE_SECRET_FIELDS as $field) {
            $secretStored[$field] = filled($infrastructureSettings?->getAttribute($field));
            $secretConfigured[$field] = $secretStored[$field] || filled($configured[$field] ?? null);
        }

        return view('console.app-settings.index', compact('workspace', 'settings', 'secretConfigured', 'secretStored', 'canManageGeneralSettings'));
    }

    public function revealSecret(Request $request, string $workspaceId, string $field): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        abort_unless(in_array($field, [...self::GENERIC_SECRET_FIELDS, ...self::INFRASTRUCTURE_SECRET_FIELDS], true), 404);

        if (in_array($field, self::GENERIC_SECRET_FIELDS, true)) {
            $settings = $workspace->appSettings?->settings ?? [];
            $value = is_array($settings) ? ($settings[$field] ?? null) : null;
            if ($field === 'optizmo_token' && blank($value)) {
                $value = config('services.optizmo.token');
            }
        } else {
            $value = $workspace->infrastructureSettings?->getAttribute($field)
                ?? config('infrastructure.bulk_install_settings.'.$field);
        }

        abort_unless(is_string($value) && $value !== '', 404, 'No saved value exists for this setting.');

        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => 'workspace.app_setting_secret_revealed',
            'subject_type' => 'workspace_app_setting_secret',
            'subject_id' => (string) $workspace->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => ['field' => $field],
            'occurred_at' => now(),
        ]);

        return response()->json(['field' => $field, 'value' => $value])
            ->header('Cache-Control', 'private, no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
    }

    public function update(Request $request, string $workspaceId): RedirectResponse
    {
        return $this->save($request, $workspaceId, false);
    }

    public function updateInstallSettings(Request $request, string $workspaceId): RedirectResponse
    {
        $legacyFields = Arr::only($request->all(), self::INFRASTRUCTURE_FIELDS);
        $nestedFields = $request->input('settings', []);
        if (is_array($nestedFields)) {
            $legacyFields = array_replace($legacyFields, Arr::only($nestedFields, self::INFRASTRUCTURE_FIELDS));
        }
        if (array_key_exists('cloud_servers_password', $request->all())) {
            $legacyFields['cloud_servers_password'] = $request->input('cloud_servers_password');
        }

        $request->merge([
            'settings' => $legacyFields,
            'settings_present' => ['dev_ips' => '1', 'pmta_relay_ips' => '1', 'production_ips' => '1', 'rdp_ips' => '1'],
        ]);

        return $this->save($request, $workspaceId, true);
    }

    private function save(Request $request, string $workspaceId, bool $installationOnly): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, $installationOnly);
        $allowedFields = [...self::GENERIC_FIELDS, ...self::INFRASTRUCTURE_FIELDS];
        $providedSettings = $request->input('settings');
        if (! is_array($providedSettings)) {
            $providedSettings = Arr::only($request->all(), $allowedFields);
        }

        $request->merge(['settings' => $providedSettings]);
        $rules = $this->validationRules($allowedFields);
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $this->removeSecretsBeforeFlashing($request);
            throw new ValidationException($validator);
        }

        $input = $validator->validated();
        $values = $input['settings'] ?? [];
        $clearSecrets = $input['settings_clear'] ?? [];
        $presentFields = $input['settings_present'] ?? [];
        $genericModel = $workspace->appSettings()->firstOrNew();
        $genericSettings = $genericModel->settings ?? [];
        $genericFields = $installationOnly ? [] : self::GENERIC_FIELDS;
        $genericFieldsChanged = [];
        $genericSecretsUpdated = [];
        $genericSecretsCleared = [];

        foreach ($genericFields as $field) {
            if (in_array($field, self::MULTI_VALUE_FIELDS, true) && isset($presentFields[$field]) && ! array_key_exists($field, $values)) {
                $values[$field] = [];
            }

            if (! array_key_exists($field, $values)) {
                continue;
            }

            if (in_array($field, self::GENERIC_SECRET_FIELDS, true)) {
                if ($this->toBoolean($clearSecrets[$field] ?? false)) {
                    $genericSettings[$field] = '';
                    $genericFieldsChanged[] = $field;
                    $genericSecretsCleared[] = $field;
                } elseif (filled($values[$field])) {
                    $genericSettings[$field] = $values[$field];
                    $genericFieldsChanged[] = $field;
                    $genericSecretsUpdated[] = $field;
                }

                continue;
            }

            $genericSettings[$field] = $this->normalizeGenericValue($field, $values[$field]);
            $genericFieldsChanged[] = $field;
        }

        $infrastructure = $workspace->infrastructureSettings()->firstOrNew();
        $infrastructure->workspace_id = $workspace->getKey();
        $previousPmtaRelayIps = $infrastructure->pmta_relay_ips ?? [];
        $infrastructureFieldsChanged = [];
        foreach (['pmta_port', 'pmta_admin_port', 'proxy_port'] as $field) {
            if (array_key_exists($field, $values) && $values[$field] !== null && $values[$field] !== '') {
                $infrastructure->{$field} = (int) $values[$field];
                $infrastructureFieldsChanged[] = $field;
            }
        }
        foreach (['apply_firewall', 'allow_all_monitor'] as $field) {
            if (array_key_exists($field, $values)) {
                $infrastructure->{$field} = $this->toBoolean($values[$field]);
                $infrastructureFieldsChanged[] = $field;
            }
        }
        foreach (['dev_ips', 'pmta_relay_ips', 'production_ips', 'rdp_ips'] as $field) {
            if (isset($presentFields[$field]) && ! array_key_exists($field, $values)) {
                $values[$field] = [];
            }
            if (array_key_exists($field, $values)) {
                $infrastructure->{$field} = $this->ipv4Values($values[$field]);
                $infrastructureFieldsChanged[] = $field;
            }
        }
        if (array_key_exists('pmta_relay_ips', $values)
            && $previousPmtaRelayIps !== $this->ipv4Values($values['pmta_relay_ips'])) {
            // Changing the field explicitly opts out of automatic host-egress refresh.
            $infrastructure->pmta_relay_ips_auto_managed = false;
        }

        $secretFieldsUpdated = [];
        $secretFieldsCleared = [];
        foreach (self::INFRASTRUCTURE_SECRET_FIELDS as $field) {
            if ($this->toBoolean($clearSecrets[$field] ?? false)) {
                $infrastructure->{$field} = null;
                $secretFieldsCleared[] = $field;
            } elseif (filled($values[$field] ?? null)) {
                $infrastructure->{$field} = $values[$field];
                $secretFieldsUpdated[] = $field;
            }
        }

        $infrastructure->save();
        if ($genericFieldsChanged !== [] || $genericModel->exists || ! $installationOnly) {
            $genericModel->workspace_id = $workspace->getKey();
            $genericModel->settings = $genericSettings;
            $genericModel->save();
        }

        $secretFieldsUpdated = array_values(array_unique([...$secretFieldsUpdated, ...$genericSecretsUpdated]));
        $secretFieldsCleared = array_values(array_unique([...$secretFieldsCleared, ...$genericSecretsCleared]));
        $fieldsChanged = array_values(array_unique([...$genericFieldsChanged, ...$infrastructureFieldsChanged]));

        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $installationOnly ? 'infrastructure.install_settings_updated' : 'workspace.app_settings_updated',
            'subject_type' => $installationOnly ? 'workspace_install_settings' : 'workspace_app_settings',
            'subject_id' => (string) ($installationOnly ? $infrastructure->getKey() : ($genericModel->getKey() ?? $infrastructure->getKey())),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => [
                'fields' => array_values(array_diff($fieldsChanged, [...self::GENERIC_SECRET_FIELDS, ...self::INFRASTRUCTURE_SECRET_FIELDS])),
                'secrets_updated' => $secretFieldsUpdated,
                'secrets_cleared' => $secretFieldsCleared,
            ],
            'occurred_at' => now(),
        ]);

        $message = $installationOnly ? 'Installation settings saved.' : 'Settings saved.';

        return redirect(route('console.app-settings.index', $workspace->getKey()).'#settings')->with('status', $message);
    }

    /** @param  list<string>  $allowedFields
     * @return array<string, array<int, mixed>>
     */
    private function validationRules(array $allowedFields): array
    {
        $rules = [
            'settings' => ['sometimes', 'array:'.implode(',', $allowedFields)],
            'settings_clear' => ['sometimes', 'array:'.implode(',', [...self::GENERIC_SECRET_FIELDS, ...self::INFRASTRUCTURE_SECRET_FIELDS])],
            'settings_present' => ['sometimes', 'array:'.implode(',', self::MULTI_VALUE_FIELDS)],
        ];

        foreach (['pmta_port', 'pmta_admin_port', 'proxy_port'] as $field) {
            $rules['settings.'.$field] = ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'];
        }
        foreach (['apply_firewall', 'allow_all_monitor', ...array_intersect(self::BOOLEAN_FIELDS, self::GENERIC_FIELDS)] as $field) {
            $rules['settings.'.$field] = ['sometimes', 'boolean'];
        }

        $stringLimits = [
            'app_name' => 255,
            'optizmo_token' => 4096,
            'mini_app_key' => 4096,
            'ip_api_key' => 4096,
            'hash_token' => 4096,
            'dkim_key' => 255,
            'extension_id' => 255,
            'cloud_servers_password' => 4096,
            'app_redirect' => 2048,
            'proxy_username' => 255,
            'proxy_password' => 4096,
            'webmail_username' => 255,
            'webmail_password' => 4096,
            'backup_path' => 2048,
            'backup_server' => 255,
            'backup_username' => 255,
            'backup_password' => 4096,
            'telegram_bot_name' => 255,
            'telegram_bot_token' => 4096,
            'telegram_channel' => 255,
            'telegram_channel_it' => 255,
            'bitly_client_id' => 4096,
            'bitly_secret_key' => 4096,
            'imgur_client_id' => 4096,
            'imgur_secret_key' => 4096,
            'ns_hn_api' => 4096,
        ];
        foreach ($stringLimits as $field => $max) {
            $rules['settings.'.$field] = ['sometimes', 'nullable', 'string', 'max:'.$max];
        }

        foreach ([
            'offer_suppression_update' => ['min:0', 'max:100000'],
            'campaign_expire' => ['min:0', 'max:3650'],
            'bot_open' => ['min:0', 'max:100000'],
            'bot_click' => ['min:0', 'max:100000'],
            'bot_unsubscribe' => ['min:0', 'max:100000'],
            'move_emails_to_dormant_interval' => ['min:1', 'max:120'],
        ] as $field => $bounds) {
            $rules['settings.'.$field] = ['sometimes', 'nullable', 'integer', ...$bounds];
        }
        $rules['settings.backup_port'] = ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'];

        foreach (self::MULTI_VALUE_FIELDS as $field) {
            $rules['settings.'.$field] = ['sometimes', 'array', 'max:128'];
            $rules['settings.'.$field.'.*'] = ['string', 'max:'.(in_array($field, ['dev_ips', 'pmta_relay_ips', 'production_ips', 'rdp_ips'], true) ? '45' : '255')];
            $rules['settings_present.'.$field] = ['sometimes', 'boolean'];
        }
        foreach ([...self::GENERIC_SECRET_FIELDS, ...self::INFRASTRUCTURE_SECRET_FIELDS] as $field) {
            $rules['settings_clear.'.$field] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    private function normalizeGenericValue(string $field, mixed $value): mixed
    {
        if (in_array($field, self::BOOLEAN_FIELDS, true)) {
            return $this->toBoolean($value);
        }
        if (in_array($field, ['offer_suppression_update', 'campaign_expire', 'bot_open', 'bot_click', 'bot_unsubscribe', 'move_emails_to_dormant_interval', 'backup_port'], true)) {
            return $value === null || $value === '' ? null : (int) $value;
        }
        if (in_array($field, self::MULTI_VALUE_FIELDS, true)) {
            return $this->arrayValue($value);
        }

        return $value;
    }

    private function toBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** @return list<string> */
    private function ipv4Values(mixed $values): array
    {
        return array_values(array_filter(
            $this->arrayValue($values),
            static fn (string $value): bool => filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        ));
    }

    /** @return list<string> */
    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($item): bool => is_string($item) && $item !== ''));
        }

        if (is_string($value) && trim($value) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
        }

        return [];
    }

    private function removeSecretsBeforeFlashing(Request $request): void
    {
        $input = $request->all();
        $secrets = [...self::GENERIC_SECRET_FIELDS, ...self::INFRASTRUCTURE_SECRET_FIELDS];
        if (isset($input['settings']) && is_array($input['settings'])) {
            $input['settings'] = Arr::except($input['settings'], $secrets);
        }
        $input = Arr::except($input, $secrets);
        unset($input['settings_clear']);
        $request->replace($input);
    }

    private function workspaceFor(User $user, string $workspaceId, bool $allowWorkspaceMember = false): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();

        if (! $allowWorkspaceMember) {
            abort_unless(in_array($workspace->pivot->role, ['owner', 'admin'], true), 403);
        }

        return $workspace;
    }
}
