<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

/** Builds a fail-closed, diagnostic post-install check for PMTA and its public monitor. */
class DmsPmtaPostInstallReadiness
{
    /** @param array<string, mixed> $settings */
    public function command(int $version, array $settings, bool $https = false, ?string $domain = null): string
    {
        if (! in_array($version, [40, 45, 50], true)) {
            throw new InvalidArgumentException('Select a supported PMTA version.');
        }

        $publicPort = $settings['pmta_admin_port'] ?? null;
        if (! is_int($publicPort) || $publicPort < 1 || $publicPort > 32767) {
            throw new InvalidArgumentException('The PMTA monitor port is invalid.');
        }
        if ($https && (! is_string($domain) || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            throw new InvalidArgumentException('A valid PMTA monitor hostname is required for HTTPS readiness.');
        }

        $backendPort = $publicPort * 2;
        $relayIps = $settings['pmta_relay_ips'] ?? [];
        $developmentIps = $settings['dev_ips'] ?? [];
        $productionIps = $settings['production_ips'] ?? [];
        $allowAllMonitor = $settings['allow_all_monitor'] ?? false;
        if (! is_array($relayIps) || ! is_array($developmentIps) || ! is_array($productionIps) || ! is_bool($allowAllMonitor)) {
            throw new InvalidArgumentException('The PMTA monitor readiness settings are invalid.');
        }

        $checks = [
            ['rpm-package', ['rpm', '-q', 'PowerMTA']],
            ['pmta-service', ['systemctl', 'is-active', '--quiet', 'pmta']],
            ['pmta-http-service', ['systemctl', 'is-active', '--quiet', 'pmtahttp']],
            ['pmta-backend-listener', ['sh', '-c', $this->listenCheck($backendPort)]],
            ['pmta-local-admin-policy', ['grep', '-Fqx', 'http-access 127.0.0.1 admin', '/etc/pmta/settings/access']],
            ['pmta-relay-access-policy', ['grep', '-Fq', 'always-allow-relaying yes', '/etc/pmta/settings/access']],
            ['nginx-command-proxy', ['grep', '-Fq', 'location = /command {', '/etc/nginx/conf.d/default.conf']],
            ['no-public-pmta-admin-policy', ['sh', '-c', '! grep -Fqx "http-access 0/0 monitor" /etc/pmta/settings/access']],
            ['nginx-service', ['systemctl', 'is-active', '--quiet', 'nginx']],
            ['nginx-configuration', ['nginx', '-t']],
            ['public-monitor-listener', ['sh', '-c', $this->listenCheck($publicPort)]],
            ['monitor-upstream-configuration', ['grep', '-Fq', "proxy_pass http://127.0.0.1:{$backendPort};", '/etc/nginx/conf.d/default.conf']],
        ];

        foreach ($relayIps as $ip) {
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException('A PMTA relay source IP is invalid.');
            }
            $checks[] = ['pmta-relay-source-'.$ip, ['grep', '-Fq', '<source '.$ip.'>', '/etc/pmta/settings/access']];
            $checks[] = ['monitor-command-allow-'.$ip, ['grep', '-Fq', 'allow '.$ip.';', '/etc/nginx/conf.d/default.conf']];
        }

        if (! $allowAllMonitor) {
            foreach (array_values(array_unique([...$developmentIps, ...$productionIps, ...$relayIps])) as $ip) {
                if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new InvalidArgumentException('A PMTA monitor allowlist IP is invalid.');
                }
                $checks[] = ['monitor-allow-'.$ip, ['grep', '-Fq', 'allow '.$ip.';', '/etc/nginx/conf.d/default.conf']];
            }
            $checks[] = ['monitor-restricted-policy', ['grep', '-Fq', '# ALPHALINK_PMTA_MONITOR_ACCESS_RESTRICTED', '/etc/nginx/conf.d/default.conf']];
        } else {
            $checks[] = ['monitor-public-read-policy', ['grep', '-Fq', '# ALPHALINK_PMTA_MONITOR_ACCESS_PUBLIC', '/etc/nginx/conf.d/default.conf']];
        }

        $expectedListen = $https ? "listen {$publicPort} ssl;" : "listen {$publicPort};";
        $checks[] = ['monitor-protocol-and-port', ['grep', '-Fq', $expectedListen, '/etc/nginx/conf.d/default.conf']];
        if ($https) {
            $checks[] = ['monitor-server-name', ['grep', '-Fq', 'server_name '.$domain.';', '/etc/nginx/conf.d/default.conf']];
            $checks[] = ['monitor-certificate', ['test', '-s', '/etc/letsencrypt/live/'.$domain.'/fullchain.pem']];
            $checks[] = ['monitor-private-key', ['test', '-s', '/etc/letsencrypt/live/'.$domain.'/privkey.pem']];
        } else {
            $checks[] = ['monitor-ip-fallback-server-name', ['grep', '-Fq', 'server_name _;', '/etc/nginx/conf.d/default.conf']];
        }

        $script = "set +e\nfailures=''\ncheck() { label=\"$1\"; shift; if ! \"$@\" >/dev/null 2>&1; then failures=\"\${failures}\${failures:+,}\${label}\"; fi; }\n";
        foreach ($checks as [$label, $argv]) {
            $script .= 'check '.escapeshellarg($label);
            foreach ($argv as $argument) {
                $script .= ' '.escapeshellarg($argument);
            }
            $script .= "\n";
        }

        $backendUrl = 'http://127.0.0.1:'.$backendPort.'/';
        if ($https) {
            $monitorUrl = 'https://'.$domain.':'.$publicPort.'/';
            $resolve = '--resolve '.escapeshellarg($domain.':'.$publicPort.':127.0.0.1');
            $monitorProbe = 'curl --silent --show-error --output /dev/null --write-out '.escapeshellarg('%{http_code}').' --connect-timeout 1 --max-time 1 '.$resolve.' '.escapeshellarg($monitorUrl);
        } else {
            $monitorUrl = 'http://127.0.0.1:'.$publicPort.'/';
            $monitorProbe = 'curl --silent --show-error --output /dev/null --write-out '.escapeshellarg('%{http_code}').' --connect-timeout 1 --max-time 1 '.escapeshellarg($monitorUrl);
        }

        // PMTA can take several seconds to bind after package installation or
        // a configuration reload. Retry both local and public paths together
        // so a slow first start does not create a false failed installation.
        $backendProbe = 'curl --silent --show-error --output /dev/null --write-out '.escapeshellarg('%{http_code}').' --connect-timeout 1 --max-time 1 '.escapeshellarg($backendUrl);
        $script .= "backend_status=''; monitor_status=''\n";
        $script .= "for attempt in \$(seq 1 20); do\n";
        $script .= '    backend_status=$('.$backendProbe.' 2>/dev/null || true)'."\n";
        $script .= '    monitor_status=$('.$monitorProbe.' 2>/dev/null || true)'."\n";
        $script .= "    case \"\$backend_status\" in 2??|3??) backend_ready=1 ;; *) backend_ready=0 ;; esac\n";
        $script .= "    case \"\$monitor_status\" in 2??|3??) monitor_ready=1 ;; *) monitor_ready=0 ;; esac\n";
        $script .= "    if [ \"\$backend_ready\" -eq 1 ] && [ \"\$monitor_ready\" -eq 1 ]; then break; fi\n";
        $script .= "    sleep 1\ndone\n";
        $script .= "case \"\$backend_status\" in 2??|3??) ;; *) failures=\"\${failures}\${failures:+,}pmta-backend-http-\${backend_status:-unreachable}\" ;; esac\n";
        $script .= "case \"\$monitor_status\" in 2??|3??) ;; *) failures=\"\${failures}\${failures:+,}public-monitor-http-\${monitor_status:-unreachable}\" ;; esac\n";
        $script .= "if [ -z \"\$failures\" ]; then printf ALPHALINK_PMTA_READY; else printf 'ALPHALINK_PMTA_NOT_READY:%s\\n' \"\$failures\"; fi";

        return $script;
    }

    private function listenCheck(int $port): string
    {
        return 'ss -H -ltn | grep -Eq '.escapeshellarg(':'.$port.'([[:space:]]|$)');
    }
}
