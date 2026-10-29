<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

/** Builds a fail-closed, diagnostic post-install check for PMTA and its public monitor. */
class DmsPmtaPostInstallReadiness
{
    /** @param array<string, mixed> $settings */
    public function command(
        int $version,
        array $settings,
        bool $https = false,
        ?string $domain = null,
        bool $directMonitor = false,
    ): string {
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
        if ($https && $directMonitor) {
            throw new InvalidArgumentException('The direct public-IP monitor cannot use HTTPS without a domain proxy.');
        }
        if ($directMonitor && $version !== 40) {
            throw new InvalidArgumentException('Direct PMTA monitor mode is supported only for PMTA 4.0.');
        }

        $backendPort = $directMonitor ? $publicPort : $publicPort * 2;
        $relayIps = $settings['pmta_relay_ips'] ?? [];
        $developmentIps = $settings['dev_ips'] ?? [];
        $productionIps = $settings['production_ips'] ?? [];
        $allowAllMonitor = $settings['allow_all_monitor'] ?? false;
        if (! is_array($relayIps) || ! is_array($developmentIps) || ! is_array($productionIps) || ! is_bool($allowAllMonitor)) {
            throw new InvalidArgumentException('The PMTA monitor readiness settings are invalid.');
        }

        $checks = [
            ['rpm-package', ['rpm', '-q', 'PowerMTA']],
            ['pmta-local-admin-policy', ['grep', '-Fqx', 'http-access 127.0.0.1 admin', '/etc/pmta/settings/access']],
            ['pmta-relay-access-policy', ['grep', '-Fq', 'always-allow-relaying yes', '/etc/pmta/settings/access']],
            ['no-public-pmta-admin-policy', ['sh', '-c', '! grep -Fqx "http-access 0/0 admin" /etc/pmta/settings/access']],
        ];

        if ($directMonitor) {
            foreach (array_values(array_unique($developmentIps)) as $ip) {
                if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new InvalidArgumentException('A PMTA monitor allowlist IP is invalid.');
                }
                $checks[] = ['direct-monitor-admin-allow-'.$ip, ['grep', '-Fqx', 'http-access '.$ip.' admin', '/etc/pmta/settings/access']];
            }
            foreach (array_values(array_unique($relayIps)) as $ip) {
                $checks[] = ['direct-monitor-admin-allow-'.$ip, ['grep', '-Fqx', 'http-access '.$ip.' admin', '/etc/pmta/settings/access']];
            }
            foreach (array_values(array_unique($productionIps)) as $ip) {
                if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new InvalidArgumentException('A PMTA monitor allowlist IP is invalid.');
                }
                $checks[] = ['direct-monitor-read-allow-'.$ip, ['grep', '-Fqx', 'http-access '.$ip.' monitor', '/etc/pmta/settings/access']];
            }
            if ($allowAllMonitor) {
                $checks[] = ['direct-monitor-public-read-policy', ['grep', '-Fqx', 'http-access 0/0 monitor', '/etc/pmta/settings/access']];
            } else {
                $checks[] = ['direct-monitor-restricted-policy', ['sh', '-c', '! grep -Fqx "http-access 0/0 monitor" /etc/pmta/settings/access']];
            }
        } else {
            $checks[] = ['nginx-command-proxy', ['grep', '-Fq', 'location = /command {', '/etc/nginx/conf.d/default.conf']];
            $checks[] = ['nginx-service', ['systemctl', 'is-active', '--quiet', 'nginx']];
            $checks[] = ['nginx-configuration', ['nginx', '-t']];
            $checks[] = ['public-monitor-listener', ['sh', '-c', $this->listenCheck($publicPort)]];
            $checks[] = ['monitor-upstream-configuration', ['grep', '-Fq', "proxy_pass http://127.0.0.1:{$backendPort};", '/etc/nginx/conf.d/default.conf']];
        }

        foreach ($relayIps as $ip) {
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException('A PMTA relay source IP is invalid.');
            }
            $checks[] = ['pmta-relay-source-'.$ip, ['grep', '-Fq', '<source '.$ip.'>', '/etc/pmta/settings/access']];
            if (! $directMonitor) {
                $checks[] = ['monitor-command-allow-'.$ip, ['grep', '-Fq', 'allow '.$ip.';', '/etc/nginx/conf.d/default.conf']];
            }
        }

        if (! $directMonitor && ! $allowAllMonitor) {
            foreach (array_values(array_unique([...$developmentIps, ...$productionIps, ...$relayIps])) as $ip) {
                if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new InvalidArgumentException('A PMTA monitor allowlist IP is invalid.');
                }
                $checks[] = ['monitor-allow-'.$ip, ['grep', '-Fq', 'allow '.$ip.';', '/etc/nginx/conf.d/default.conf']];
            }
            $checks[] = ['monitor-restricted-policy', ['grep', '-Fq', '# ALPHALINK_PMTA_MONITOR_ACCESS_RESTRICTED', '/etc/nginx/conf.d/default.conf']];
        } elseif (! $directMonitor) {
            $checks[] = ['monitor-public-read-policy', ['grep', '-Fq', '# ALPHALINK_PMTA_MONITOR_ACCESS_PUBLIC', '/etc/nginx/conf.d/default.conf']];
        }

        if (! $directMonitor) {
            $expectedListen = $https ? "listen {$publicPort} ssl;" : "listen {$publicPort};";
            $checks[] = ['monitor-protocol-and-port', ['grep', '-Fq', $expectedListen, '/etc/nginx/conf.d/default.conf']];
            if ($https) {
                $checks[] = ['monitor-server-name', ['grep', '-Fq', 'server_name '.$domain.';', '/etc/nginx/conf.d/default.conf']];
                $checks[] = ['monitor-certificate', ['test', '-s', '/etc/letsencrypt/live/'.$domain.'/fullchain.pem']];
                $checks[] = ['monitor-private-key', ['test', '-s', '/etc/letsencrypt/live/'.$domain.'/privkey.pem']];
            } else {
                $checks[] = ['monitor-ip-fallback-server-name', ['grep', '-Fq', 'server_name _;', '/etc/nginx/conf.d/default.conf']];
            }
        }

        if (in_array($version, [45, 50], true)) {
            $checks[] = ['pmta-no-http-https-redirect', ['grep', '-Fqx', 'http-redirect-to-https false', '/etc/pmta/settings/config']];
        }

        $script = "set +e\nfailures=''\ncheck() { label=\"$1\"; shift; if ! \"$@\" >/dev/null 2>&1; then failures=\"\${failures}\${failures:+,}\${label}\"; fi; }\n";
        foreach ($checks as [$label, $argv]) {
            $script .= 'check '.escapeshellarg($label);
            foreach ($argv as $argument) {
                $script .= ' '.escapeshellarg($argument);
            }
            $script .= "\n";
        }

        if ($directMonitor) {
            $monitorUrl = 'http://127.0.0.1:'.$publicPort.'/';
            $monitorProbe = 'curl --silent --show-error --output /dev/null --write-out '.escapeshellarg('%{http_code}').' --connect-timeout 1 --max-time 1 '.escapeshellarg($monitorUrl);
        } else {
            $backendUrl = 'http://127.0.0.1:'.$backendPort.'/';
            if ($https) {
                $monitorUrl = 'https://'.$domain.':'.$publicPort.'/';
                $resolve = '--resolve '.escapeshellarg($domain.':'.$publicPort.':127.0.0.1');
                $monitorProbe = 'curl --silent --show-error --output /dev/null --write-out '.escapeshellarg('%{http_code}').' --connect-timeout 1 --max-time 1 '.$resolve.' '.escapeshellarg($monitorUrl);
            } else {
                $monitorUrl = 'http://127.0.0.1:'.$publicPort.'/';
                $monitorProbe = 'curl --silent --show-error --output /dev/null --write-out '.escapeshellarg('%{http_code}').' --connect-timeout 1 --max-time 1 '.escapeshellarg($monitorUrl);
            }
            // PMTA can take several seconds to bind after installation or a configuration reload.
            $backendProbe = 'curl --silent --show-error --output /dev/null --write-out '.escapeshellarg('%{http_code}').' --connect-timeout 1 --max-time 1 '.escapeshellarg($backendUrl);
        }

        $script .= "pmta_service_ready=0; pmta_http_service_ready=0; listener_ready=0; monitor_status=''; monitor_ready=0\n";
        if (! $directMonitor) {
            $script .= "backend_status=''; backend_ready=0\n";
        }
        $script .= 'for attempt in $(seq 1 20); do'."\n";
        $script .= '    if systemctl is-active --quiet pmta; then pmta_service_ready=1; else pmta_service_ready=0; fi'."\n";
        $script .= '    if systemctl is-active --quiet pmtahttp; then pmta_http_service_ready=1; else pmta_http_service_ready=0; fi'."\n";
        $script .= '    if '.$this->listenCheck($backendPort).'; then listener_ready=1; else listener_ready=0; fi'."\n";
        $script .= '    monitor_status=$('.$monitorProbe.' 2>/dev/null || true)'."\n";
        $script .= '    case "$monitor_status" in 2??|3??) monitor_ready=1 ;; *) monitor_ready=0 ;; esac'."\n";
        if (! $directMonitor) {
            $script .= '    backend_status=$('.$backendProbe.' 2>/dev/null || true)'."\n";
            $script .= '    case "$backend_status" in 2??|3??) backend_ready=1 ;; *) backend_ready=0 ;; esac'."\n";
            $script .= '    if [ "$pmta_service_ready" -eq 1 ] && [ "$pmta_http_service_ready" -eq 1 ] && [ "$listener_ready" -eq 1 ] && [ "$backend_ready" -eq 1 ] && [ "$monitor_ready" -eq 1 ]; then break; fi'."\n";
        } else {
            $script .= '    if [ "$pmta_service_ready" -eq 1 ] && [ "$pmta_http_service_ready" -eq 1 ] && [ "$listener_ready" -eq 1 ] && [ "$monitor_ready" -eq 1 ]; then break; fi'."\n";
        }
        $script .= '    sleep 1'."\n";
        $script .= 'done'."\n";
        $script .= '[ "$pmta_service_ready" -eq 1 ] || failures="${failures}${failures:+,}pmta-service"'."\n";
        $script .= '[ "$pmta_http_service_ready" -eq 1 ] || failures="${failures}${failures:+,}pmta-http-service"'."\n";
        $listenerLabel = $directMonitor ? 'pmta-public-monitor-listener' : 'pmta-backend-listener';
        $script .= '[ "$listener_ready" -eq 1 ] || failures="${failures}${failures:+,}'.$listenerLabel.'"'."\n";
        if ($directMonitor) {
            $script .= 'case "$monitor_status" in 2??|3??) ;; *) failures="${failures}${failures:+,}pmta-direct-http-${monitor_status:-unreachable}" ;; esac'."\n";
        } else {
            $script .= 'case "$backend_status" in 2??|3??) ;; *) failures="${failures}${failures:+,}pmta-backend-http-${backend_status:-unreachable}" ;; esac'."\n";
            $script .= 'case "$monitor_status" in 2??|3??) ;; *) failures="${failures}${failures:+,}public-monitor-http-${monitor_status:-unreachable}" ;; esac'."\n";
        }
        $script .= "if [ -z \"\$failures\" ]; then printf ALPHALINK_PMTA_READY; else\n";
        $script .= "printf 'ALPHALINK_PMTA_NOT_READY:%s\\n' \"\$failures\"\n";
        $diagnosticFailurePatterns = $directMonitor
            ? '*pmta-service*|*pmta-http-service*|*pmta-public-monitor-listener*|*pmta-direct-http-*'
            : '*pmta-service*|*pmta-http-service*|*pmta-backend-listener*|*pmta-backend-http-*|*public-monitor-http-5*';
        $script .= 'case "$failures" in '.$diagnosticFailurePatterns.")\n";
        $script .= "    pmta_diagnostic=\$({\n";
        $script .= "        printf 'systemd: '; systemctl show pmta -p ActiveState -p SubState -p Result -p ExecMainStatus --no-pager 2>/dev/null | tr '\\n' ' ';\n";
        $script .= "        printf ' status: '; systemctl status pmta --no-pager -l -n 12 2>&1 | tail -n 10 | tr '\\n' ' ';\n";
        $script .= "        printf ' journal: '; journalctl -u pmta --no-pager -n 30 -o cat 2>/dev/null | grep -Ei 'error|failed|fatal|invalid|unknown|syntax|parse|license|permission|bind|address.*(use|fail)|not found|cannot|unable|denied' | tail -n 8;\n";
        $script .= "        printf ' log: '; tail -n 40 /var/log/pmta/pmta.log 2>/dev/null | grep -Ei 'error|failed|fatal|invalid|unknown|syntax|parse|license|permission|bind|address.*(use|fail)|not found|cannot|unable|denied' | tail -n 6;\n";
        $script .= "    } | sed -E -e 's/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z]{2,}/<email>/g' -e 's/([0-9]{1,3}\\.){3}[0-9]{1,3}/<ip>/g' -e 's/[A-Fa-f0-9]{32,}/<redacted>/g' -e 's/[A-Za-z0-9_+\\/=-]{40,}/<redacted>/g' | tr '\\n' ' ' | cut -c1-1200)\n";
        $script .= "    if [ -n \"\$pmta_diagnostic\" ]; then printf 'ALPHALINK_PMTA_DIAGNOSTIC:%s\\n' \"\$pmta_diagnostic\"; fi\n";
        $script .= "    ;;\nesac\n";
        $script .= 'fi';

        return $script;
    }

    public static function failureDetails(bool|string $result): string
    {
        if (! is_string($result) || ! str_starts_with($result, 'ALPHALINK_PMTA_NOT_READY:')) {
            return 'probe-command-failed';
        }

        $lines = preg_split('/\\r?\\n/', substr($result, strlen('ALPHALINK_PMTA_NOT_READY:'))) ?: [];
        $failures = [];
        $startupDiagnostic = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'ALPHALINK_PMTA_DIAGNOSTIC:')) {
                $startupDiagnostic = trim(substr($line, strlen('ALPHALINK_PMTA_DIAGNOSTIC:')));

                continue;
            }
            if ($line !== '') {
                $failures[] = $line;
            }
        }

        $details = implode(',', $failures);
        if ($startupDiagnostic !== null && $startupDiagnostic !== '') {
            $details .= ($details === '' ? '' : '; ').'PMTA startup detail: '.$startupDiagnostic;
        }

        return $details !== '' ? $details : 'unspecified-failure';
    }

    private function listenCheck(int $port): string
    {
        return 'ss -H -ltn | grep -Eq '.escapeshellarg(':'.$port.'([[:space:]]|$)');
    }
}
