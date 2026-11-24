<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkMultiDomainInstallPlan;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AlphaLinkMultiDomainInstallPlanTest extends TestCase
{
    public function test_one_physical_ip_keeps_a_single_ehlo_and_has_independent_domain_and_tracking_identities(): void
    {
        $plan = (new AlphaLinkMultiDomainInstallPlan)->build([[
            'ip' => '8.8.8.8', 'type' => 'ipv4', 'main' => true,
            'sub' => 'mail', 'domain_id' => 'domain-1', 'domain' => 'example.com', 'is_our' => true,
            'domains' => [[
                'sub' => 'offers', 'domain_id' => 'domain-1', 'domain' => 'example.com', 'is_our' => true,
            ], [
                'sub' => '', 'domain_id' => 'domain-2', 'domain' => 'other.example', 'is_our' => true,
            ]],
        ]]);

        $this->assertCount(1, $plan['ips']);
        $this->assertSame('mail.example.com', $plan['ips'][0]['ehlo_hostname']);
        $this->assertSame($plan['assignments'][0]['tracking_host'], $plan['ips'][0]['tracking_host']);
        $this->assertSame($plan['assignments'][1]['tracking_host'], $plan['ips'][0]['domains'][0]['tracking_host']);
        $this->assertSame(['mail.example.com', 'offers.example.com', 'other.example'], array_column($plan['assignments'], 'hostname'));
        $this->assertCount(3, $plan['tracking_hosts']);
        $this->assertCount(2, $plan['dns_groups']);
        $this->assertSame('domain-1', $plan['dns_groups'][0]['domain_id']);
        $this->assertCount(2, $plan['dns_groups'][0]['assignments']);
        foreach ($plan['assignments'] as $assignment) {
            $this->assertStringEndsWith('.'.$assignment['hostname'], $assignment['tracking_host']);
            $this->assertStringStartsWith('track-', $assignment['tracking_host']);
        }
    }

    public function test_same_sending_hostname_on_two_ips_has_separate_tracking_hosts(): void
    {
        $rows = [
            ['ip' => '8.8.8.8', 'main' => true, 'sub' => 'mail', 'domain_id' => 'domain-1', 'domain' => 'example.com', 'domains' => [['sub' => 'offers', 'domain_id' => 'domain-1', 'domain' => 'example.com']]],
            ['ip' => '9.9.9.9', 'main' => false, 'sub' => 'mail', 'domain_id' => 'domain-1', 'domain' => 'example.com', 'domains' => []],
        ];
        $plan = (new AlphaLinkMultiDomainInstallPlan)->build($rows);

        $this->assertNotSame($plan['assignments'][0]['tracking_host'], $plan['assignments'][2]['tracking_host']);
    }

    public function test_duplicate_hostname_on_one_ip_is_rejected(): void
    {
        try {
            (new AlphaLinkMultiDomainInstallPlan)->build([[
                'ip' => '8.8.8.8', 'main' => true, 'sub' => 'mail', 'domain_id' => 'domain-1', 'domain' => 'example.com',
                'domains' => [['sub' => 'mail', 'domain_id' => 'domain-1', 'domain' => 'example.com']],
            ]]);
            $this->fail('The same sending hostname must not be assigned twice to one physical IP.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('only once', $exception->getMessage());
            $this->assertStringContainsString('mail.example.com', $exception->getMessage());
        }
    }

    public function test_conflicting_ehlo_is_rejected_before_provisioning(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EHLO hostname conflicts');
        (new AlphaLinkMultiDomainInstallPlan)->build([[
            'ip' => '8.8.8.8', 'main' => true, 'sub' => 'mail', 'domain_id' => 'domain-1', 'domain' => 'example.com',
            'ehlo_hostname' => 'other.example.com',
            'domains' => [['sub' => 'offers', 'domain_id' => 'domain-1', 'domain' => 'example.com']],
        ]]);
    }

    public function test_more_than_100_tracking_hosts_is_rejected_for_the_current_certificate_flow(): void
    {
        $rows = [];
        for ($index = 1; $index <= 51; $index++) {
            $rows[] = [
                'ip' => '198.51.100.'.$index,
                'main' => $index === 1,
                'sub' => 'mail'.$index,
                'domain_id' => 'domain-1',
                'domain' => 'example.com',
                'domains' => [[
                    'sub' => 'offers'.$index,
                    'domain_id' => 'domain-1',
                    'domain' => 'example.com',
                ]],
            ];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 100 HTTPS tracking hostnames');
        (new AlphaLinkMultiDomainInstallPlan)->build($rows);
    }
}
