<?php

namespace App\Services\Infrastructure;

use InvalidArgumentException;

/**
 * Reconstructs ServerInstallBulk::install() ordering without running any step.
 */
class DmsBulkInstallSequencePlanner
{
    /**
     * @param  array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool}  $options
     * @return array{steps: list<array<string, mixed>>, error_message: ?string}
     */
    public function plan(
        bool $serverIsInstalled,
        bool $domainIsUsed,
        bool $hasDiscoveredIps,
        bool $isOwnedDomain,
        int $pmtaVersion,
        array $options,
        string $domainName,
    ): array {
        $this->validateOptions($options);

        if ($serverIsInstalled) {
            return ['steps' => [], 'error_message' => 'Server is already installed'];
        }

        if ($domainIsUsed) {
            return ['steps' => [], 'error_message' => "Sorry this Domain: {$domainName} is already used"];
        }

        $steps = [
            ['name' => 'prepare_installation'],
            ['name' => 'initialize_platform_installer'],
            ['name' => 'discover_server_ips'],
            ['name' => 'format_discovered_ips'],
        ];

        if (! $hasDiscoveredIps) {
            return [
                'steps' => $steps,
                'error_message' => 'Found 0 Ips Please Check Server Configuration Aborting',
            ];
        }

        $steps[] = ['name' => 'configure_host'];
        $steps[] = ['name' => 'remove_old_packages'];
        $steps[] = ['name' => 'setup_services'];

        if ($options['tracking']) {
            $steps[] = ['name' => 'install_php'];
            $steps[] = ['name' => 'setup_tracking_app'];
        }

        $steps[] = [
            'name' => 'setup_domain_records',
            'provider_write' => $isOwnedDomain,
        ];
        $steps[] = ['name' => 'install_pmta', 'version' => $pmtaVersion];
        $steps[] = ['name' => 'setup_vmtas', 'dkim_enabled' => $options['dkim']];

        foreach (['imap' => 'install_imap', 'proxy' => 'install_proxy', 'https' => 'install_https'] as $option => $step) {
            if ($options[$option]) {
                $steps[] = ['name' => $step];
            }
        }

        $steps[] = ['name' => 'configure_firewall'];
        $steps[] = ['name' => 'finish_install'];
        $steps[] = ['name' => 'log_pmta_admin_link'];
        $steps[] = ['name' => 'log_ended_disconnecting'];

        return ['steps' => $steps, 'error_message' => null];
    }

    /** @param array<string, mixed> $options */
    private function validateOptions(array $options): void
    {
        $expected = ['tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https'];
        $keys = array_keys($options);
        sort($keys);
        $sortedExpected = $expected;
        sort($sortedExpected);

        if ($keys !== $sortedExpected) {
            throw new InvalidArgumentException('DMS install sequence requires all seven install options.');
        }

        foreach ($options as $option) {
            if (! is_bool($option)) {
                throw new InvalidArgumentException('DMS install sequence options must be booleans.');
            }
        }
    }
}
