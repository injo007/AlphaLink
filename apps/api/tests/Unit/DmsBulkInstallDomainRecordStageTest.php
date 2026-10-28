<?php

namespace Tests\Unit;

use App\Models\SendingDomain;
use App\Services\Infrastructure\DmsBulkInstallDnsRecordPlanner;
use App\Services\Infrastructure\DmsBulkInstallDomainDnsRecordWriter;
use App\Services\Infrastructure\DmsBulkInstallDomainRecordStage;
use App\Services\Infrastructure\DmsBulkInstallPublicIpResolver;
use App\Services\Infrastructure\DmsCentos9PublicIpResolver;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
use PHPUnit\Framework\TestCase;

class DmsBulkInstallDomainRecordStageTest extends TestCase
{
    public function test_external_installable_domain_receives_records_through_its_provider(): void
    {
        $domain = new SendingDomain(['name' => 'external.example.test', 'is_our' => false]);
        $resolver = new RecordingPublicIpResolver(['10.0.0.8' => '8.8.8.8']);
        $writer = new RecordingDnsRecordWriter;
        $logs = [];

        $this->stage()->run(
            $domain,
            [$this->ip('10.0.0.8')],
            $this->options(),
            's1',
            'public-key',
            $resolver,
            $writer,
            function (string $message) use (&$logs): void {
                $logs[] = $message;
            },
        );

        $this->assertSame(['10.0.0.8'], $resolver->ips);
        $this->assertCount(1, $writer->calls);
        $this->assertTrue($domain->is($writer->calls[0]['domain']));
        $this->assertSame('A', $writer->calls[0]['records'][0]['type']);
        $this->assertSame('8.8.8.8', $writer->calls[0]['records'][0]['address']);
        $this->assertSame([
            'Config Hosts A-AAAA-MX... For external.example.test (A=3)',
            'Done Setting Records For external.example.test (A=3)',
        ], $logs);
    }

    public function test_unresolved_private_interface_never_reaches_dns_provider(): void
    {
        $domain = new SendingDomain(['name' => 'external.example.test', 'is_our' => false]);
        $writer = new RecordingDnsRecordWriter;
        $logs = [];

        try {
            $this->stage()->run(
                $domain,
                [$this->ip('10.0.0.8')],
                $this->options(),
                's1',
                null,
                new RecordingPublicIpResolver,
                $writer,
                function (string $message) use (&$logs): void {
                    $logs[] = $message;
                },
            );
            $this->fail('Private interface IP must not be published.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('A selected interface has no verified public IP for DNS records.', $exception->getMessage());
        }

        $this->assertSame([], $writer->calls);
        $this->assertSame([], $logs);
    }

    public function test_unresolved_private_aliases_use_the_registered_public_ipv4_for_dns(): void
    {
        $domain = new SendingDomain(['name' => 'clarionmatrix.fun', 'is_our' => true]);
        $resolver = new RecordingPublicIpResolver;
        $writer = new RecordingDnsRecordWriter;
        $logs = [];
        $ips = [
            ['main' => true, 'ip' => '164.92.159.209', 'type' => 'ipv4', 'sub' => '', 'domain' => 'clarionmatrix.fun'],
            ['main' => false, 'ip' => '10.18.0.6', 'type' => 'ipv4', 'sub' => 'hilarious', 'domain' => 'clarionmatrix.fun'],
            ['main' => false, 'ip' => '10.110.0.5', 'type' => 'ipv4', 'sub' => 'talk', 'domain' => 'clarionmatrix.fun'],
        ];

        $this->stage()->run(
            $domain,
            $ips,
            ['tracking' => true, 'imap' => false, 'spf' => true, 'dkim' => false, 'dmarc' => false],
            's1',
            null,
            $resolver,
            $writer,
            function (string $message) use (&$logs): void {
                $logs[] = $message;
            },
            '164.92.159.209',
        );

        $this->assertSame(['10.18.0.6', '10.110.0.5'], $resolver->ips);
        $this->assertSame([
            ['host' => '@', 'address' => '164.92.159.209', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'www', 'address' => '164.92.159.209', 'type' => 'A', 'ttl' => '60'],
            ['host' => '*', 'address' => '164.92.159.209', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'hilarious', 'address' => '164.92.159.209', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'talk', 'address' => '164.92.159.209', 'type' => 'A', 'ttl' => '60'],
            ['host' => '@', 'address' => 'v=spf1 ip4:164.92.159.209 -all', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '*', 'address' => 'v=spf1 ip4:164.92.159.209 -all', 'type' => 'TXT', 'ttl' => '60'],
        ], $writer->calls[0]['records']);
        $this->assertStringNotContainsString('10.18.0.6', json_encode($writer->calls[0]['records'], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('10.110.0.5', json_encode($writer->calls[0]['records'], JSON_THROW_ON_ERROR));
        $this->assertContains(
            'Using selected public ipv4 address 164.92.159.209 as the DNS target for private interface 10.18.0.6 at hilarious.clarionmatrix.fun because its interface egress lookup could not be verified.',
            $logs,
        );
        $this->assertContains(
            'Using selected public ipv4 address 164.92.159.209 as the DNS target for private interface 10.110.0.5 at talk.clarionmatrix.fun because its interface egress lookup could not be verified.',
            $logs,
        );
    }

    public function test_private_interface_uses_the_registered_server_ipv4_when_public_row_is_not_a_host_interface(): void
    {
        $resolved = $this->stage()->resolvePublicAddresses(
            [[
                'main' => true,
                'ip' => '10.18.0.6',
                'type' => 'ipv4',
                'sub' => 'relay',
                'domain' => 'example.test',
            ]],
            new RecordingPublicIpResolver,
            null,
            '164.92.159.209',
        );

        $this->assertSame('164.92.159.209', $resolved[0]['ip']);
        $this->assertSame('relay', $resolved[0]['sub']);
        $this->assertSame('ipv4', $resolved[0]['type']);
    }

    public function test_owned_domain_records_use_resolved_interface_ip_and_dms_provider_outcome_logs(): void
    {
        $domain = new SendingDomain(['name' => 'owned.example.test', 'is_our' => true]);
        $resolver = new RecordingPublicIpResolver(['10.0.0.8' => '198.51.100.8']);
        $writer = new RecordingDnsRecordWriter(true);
        $logs = [];

        $this->stage()->run(
            $domain,
            [$this->ip('10.0.0.8')],
            $this->options(),
            's1',
            null,
            $resolver,
            $writer,
            function (string $message) use (&$logs): void {
                $logs[] = $message;
            },
        );

        $this->assertSame(['10.0.0.8'], $resolver->ips);
        $this->assertTrue($domain->is($writer->calls[0]['domain']));
        $this->assertSame([
            ['host' => '@', 'address' => '198.51.100.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => 'www', 'address' => '198.51.100.8', 'type' => 'A', 'ttl' => '60'],
            ['host' => '*', 'address' => '198.51.100.8', 'type' => 'A', 'ttl' => '60'],
        ], $writer->calls[0]['records']);
        $this->assertSame([
            'Config Hosts A-AAAA-MX... For owned.example.test (A=3)',
            'Done Setting Records For owned.example.test (A=3)',
        ], $logs);
    }

    public function test_selected_public_ip_records_are_written_without_optional_dns_options(): void
    {
        $domain = new SendingDomain(['name' => 'owned.example.test', 'is_our' => true]);
        $resolver = new RecordingPublicIpResolver;
        $writer = new RecordingDnsRecordWriter;
        $logs = [];

        $this->stage()->run(
            $domain,
            [$this->ip('8.8.8.8')],
            ['tracking' => false, 'imap' => false, 'spf' => false, 'dkim' => false, 'dmarc' => false],
            's1',
            null,
            $resolver,
            $writer,
            function (string $message) use (&$logs): void {
                $logs[] = $message;
            },
        );

        $this->assertCount(1, $writer->calls);
        $this->assertSame([
            ['host' => '@', 'address' => '8.8.8.8', 'type' => 'A', 'ttl' => '60'],
        ], $writer->calls[0]['records']);
        $this->assertSame([
            'Config Hosts A-AAAA-MX... For owned.example.test (A=1)',
            'Done Setting Records For owned.example.test (A=1)',
        ], $logs);
    }

    public function test_unresolved_private_root_interface_is_skipped_when_a_public_root_address_is_selected(): void
    {
        $domain = new SendingDomain(['name' => 'owned.example.test', 'is_our' => true]);
        $resolver = new RecordingPublicIpResolver;
        $writer = new RecordingDnsRecordWriter;
        $logs = [];

        $this->stage()->run(
            $domain,
            [
                $this->ip('10.3.1.195'),
                ['main' => true, 'ip' => '51.83.224.78', 'type' => 'ipv4', 'sub' => '', 'domain' => 'owned.example.test'],
                ['main' => false, 'ip' => '2001:41d0:601:2000::e65', 'type' => 'ipv6', 'sub' => '', 'domain' => 'owned.example.test'],
            ],
            ['tracking' => false, 'imap' => false, 'spf' => true, 'dkim' => false, 'dmarc' => false],
            's1',
            null,
            $resolver,
            $writer,
            function (string $message) use (&$logs): void {
                $logs[] = $message;
            },
        );

        $this->assertSame(['10.3.1.195'], $resolver->ips);
        $this->assertSame([
            ['host' => '@', 'address' => '51.83.224.78', 'type' => 'A', 'ttl' => '60'],
            ['host' => '@', 'address' => '2001:41d0:601:2000::e65', 'type' => 'AAAA', 'ttl' => '60'],
            ['host' => '@', 'address' => 'v=spf1 ip4:51.83.224.78 ip6:2001:41d0:601:2000::e65 -all', 'type' => 'TXT', 'ttl' => '60'],
            ['host' => '*', 'address' => 'v=spf1 ip4:51.83.224.78 ip6:2001:41d0:601:2000::e65 -all', 'type' => 'TXT', 'ttl' => '60'],
        ], $writer->calls[0]['records']);
        $this->assertStringNotContainsString('10.3.1.195', json_encode($writer->calls[0]['records'], JSON_THROW_ON_ERROR));
        $this->assertSame([
            'Skipping unverified private interface 10.3.1.195 for @.owned.example.test; a verified public ipv4 address is already selected.',
            'Config Hosts A-AAAA-MX... For owned.example.test (A=1, AAAA=1, TXT=2)',
            'Done Setting Records For owned.example.test (A=1, AAAA=1, TXT=2)',
        ], $logs);
    }

    public function test_failed_dns_provider_never_reports_records_as_set(): void
    {
        $domain = new SendingDomain(['name' => 'external.example.test', 'is_our' => false]);
        $writer = new RecordingDnsRecordWriter(false);
        $logs = [];

        try {
            $this->stage()->run(
                $domain,
                [$this->ip('8.8.8.8')],
                $this->options(),
                's1',
                null,
                new RecordingPublicIpResolver,
                $writer,
                function (string $message) use (&$logs): void {
                    $logs[] = $message;
                },
            );
            $this->fail('A failed provider update must stop the install stage.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('The selected domain DNS records could not be applied through its provider.', $exception->getMessage());
        }

        $this->assertSame(['Config Hosts A-AAAA-MX... For external.example.test (A=3)'], $logs);
    }

    public function test_centos9_public_ip_lookup_matches_dms_command_and_falls_back_for_invalid_output(): void
    {
        $transport = new RecordingPublicIpCommandTransport([" 198.51.100.8\n", 'not-an-ip']);
        $resolver = new DmsCentos9PublicIpResolver($transport);

        $this->assertSame('198.51.100.8', $resolver->resolve('10.0.0.8'));
        $this->assertSame('10.0.0.8', $resolver->resolve('10.0.0.8'));
        $this->assertSame([
            "curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.8' https://ifconfig.me/ip 2>/dev/null",
            "curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.8' https://ifconfig.me/ip 2>/dev/null",
            "curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.8' https://api.ipify.org 2>/dev/null",
            "curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.8' https://icanhazip.com 2>/dev/null",
            "curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.8' https://ident.me 2>/dev/null",
        ], $transport->commands);
    }

    public function test_centos9_public_ip_lookup_uses_an_independent_check_when_the_first_endpoint_fails(): void
    {
        $transport = new RecordingPublicIpCommandTransport(['', " 8.8.4.4\n"]);
        $resolver = new DmsCentos9PublicIpResolver($transport);

        $this->assertSame('8.8.4.4', $resolver->resolve('10.0.0.8'));
        $this->assertSame([
            "curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.8' https://ifconfig.me/ip 2>/dev/null",
            "curl -fsS --connect-timeout 3 --max-time 6 --interface '10.0.0.8' https://api.ipify.org 2>/dev/null",
        ], $transport->commands);
    }

    public function test_centos9_public_ip_lookup_does_not_mask_an_ssh_channel_failure(): void
    {
        $resolver = new DmsCentos9PublicIpResolver(new RecordingPublicIpCommandTransport([false]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The remote server connection was lost while checking a selected interface public address.');

        $resolver->resolve('10.0.0.8');
    }

    private function stage(): DmsBulkInstallDomainRecordStage
    {
        return new DmsBulkInstallDomainRecordStage(new DmsBulkInstallDnsRecordPlanner);
    }

    /** @return array{main: bool, ip: string, type: string, sub: string, domain: string} */
    private function ip(string $ip): array
    {
        return ['main' => true, 'ip' => $ip, 'type' => 'ipv4', 'sub' => '', 'domain' => 'owned.example.test'];
    }

    /** @return array{tracking: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool} */
    private function options(): array
    {
        return ['tracking' => true, 'imap' => false, 'spf' => false, 'dkim' => false, 'dmarc' => false];
    }
}

class RecordingPublicIpResolver implements DmsBulkInstallPublicIpResolver
{
    /** @var list<string> */
    public array $ips = [];

    /** @param array<string, string> $resolved */
    public function __construct(private readonly array $resolved = []) {}

    public function resolve(string $interfaceIp): string
    {
        $this->ips[] = $interfaceIp;

        return $this->resolved[$interfaceIp] ?? $interfaceIp;
    }
}

class RecordingDnsRecordWriter implements DmsBulkInstallDomainDnsRecordWriter
{
    /** @var list<array{domain: SendingDomain, records: list<array{host: string, address: string, type: string, ttl: string}>}> */
    public array $calls = [];

    public function __construct(private readonly bool $result = true) {}

    public function setHosts(SendingDomain $domain, array $records): bool
    {
        $this->calls[] = ['domain' => $domain, 'records' => $records];

        return $this->result;
    }
}

class RecordingPublicIpCommandTransport implements DmsInstallerRemoteCommandTransport
{
    /** @var list<string> */
    public array $commands = [];

    /** @param list<bool|string> $results */
    public function __construct(private readonly array $results = []) {}

    public function exec(string $command): bool|string
    {
        $this->commands[] = $command;

        return $this->results[count($this->commands) - 1] ?? '';
    }
}
