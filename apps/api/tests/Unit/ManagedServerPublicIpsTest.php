<?php

namespace Tests\Unit;

use App\Models\ManagedServer;
use PHPUnit\Framework\TestCase;

class ManagedServerPublicIpsTest extends TestCase
{
    public function test_it_resolves_primary_and_additional_public_ips_from_host_facts(): void
    {
        $server = new ManagedServer([
            'ip_address' => '51.68.165.23',
            'host_facts' => [
                'public_ips' => [
                    '57.129.51.192',
                    '54.37.78.255',
                    '57.131.128.57',
                    '57.131.128.106',
                    '57.129.92.64',
                    '51.68.165.23',
                ],
            ],
        ]);

        $ips = $server->publicIps();

        $this->assertSame('51.68.165.23', $ips[0]);
        $this->assertCount(6, $ips);
        $this->assertContains('57.129.51.192', $ips);
        $this->assertContains('54.37.78.255', $ips);
        $this->assertContains('57.131.128.57', $ips);
        $this->assertContains('57.131.128.106', $ips);
        $this->assertContains('57.129.92.64', $ips);
    }

    public function test_it_filters_out_private_ips(): void
    {
        $server = new ManagedServer([
            'ip_address' => '51.68.165.23',
            'host_facts' => [
                'public_ips' => [
                    '10.2.1.46',
                    '10.2.1.78',
                    '192.168.1.1',
                    '57.131.128.106',
                ],
            ],
        ]);

        $ips = $server->publicIps();

        $this->assertSame(['51.68.165.23', '57.131.128.106'], $ips);
    }

    public function test_it_falls_back_to_primary_ip_when_no_facts_or_relations_present(): void
    {
        $server = new ManagedServer([
            'ip_address' => '51.68.165.23',
        ]);

        $this->assertSame(['51.68.165.23'], $server->publicIps());
    }
}
