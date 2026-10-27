<?php

namespace App\Services\Infrastructure;

use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Resolves AlphaLink's installation settings and private assets before SSH mutation. */
class DmsBulkInstallRuntimeInputs
{
    public function __construct(private readonly DmsBulkInstallPrivateAssetReader $assets) {}

    /**
     * @param  array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     * @return array{settings: array<string, mixed>, assets: array<string, ?string>}
     */
    public function resolve(array $options, ?Workspace $workspace = null, ?int $pmtaVersion = null): array
    {
        $root = config('infrastructure.install_assets_directory');
        $selectedAssets = $options['tracking'] || $options['proxy'] || $options['imap'] || $options['dkim'];
        if ($selectedAssets && (! is_string($root) || $root === '')) {
            throw new RuntimeException('The AlphaLink installation asset directory is not configured.');
        }
        $assetRoot = is_string($root) ? $root : '';

        $configured = config('infrastructure.bulk_install_settings', []);
        if (! is_array($configured)) {
            throw new RuntimeException('The AlphaLink installation settings are unavailable.');
        }

        $saved = $workspace?->infrastructureSettings;
        $value = static function (string $key) use (&$saved, $configured): mixed {
            return $saved?->getAttribute($key) ?? ($configured[$key] ?? null);
        };

        $pmtaPort = $this->port($value('pmta_port') ?? config('infrastructure.pmta_port'));
        $adminPort = $this->port($value('pmta_admin_port'));
        $proxyPort = $this->port($value('proxy_port'));
        $applyFirewall = $this->boolean($value('apply_firewall'));
        $allowAllMonitor = $this->boolean($value('allow_all_monitor'));
        if ($adminPort === null || $proxyPort === null || $applyFirewall === null || $allowAllMonitor === null) {
            throw new RuntimeException('Required AlphaLink installer port and firewall settings are not configured.');
        }
        if ($pmtaPort === null) {
            throw new RuntimeException('The AlphaLink PMTA listener port is invalid.');
        }

        $devIps = $this->ipList($value('dev_ips'));
        $pmtaRelayIps = $this->ipList($value('pmta_relay_ips'));
        if ($pmtaVersion !== null && $pmtaRelayIps === []) {
            throw new RuntimeException('Configure PMTA RELAY SOURCE IPS with AlphaLink\'s stable public IPv4 egress address before installing or reinstalling PMTA.');
        }
        if ($devIps === [] && ! $allowAllMonitor) {
            throw new RuntimeException('Add an operator IPv4 address to DEV IPS for PMTA administrator access, or enable public monitor access before installing.');
        }

        $miniApp = $options['tracking'] ? $this->assets->read($assetRoot, 'install/mini_app.zip') : null;
        $cloudflareIps = $options['tracking'] ? $this->assets->read($assetRoot, 'install/cf_ips.conf', false) : null;
        $dovecot = $options['imap'] ? $this->assets->read($assetRoot, 'install/dovecot_centos.conf') : null;
        $squid = $options['proxy'] ? $this->assets->read($assetRoot, 'install/squid_centos.conf') : null;
        $dkim = $options['dkim'] ? json_decode($this->assets->read($assetRoot, 'install/dkim.json'), true) : null;
        if ($options['dkim'] && (! is_array($dkim) || ! is_string($dkim['value'] ?? null) || ! is_string($dkim['key'] ?? null))) {
            throw new RuntimeException('The selected AlphaLink DKIM asset is invalid.');
        }

        if ($workspace !== null && (($options['tracking'] && $this->string($value('mini_app_key')) === '')
            || ($options['imap'] && ($this->string($value('webmail_username')) === '' || $this->string($value('webmail_password')) === ''))
            || ($options['dkim'] && $this->string($value('dkim_key')) === ''))) {
            $saved = $this->provisionSelectedServiceValues($workspace, $options, $configured);
        }

        $miniAppKey = $this->string($value('mini_app_key'));
        $webmailUsername = $this->string($value('webmail_username'));
        $webmailPassword = $this->string($value('webmail_password'));
        $dkimSelector = $this->string($value('dkim_key'));
        if (($options['tracking'] && $miniAppKey === '')
            || ($options['imap'] && ($webmailUsername === '' || $webmailPassword === ''))
            || ($options['dkim'] && $dkimSelector === '')) {
            throw new RuntimeException('Required selected AlphaLink service credentials are not configured.');
        }

        $callbackUrl = config('infrastructure.tracking_callback_url') ?: config('app.url');
        if ($options['tracking'] && ! $this->publicTrackingCallbackUrl($callbackUrl)) {
            throw new RuntimeException('Tracking requires a public HTTPS AlphaLink callback URL reachable from the installed server.');
        }

        $assets = [
            'mini_app' => $miniApp,
            'cloudflare_ips' => $cloudflareIps,
            'dovecot_config' => $dovecot,
            'squid_config' => $squid,
        ];
        $domainConfigs = $saved?->pmta_domain_configs;
        if ($pmtaVersion !== null && is_array($domainConfigs) && is_string($domainConfigs[(string) $pmtaVersion] ?? null)) {
            $assets['pmta_domains_override'] = $domainConfigs[(string) $pmtaVersion];
        }

        return [
            'settings' => [
                'pmta_port' => $pmtaPort,
                'pmta_admin_port' => $adminPort,
                'proxy_port' => $proxyPort,
                'dev_ips' => $devIps,
                'pmta_relay_ips' => $pmtaRelayIps,
                'production_ips' => $this->ipList($value('production_ips')),
                'rdp_ips' => $this->ipList($value('rdp_ips')),
                'allow_all_monitor' => $allowAllMonitor,
                'apply_firewall' => $applyFirewall,
                'dkim_selector' => $dkimSelector,
                'dkim_key' => is_array($dkim) ? $dkim['value'] : '',
                'dkim_public_key' => is_array($dkim) ? $dkim['key'] : null,
                'imap_username' => $webmailUsername,
                'imap_password' => $webmailPassword,
                'proxy_username' => $this->string($value('proxy_username')),
                'proxy_password' => $this->string($value('proxy_password')),
                'mini_app_url' => (string) $callbackUrl,
                'mini_app_key' => $miniAppKey,
            ],
            'assets' => $assets,
        ];
    }

    /**
     * @param  array<string, bool>  $options
     * @param  array<string, mixed>  $configured
     */
    private function provisionSelectedServiceValues(Workspace $workspace, array $options, array $configured): WorkspaceInfrastructureSettings
    {
        return DB::transaction(function () use ($workspace, $options, $configured): WorkspaceInfrastructureSettings {
            Workspace::query()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            $settings = WorkspaceInfrastructureSettings::query()->firstOrCreate(['workspace_id' => $workspace->getKey()]);

            if ($options['tracking'] && $this->string($settings->mini_app_key ?? ($configured['mini_app_key'] ?? null)) === '') {
                $settings->mini_app_key = bin2hex(random_bytes(32));
            }
            if ($options['dkim'] && $this->string($settings->dkim_key ?? ($configured['dkim_key'] ?? null)) === '') {
                $settings->dkim_key = 'al'.bin2hex(random_bytes(8));
            }
            if ($options['imap'] && $this->string($settings->webmail_username ?? ($configured['webmail_username'] ?? null)) === '') {
                $settings->webmail_username = 'alimap'.bin2hex(random_bytes(8));
            }
            if ($options['imap'] && $this->string($settings->webmail_password ?? ($configured['webmail_password'] ?? null)) === '') {
                $settings->webmail_password = bin2hex(random_bytes(32));
            }

            if ($settings->isDirty()) {
                $settings->save();
            }

            return $settings;
        });
    }

    private function port(mixed $value): ?int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        return $port === false ? null : $port;
    }

    private function publicTrackingCallbackUrl(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? null) === 'https'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && $host !== ''
            && $host !== 'localhost'
            && ! str_ends_with($host, '.localhost')
            && (! filter_var($host, FILTER_VALIDATE_IP)
                || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
    }

    private function boolean(mixed $value): ?bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** @return list<string> */
    private function ipList(mixed $value): array
    {
        if (is_array($value)) {
            $ips = $value;
        } elseif (is_string($value) && trim($value) !== '') {
            $ips = array_map('trim', explode(',', $value));
        } else {
            return [];
        }

        return array_values(array_filter($ips, static fn (mixed $ip): bool => is_string($ip)
            && filter_var(trim($ip), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
    }
}
