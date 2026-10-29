<?php

namespace App\Services;

use App\Models\PmtaSmtpConfig;

class PmtaSmtpConfigBuilder
{
    public static function virtualMtaName(string $ip, int|string $id): string
    {
        $ipName = str_replace(':', '.', $ip);

        return "smtp-{$ipName}-{$id}";
    }

    public function build(PmtaSmtpConfig $config, int $pmtaVersion): string
    {
        $data = '<virtual-mta '.self::virtualMtaName((string) $config->ip, (int) $config->getKey()).">\n";
        $data .= "    smtp-source-ip {$config->ip}\n";
        $data .= '    host-name '.($config->dns ?: $config->ip)."\n";
        $data .= "    <domain *>\n        queue-to smtp.queue\n    </domain>\n";

        if ($config->google_script) {
            $data .= "    <domain smtp.queue>\n        type pipe\n        command ___/usr/sbin/gs {$config->password} \$to___\n    </domain>\n";
        } else {
            $routeDirective = $pmtaVersion !== 40 ? 'smtp-hosts ' : 'route ';
            $data .= "    <domain smtp.queue>\n";
            $data .= "        use-unencrypted-plain-auth yes\n";
            $data .= "        auth-username {$config->username}\n";
            $data .= "        auth-password {$config->password}\n";
            $data .= "        {$routeDirective}{$config->host}:{$config->port}\n";
            $data .= "        max-smtp-out 1200\n        require-starttls yes\n        use-starttls yes\n    </domain>\n";
        }

        $data .= "</virtual-mta>\n";

        return str_replace(['$', '"', '!'], ['\\$', '\\"', '\\!'], $data);
    }
}
