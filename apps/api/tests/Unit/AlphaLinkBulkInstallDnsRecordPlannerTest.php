<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkBulkInstallDnsRecordPlanner;
use PHPUnit\Framework\TestCase;

class AlphaLinkBulkInstallDnsRecordPlannerTest extends TestCase
{
    public function test_selected_hostname_is_the_only_a_record_needed_for_https_tracking(): void
    {
        $records = (new AlphaLinkBulkInstallDnsRecordPlanner)->recordsForAssignments('example.test', [[
            'hostname' => 'rico.example.test', 'ip' => '8.8.8.8',
            'dkim_selector' => 'key1', 'dkim_public_record' => 'v=DKIM1; p=ONE',
            'tracking_host' => 'rico.example.test',
        ]], ['tracking' => true, 'imap' => false, 'spf' => true, 'dkim' => true, 'dmarc' => true]);

        $addressRecords = array_values(array_filter($records, static fn (array $record): bool => $record['type'] === 'A'));
        $this->assertSame([['host' => 'rico', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60']], $addressRecords);
        $this->assertNotContains('track-rico', array_column($addressRecords, 'host'));
    }

    public function test_multi_domain_records_are_scoped_to_selected_hosts_and_combine_shared_host_ips(): void
    {
        $records = (new AlphaLinkBulkInstallDnsRecordPlanner)->recordsForAssignments('example.test', [
            [
                'hostname' => 'mail.example.test', 'ip' => '8.8.8.8',
                'dkim_selector' => 'key1', 'dkim_public_record' => 'v=DKIM1; p=ONE',
                'tracking_host' => 'click.mail.example.test',
            ],
            [
                'hostname' => 'mail.example.test', 'ip' => '1.1.1.1',
                'dkim_selector' => 'key1', 'dkim_public_record' => 'v=DKIM1; p=ONE',
                'tracking_host' => 'click.mail.example.test',
            ],
            [
                'hostname' => 'offers.example.test', 'ip' => '8.8.8.8',
                'dkim_selector' => 'key2', 'dkim_public_record' => 'v=DKIM1; p=TWO',
                'tracking_host' => 'click.offers.example.test',
            ],
        ], ['tracking' => true, 'imap' => false, 'spf' => true, 'dkim' => true, 'dmarc' => true]);

        $this->assertContains(['host' => 'mail', 'address' => 'v=spf1 ip4:8.8.8.8 ip4:1.1.1.1 -all', 'type' => 'TXT', 'ttl' => '60'], $records);
        $this->assertContains(['host' => 'offers', 'address' => 'v=spf1 ip4:8.8.8.8 -all', 'type' => 'TXT', 'ttl' => '60'], $records);
        $this->assertContains(['host' => 'key1._domainkey.mail', 'address' => 'v=DKIM1; p=ONE', 'type' => 'TXT', 'ttl' => '60'], $records);
        $this->assertContains(['host' => 'key2._domainkey.offers', 'address' => 'v=DKIM1; p=TWO', 'type' => 'TXT', 'ttl' => '60'], $records);
        $this->assertContains(['host' => 'click.offers', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'], $records);
        $this->assertNotContains('@', array_column($records, 'host'));
        $this->assertNotContains('*', array_column($records, 'host'));
    }

    public function test_multi_domain_records_reject_a_tracking_hostname_outside_its_zone(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside its DNS zone');

        (new AlphaLinkBulkInstallDnsRecordPlanner)->recordsForAssignments('example.test', [[
            'hostname' => 'mail.example.test', 'ip' => '8.8.8.8',
            'dkim_selector' => 'key1', 'dkim_public_record' => 'v=DKIM1; p=ONE',
            'tracking_host' => 'click.other.test',
        ]], ['tracking' => true, 'imap' => false, 'spf' => true, 'dkim' => true, 'dmarc' => true]);
    }

    public function test_multi_domain_records_reject_conflicting_dkim_keys_for_one_selector(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflicting public keys');

        (new AlphaLinkBulkInstallDnsRecordPlanner)->recordsForAssignments('example.test', [
            ['hostname' => 'mail.example.test', 'ip' => '8.8.8.8', 'dkim_selector' => 'key1', 'dkim_public_record' => 'v=DKIM1; p=ONE'],
            ['hostname' => 'mail.example.test', 'ip' => '1.1.1.1', 'dkim_selector' => 'key1', 'dkim_public_record' => 'v=DKIM1; p=TWO'],
        ], ['tracking' => false, 'imap' => false, 'spf' => true, 'dkim' => true, 'dmarc' => true]);
    }

    public function test_it_builds_the_reference_records_in_the_observed_order_for_each_option(): void
    {
        $records = (new AlphaLinkBulkInstallDnsRecordPlanner)->records(true, [
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
        $records = (new AlphaLinkBulkInstallDnsRecordPlanner)->records(true, [[
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
        $records = (new AlphaLinkBulkInstallDnsRecordPlanner)->records(true, [
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

        (new AlphaLinkBulkInstallDnsRecordPlanner)->records(true, [[
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
        $records = (new AlphaLinkBulkInstallDnsRecordPlanner)->records(false, [[
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

    public function test_secondary_owned_domain_without_main_flag_still_gets_tracking_and_auth_records(): void
    {
        $records = (new AlphaLinkBulkInstallDnsRecordPlanner)->records(true, [[
            'main' => false,
            'ip' => '198.51.100.80',
            'type' => 'ipv4',
            'sub' => '',
            'domain' => 'secondary.example.test',
        ]], [
            'tracking' => true,
            'imap' => false,
            'spf' => true,
            'dkim' => true,
            'dmarc' => true,
        ], 'al123', 'v=DKIM1;t=s;p=KEY');

        $this->assertSame([
            ['host' => '@', 'address' => '198.51.100.80', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'www', 'address' => '198.51.100.80', 'type' => 'A', 'ttl' => '60'],
            ['host' => '*', 'address' => '198.51.100.80', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'al123._domainkey', 'address' => 'v=DKIM1;t=s;p=KEY', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '_dmarc', 'address' => 'v=DMARC1; p=none; fo=1; rua=mailto:dmarc@secondary.example.test; ruf=mailto:dmarc@secondary.example.test', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '@', 'address' => 'v=spf1 ip4:198.51.100.80 -all', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '*', 'address' => 'v=spf1 ip4:198.51.100.80 -all', 'type' => 'TXT', 'ttl' => '60'],
        ], $records);
    }
}
