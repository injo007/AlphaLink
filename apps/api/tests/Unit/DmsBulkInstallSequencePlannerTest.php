<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsBulkInstallSequencePlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DmsBulkInstallSequencePlannerTest extends TestCase
{
    public function test_it_preserves_dms_order_when_every_optional_phase_is_enabled(): void
    {
        $result = (new DmsBulkInstallSequencePlanner)->plan(
            false,
            false,
            true,
            true,
            50,
            $this->options(true),
            'owned.test',
        );

        $this->assertNull($result['error_message']);
        $this->assertSame([
            ['name' => 'prepare_installation'],
            ['name' => 'initialize_platform_installer'],
            ['name' => 'discover_server_ips'],
            ['name' => 'format_discovered_ips'],
            ['name' => 'configure_host'],
            ['name' => 'remove_old_packages'],
            ['name' => 'setup_services'],
            ['name' => 'install_php'],
            ['name' => 'setup_tracking_app'],
            ['name' => 'setup_domain_records', 'provider_write' => true],
            ['name' => 'install_pmta', 'version' => 50],
            ['name' => 'setup_vmtas', 'dkim_enabled' => true],
            ['name' => 'install_imap'],
            ['name' => 'install_proxy'],
            ['name' => 'install_https'],
            ['name' => 'configure_firewall'],
            ['name' => 'finish_install'],
            ['name' => 'log_pmta_admin_link'],
            ['name' => 'log_ended_disconnecting'],
        ], $result['steps']);
    }

    public function test_it_keeps_the_dns_step_as_a_no_op_for_a_non_owned_domain(): void
    {
        $result = (new DmsBulkInstallSequencePlanner)->plan(
            false,
            false,
            true,
            false,
            40,
            $this->options(false),
            'external.test',
        );

        $this->assertNull($result['error_message']);
        $this->assertSame(['name' => 'setup_domain_records', 'provider_write' => false], $result['steps'][7]);
        $this->assertSame([
            'prepare_installation', 'initialize_platform_installer', 'discover_server_ips',
            'format_discovered_ips', 'configure_host', 'remove_old_packages', 'setup_services',
            'setup_domain_records', 'install_pmta', 'setup_vmtas', 'configure_firewall',
            'finish_install', 'log_pmta_admin_link', 'log_ended_disconnecting',
        ], array_column($result['steps'], 'name'));
    }

    public function test_it_returns_dms_guards_and_aborts_before_host_changes_when_no_ip_is_found(): void
    {
        $planner = new DmsBulkInstallSequencePlanner;
        $options = $this->options(false);

        $alreadyInstalled = $planner->plan(true, true, false, false, 40, $options, 'used.test');
        $this->assertSame('Server is already installed', $alreadyInstalled['error_message']);
        $this->assertSame([], $alreadyInstalled['steps']);

        $usedDomain = $planner->plan(false, true, false, false, 40, $options, 'used.test');
        $this->assertSame('Sorry this Domain: used.test is already used', $usedDomain['error_message']);
        $this->assertSame([], $usedDomain['steps']);

        $noIps = $planner->plan(false, false, false, false, 40, $options, 'free.test');
        $this->assertSame('Found 0 Ips Please Check Server Configuration Aborting', $noIps['error_message']);
        $this->assertSame([
            'prepare_installation', 'initialize_platform_installer', 'discover_server_ips', 'format_discovered_ips',
        ], array_column($noIps['steps'], 'name'));
    }

    public function test_it_rejects_incomplete_or_non_boolean_option_sets(): void
    {
        $planner = new DmsBulkInstallSequencePlanner;

        try {
            $planner->plan(false, false, true, false, 40, ['tracking' => false], 'free.test');
            $this->fail('Incomplete option sets must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('DMS install sequence requires all seven install options.', $exception->getMessage());
        }

        $options = $this->options(false);
        $options['https'] = 'false';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DMS install sequence options must be booleans.');
        $planner->plan(false, false, true, false, 40, $options, 'free.test');
    }

    /** @return array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool} */
    private function options(bool $enabled): array
    {
        return [
            'tracking' => $enabled,
            'proxy' => $enabled,
            'imap' => $enabled,
            'spf' => $enabled,
            'dkim' => $enabled,
            'dmarc' => $enabled,
            'https' => $enabled,
        ];
    }
}
