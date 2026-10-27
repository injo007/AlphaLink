<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsBulkInstallDnsRecordPlanner;
use PHPUnit\Framework\TestCase;

class DmsBulkInstallDnsRecordPlannerTest extends TestCase
{
    public function test_it_builds_the_dms_records_in_the_observed_order_for_each_option(): void
    {
        $records = (new DmsBulkInstallDnsRecordPlanner)->records(true, [
            [
                'main' => true,
                'ip' => '8.8.8.8',
                'type' => 'ipv4',
                'sub' => '',
                'domain' => 'example.test',
            ],
            [
                'main' => false,
                'ip' => '2001:4860:4860::8888',
                'type' => 'ipv6',
                'sub' => 'relay-two',
                'domain' => 'example.test',
            ],
        ], [
            'tracking' => true,
            'imap' => true,
            'spf' => true,
            'dkim' => true,
            'dmarc' => true,
        ], 's1', "  v=DKIM1; p=public-key \n");

        $this->assertSame([
            ['host' => '@', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'www', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => '*', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => '@', 'address' => 'example.test', 'type' => 'MX', 'ttl' => '60'],
            ['host' => 's1._domainkey', 'address' => 'v=DKIM1; p=public-key', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '_dmarc', 'address' => 'v=DMARC1; p=none; fo=1; rua=mailto:dmarc@example.test; ruf=mailto:dmarc@example.test', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => 'relay-two', 'address' => '2001:4860:4860::8888', 'type' => 'AAAA', 'ttl' => '60'],
            ['host' => 'relay-two', 'address' => 'relay-two.example.test', 'type' => 'MX', 'ttl' => '60'],
            ['host' => 's1._domainkey.relay-two', 'address' => 'v=DKIM1; p=public-key', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '_dmarc.relay-two', 'address' => 'v=DMARC1; p=none; fo=1; rua=mailto:dmarc@example.test; ruf=mailto:dmarc@example.test', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '@', 'address' => 'v=spf1 ip4:8.8.8.8 ip6:2001:4860:4860::8888 -all', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '*', 'address' => 'v=spf1 ip4:8.8.8.8 ip6:2001:4860:4860::8888 -all', 'type' => 'TXT', 'ttl' => '60'],
        ], $records);
    }

    public function test_it_uses_only_a_supplied_public_mapping_for_a_private_address(): void
    {
        $records = (new DmsBulkInstallDnsRecordPlanner)->records(true, [[
            'main' => true,
            'ip' => '10.0.0.4',
            'type' => 'ipv4',
            'sub' => '',
            'domain' => 'example.test',
        ]], [
            'tracking' => true,
            'imap' => false,
            'spf' => true,
            'dkim' => false,
            'dmarc' => false,
        ], publicIpMappings: ['10.0.0.4' => '198.51.100.4']);

        $this->assertSame([
            ['host' => '@', 'address' => '198.51.100.4', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'www', 'address' => '198.51.100.4', 'type' => 'A', 'ttl' => '60'],
            ['host' => '*', 'address' => '198.51.100.4', 'type' => 'A', 'ttl' => '60'],
            ['host' => '@', 'address' => 'v=spf1 ip4:198.51.100.4 -all', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '*', 'address' => 'v=spf1 ip4:198.51.100.4 -all', 'type' => 'TXT', 'ttl' => '60'],
        ], $records);
    }

    public function test_it_always_creates_selected_ip_a_and_aaaa_records_when_tracking_is_disabled(): void
    {
        $records = (new DmsBulkInstallDnsRecordPlanner)->records(true, [
            [
                'main' => true,
                'ip' => '8.8.8.8',
                'type' => 'ipv4',
                'sub' => '',
                'domain' => 'example.test',
            ],
            [
                'main' => false,
                'ip' => '2001:4860:4860::8888',
                'type' => 'ipv6',
                'sub' => 'relay',
                'domain' => 'example.test',
            ],
        ], [
            'tracking' => false,
            'imap' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
        ]);

        $this->assertSame([
            ['host' => '@', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'relay', 'address' => '2001:4860:4860::8888', 'type' => 'AAAA', 'ttl' => '60'],
        ], $records);
    }

    public function test_it_rejects_a_private_address_when_the_mapping_is_invalid(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A selected interface has no verified public IP for DNS records.');

        (new DmsBulkInstallDnsRecordPlanner)->records(true, [[
            'main' => false,
            'ip' => '10.0.0.4',
            'type' => 'ipv4',
            'sub' => 'relay-two',
            'domain' => 'example.test',
        ]], [
            'tracking' => false,
            'imap' => false,
            'spf' => true,
            'dkim' => false,
            'dmarc' => false,
        ], publicIpMappings: ['10.0.0.4' => 'not-an-ip']);

    }

    public function test_external_domain_records_target_the_selected_subdomain(): void
    {
        $records = (new DmsBulkInstallDnsRecordPlanner)->records(false, [[
            'main' => true,
            'ip' => '8.8.8.8',
            'type' => 'ipv4',
            'sub' => 'relay',
            'domain' => 'example.test',
        ]], [
            'tracking' => true,
            'imap' => false,
            'spf' => true,
            'dkim' => true,
            'dmarc' => false,
        ], 's1', 'public-key');

        $this->assertSame([
            ['host' => 'relay', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'www', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => '*', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => 's1._domainkey.relay', 'address' => 'public-key', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => 'relay', 'address' => 'v=spf1 ip4:8.8.8.8 -all', 'type' => 'TXT', 'ttl' => '60'],
        ], $records);
    }
}
