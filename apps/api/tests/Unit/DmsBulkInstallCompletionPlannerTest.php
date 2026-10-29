<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsBulkInstallCompletionPlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DmsBulkInstallCompletionPlannerTest extends TestCase
{
    public function test_it_plans_non_owned_domain_finalization_in_dms_write_order(): void
    {
        $rows = [
            ['main' => true, 'ip' => '8.8.8.8', 'type' => 'ipv4', 'domain_id' => 21, 'sub' => '', 'domain' => 'relay.test'],
            ['main' => false, 'ip' => '2001:4860:4860::8888', 'type' => 'ipv6', 'domain_id' => 21, 'sub' => 'edge', 'domain' => 'relay.test'],
        ];
        $options = [
            'tracking' => true,
            'proxy' => false,
            'imap' => true,
            'spf' => false,
            'dkim' => true,
            'dmarc' => false,
            'https' => true,
        ];

        $operations = (new DmsBulkInstallCompletionPlanner)->plan(7, 21, false, $rows, 50, $options);

        $this->assertSame([
            ['action' => 'delete_server_mtas', 'server_id' => 7],
            ['action' => 'delete_server_ips', 'server_id' => 7],
            ['action' => 'insert_server_ip', 'ip' => '8.8.8.8', 'type' => 'ipv4', 'dns' => 'relay.test', 'domain_id' => 21, 'server_id' => 7, 'is_active' => true],
            ['action' => 'insert_server_ip', 'ip' => '2001:4860:4860::8888', 'type' => 'ipv6', 'dns' => 'relay.test', 'domain_id' => 21, 'server_id' => 7, 'is_active' => true],
            [
                'action' => 'save_installation_config',
                'server_id' => 7,
                'install_parameters' => [
                    'data' => $rows,
                    'pmta_version' => 50,
                    ...$options,
                    'pmta_monitor_https' => true,
                ],
            ],
            ['action' => 'mark_server_installed', 'server_id' => 7, 'is_installed' => true],
        ], $operations);
    }

    public function test_it_uses_subdomain_dns_and_marks_owned_domain_used_before_saving_config(): void
    {
        $rows = [
            ['main' => true, 'ip' => '8.8.4.4', 'type' => 'ipv4', 'domain_id' => 44, 'sub' => '', 'domain' => 'owned.test'],
            ['main' => false, 'ip' => '1.1.1.1', 'type' => 'ipv4', 'domain_id' => 44, 'sub' => 'mail-two', 'domain' => 'owned.test'],
        ];
        $options = [
            'https' => false,
            'dmarc' => false,
            'dkim' => false,
            'spf' => false,
            'imap' => false,
            'proxy' => false,
            'tracking' => false,
        ];

        $operations = (new DmsBulkInstallCompletionPlanner)->plan(9, 44, true, $rows, 45, $options);

        $this->assertSame([
            'owned.test',
            'mail-two.owned.test',
        ], array_column(array_slice($operations, 2, 2), 'dns'));
        $this->assertSame([
            'action' => 'mark_domain_used',
            'domain_id' => 44,
            'status' => 'used',
        ], $operations[4]);
        $this->assertSame('save_installation_config', $operations[5]['action']);
        $this->assertSame('mark_server_installed', $operations[6]['action']);
        $this->assertSame([
            'data', 'pmta_version', 'tracking', 'proxy', 'imap', 'spf', 'dkim', 'dmarc', 'https', 'pmta_monitor_https',
        ], array_keys($operations[5]['install_parameters']));
        $this->assertFalse($operations[5]['install_parameters']['https']);
        $this->assertTrue($operations[5]['install_parameters']['pmta_monitor_https']);
    }

    public function test_it_records_ip_http_monitor_mode_when_the_domain_certificate_is_unavailable(): void
    {
        $rows = [['main' => true, 'ip' => '203.0.113.45', 'type' => 'ipv4', 'domain_id' => 7, 'sub' => '', 'domain' => 'missing.example.test']];
        $options = [
            'tracking' => false,
            'proxy' => false,
            'imap' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
            'https' => false,
        ];

        $operations = (new DmsBulkInstallCompletionPlanner)->plan(1, 7, false, $rows, 45, $options, false);

        $installation = collect($operations)->firstWhere('action', 'save_installation_config');
        $serverStatus = collect($operations)->firstWhere('action', 'mark_server_installed');

        $this->assertFalse($installation['install_parameters']['pmta_monitor_https']);
        $this->assertTrue($serverStatus['is_installed']);
    }

    public function test_it_rejects_unformatted_rows_and_incomplete_or_non_boolean_options(): void
    {
        $planner = new DmsBulkInstallCompletionPlanner;
        $options = [
            'tracking' => false,
            'proxy' => false,
            'imap' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
            'https' => false,
        ];

        try {
            $planner->plan(7, 21, false, [['ip' => '8.8.8.8']], 50, $options);
            $this->fail('Malformed rows must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('DMS install completion requires formatted IP rows.', $exception->getMessage());
        }

        try {
            $planner->plan(7, 21, false, [], 50, ['tracking' => false]);
            $this->fail('Incomplete option sets must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('DMS install completion requires all seven install options.', $exception->getMessage());
        }

        $options['tracking'] = 'false';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DMS install completion options must be booleans.');
        $planner->plan(7, 21, false, [], 50, $options);
    }
}
