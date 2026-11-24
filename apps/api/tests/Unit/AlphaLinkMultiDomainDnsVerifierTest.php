<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkDnsPropagationWaiter;
use App\Services\Infrastructure\AlphaLinkMultiDomainDnsVerifier;
use App\Services\Infrastructure\AlphaLinkPublicDnsRecordResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AlphaLinkMultiDomainDnsVerifierTest extends TestCase
{
    public function test_spf_dkim_dmarc_and_forward_addresses_must_all_resolve(): void
    {
        $dns = $this->createMock(AlphaLinkPublicDnsRecordResolver::class);
        $dns->method('records')->willReturnCallback(static function (string $hostname, int $type): array {
            return match ([$hostname, $type]) {
                ['mail.example.test', DNS_A] => [['ip' => '8.8.8.8']],
                ['track.mail.example.test', DNS_A] => [['ip' => '8.8.8.8']],
                ['s1._domainkey.mail.example.test', DNS_TXT] => [['txt' => 'v=DKIM1; k=rsa; p=AbCd']],
                ['_dmarc.mail.example.test', DNS_TXT] => [['txt' => 'v=DMARC1; p=none']],
                ['mail.example.test', DNS_TXT] => [['txt' => 'v=spf1 ip4:8.8.8.8 -all']],
                default => [],
            };
        });

        $this->verifier($dns)->verify([$this->assignment()], ['spf' => true, 'dkim' => true, 'dmarc' => true]);
        $this->assertTrue(true);
    }

    public function test_forward_addresses_can_be_checked_without_reverse_dns(): void
    {
        $dns = $this->createMock(AlphaLinkPublicDnsRecordResolver::class);
        $dns->method('records')->willReturnCallback(static function (string $hostname, int $type): array {
            return match ([$hostname, $type]) {
                ['mail.example.test', DNS_A], ['track.mail.example.test', DNS_A] => [['ip' => '8.8.8.8']],
                default => [],
            };
        });

        $this->verifier($dns)->verifyForwardAddresses([$this->assignment()]);
        $this->assertTrue(true);
    }

    public function test_forward_address_verification_retries_dns_propagation(): void
    {
        $trackingLookups = 0;
        $dns = $this->createMock(AlphaLinkPublicDnsRecordResolver::class);
        $dns->method('records')->willReturnCallback(static function (string $hostname, int $type) use (&$trackingLookups): array {
            if ($hostname === 'track.mail.example.test' && $type === DNS_A) {
                return ++$trackingLookups < 2 ? [] : [['ip' => '8.8.8.8']];
            }

            return $hostname === 'mail.example.test' && $type === DNS_A ? [['ip' => '8.8.8.8']] : [];
        });
        $waiter = new AlphaLinkDnsPropagationWaiter(2, 0, static fn (int $microseconds): null => null);

        $this->verifier($dns, $waiter)->verifyForwardAddresses([$this->assignment()]);

        $this->assertSame(2, $trackingLookups);
    }

    public function test_missing_tracking_forward_record_fails_verification(): void
    {
        $dns = $this->createMock(AlphaLinkPublicDnsRecordResolver::class);
        $dns->method('records')->willReturnCallback(static function (string $hostname, int $type): array {
            return $hostname === 'mail.example.test' && $type === DNS_A ? [['ip' => '8.8.8.8']] : [];
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A/AAAA did not resolve');
        $this->verifier($dns)->verifyForwardAddresses([$this->assignment()]);
    }

    public function test_spf_must_authorize_each_assignment_ip(): void
    {
        $dns = $this->createMock(AlphaLinkPublicDnsRecordResolver::class);
        $dns->method('records')->willReturnCallback(static function (string $hostname, int $type): array {
            return match ([$hostname, $type]) {
                ['mail.example.test', DNS_A], ['track.mail.example.test', DNS_A] => [['ip' => '8.8.8.8']],
                ['mail.example.test', DNS_TXT] => [['txt' => 'v=spf1 ip4:1.1.1.1 -all']],
                default => [],
            };
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SPF');
        $this->verifier($dns)->verify([$this->assignment()], ['spf' => true, 'dkim' => true, 'dmarc' => true]);
    }

    private function verifier(
        AlphaLinkPublicDnsRecordResolver&MockObject $dns,
        ?AlphaLinkDnsPropagationWaiter $waiter = null,
    ): AlphaLinkMultiDomainDnsVerifier {
        return new AlphaLinkMultiDomainDnsVerifier($dns, $waiter ?? new AlphaLinkDnsPropagationWaiter(1, 0));
    }

    /** @return array<string, string> */
    private function assignment(): array
    {
        return [
            'ip' => '8.8.8.8', 'hostname' => 'mail.example.test', 'tracking_host' => 'track.mail.example.test',
            'ehlo_hostname' => 'mail.example.test', 'dkim_selector' => 's1',
            'dkim_public_record' => 'v=DKIM1; k=rsa; p=AbCd',
        ];
    }
}
