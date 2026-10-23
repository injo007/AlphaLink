<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsBulkInstallDnsRecordPlanner;
use App\Services\Infrastructure\DmsBulkInstallIpPlanner;
use App\Services\Infrastructure\DmsBulkInstallSubdomainGenerator;
use App\Services\Infrastructure\DmsBulkInstallVmtaPlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

class DmsBulkInstallIpPlannerTest extends TestCase
{
    public function test_it_orders_and_formats_dms_ips_with_primary_and_first_alias_rules(): void
    {
        $ips = (new DmsBulkInstallIpPlanner)->format(
            '8.8.8.8',
            42,
            'example.test',
            [
                '2001:4860:4860::8888',
                '8.8.8.8',
                '1.1.1.1',
                '8.8.8.8',
                '2001:4860:4860::8844',
            ],
            ['first-alias', 'primary-alias', 'third-alias', 'fourth-alias'],
        );

        $this->assertSame([
            [
                'main' => false,
                'ip' => '1.1.1.1',
                'type' => 'ipv4',
                'domain_id' => 42,
                'sub' => '',
                'domain' => 'example.test',
            ],
            [
                'main' => true,
                'ip' => '8.8.8.8',
                'type' => 'ipv4',
                'domain_id' => 42,
                'sub' => '',
                'domain' => 'example.test',
            ],
            [
                'main' => false,
                'ip' => '2001:4860:4860::8844',
                'type' => 'ipv6',
                'domain_id' => 42,
                'sub' => 'third-alias',
                'domain' => 'example.test',
            ],
            [
                'main' => false,
                'ip' => '2001:4860:4860::8888',
                'type' => 'ipv6',
                'domain_id' => 42,
                'sub' => 'fourth-alias',
                'domain' => 'example.test',
            ],
        ], $ips);
    }

    public function test_formatted_ips_feed_the_existing_dns_and_vmta_planners_without_shape_changes(): void
    {
        $ips = (new DmsBulkInstallIpPlanner)->format(
            '8.8.8.8',
            42,
            'example.test',
            ['8.8.8.8', '1.1.1.1', '2001:4860:4860::8888'],
            ['unused-first', 'unused-primary', 'relay-three'],
        );

        $dnsRecords = (new DmsBulkInstallDnsRecordPlanner)->records(true, $ips, [
            'tracking' => true,
            'imap' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
        ]);
        $vmtaFiles = (new DmsBulkInstallVmtaPlanner)->files(
            'centos9',
            true,
            $ips,
            [],
            'selector-one',
            false,
            false,
        );

        $this->assertSame([
            ['host' => '@', 'address' => '1.1.1.1', 'type' => 'A', 'ttl' => '60'],
            ['host' => '@', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'www', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => '*', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'relay-three', 'address' => '2001:4860:4860::8888', 'type' => 'AAAA', 'ttl' => '60'],
        ], $dnsRecords);
        $this->assertStringContainsString('host-name relay-three.example.test', $vmtaFiles['vmtas']);
        $this->assertStringContainsString('relay-domain relay-three.example.test', $vmtaFiles['inbound']);
    }

    public function test_it_generates_the_source_alias_count_and_formats_the_selected_word_at_its_ip_index(): void
    {
        $expectedAliases = (new DmsBulkInstallSubdomainGenerator(new Randomizer(new Mt19937(2718))))->generate(3);
        $planner = new DmsBulkInstallIpPlanner(
            new DmsBulkInstallSubdomainGenerator(new Randomizer(new Mt19937(2718))),
        );

        $ips = $planner->format('8.8.8.8', 42, 'example.test', [
            '8.8.8.8',
            '1.1.1.1',
            '2001:4860:4860::8888',
        ]);

        $this->assertSame('', $ips[0]['sub']);
        $this->assertSame('', $ips[1]['sub']);
        $this->assertSame($expectedAliases[2], $ips[2]['sub']);
    }

    public function test_it_rejects_invalid_discovered_ips_and_incomplete_random_alias_output(): void
    {
        $planner = new DmsBulkInstallIpPlanner;

        try {
            $planner->orderDiscoveredIps(['192.0.2.12', 'not-an-ip']);
            $this->fail('Expected invalid discovered addresses to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Discovered server addresses must be valid IPv4 or IPv6 values.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $planner->format('8.8.8.8', 42, 'example.test', ['8.8.8.8'], []);
    }
}
