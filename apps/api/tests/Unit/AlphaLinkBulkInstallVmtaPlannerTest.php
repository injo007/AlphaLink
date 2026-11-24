<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkBulkInstallVmtaPlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AlphaLinkBulkInstallVmtaPlannerTest extends TestCase
{
    public function test_centos9_builds_per_ip_and_per_isp_vmtas_owned_domain_inbound_and_feedback_loop(): void
    {
        $files = (new AlphaLinkBulkInstallVmtaPlanner)->files('centos9', true, [
            ['ip' => '8.8.8.8', 'sub' => '', 'domain' => 'example.test'],
            ['ip' => '2001:4860:4860::8888', 'sub' => 'relay-two', 'domain' => 'example.test'],
        ], ['gmail'], 'selector-one', true, true);

        $this->assertSame(
            "<virtual-mta mta-8.8.8.8>\n".
            "   smtp-source-ip 8.8.8.8\n".
            "   host-name example.test\n".
            "</virtual-mta>\n".
            "<virtual-mta mta-8.8.8.8-gmail>\n".
            "   smtp-source-ip 8.8.8.8\n".
            "   host-name example.test\n".
            "</virtual-mta>\n".
            "<virtual-mta mta-2001.4860.4860..8888>\n".
            "   smtp-source-ip 2001:4860:4860::8888\n".
            "   host-name relay-two.example.test\n".
            "</virtual-mta>\n".
            "<virtual-mta mta-2001.4860.4860..8888-gmail>\n".
            "   smtp-source-ip 2001:4860:4860::8888\n".
            "   host-name relay-two.example.test\n".
            "</virtual-mta>\n",
            $files['vmtas'],
        );
        $this->assertSame(
            "domain-key selector-one,example.test,/etc/pmta/dkim\n".
            "domain-key selector-one,relay-two.example.test,/etc/pmta/dkim\n",
            $files['dkim_domains'],
        );
        $this->assertSame(
            "relay-domain example.test\n".
            "<domain example.test>\n".
            "   type file\n".
            "   file-format append-mbox\n".
            "   file-destination /etc/pmta/inbound/\$domain\n".
            "</domain>\n".
            "relay-domain relay-two.example.test\n".
            "<domain relay-two.example.test>\n".
            "   type file\n".
            "   file-format append-mbox\n".
            "   file-destination /etc/pmta/inbound/\$domain\n".
            "</domain>\n\n".
            "<feedback-loop-processor>\n".
            "<address-list>\n".
            "    address /fbl@example.test/\n".
            "    address /fbl@relay-two.example.test/\n".
            "</address-list>\n".
            "</feedback-loop-processor>\n",
            $files['inbound'],
        );
    }

    public function test_centos7_omits_feedback_loop_and_external_domain_omits_inbound(): void
    {
        $files = (new AlphaLinkBulkInstallVmtaPlanner)->files('centos7', false, [[
            'ip' => '8.8.8.8',
            'sub' => 'relay-two',
            'domain' => 'example.test',
        ]], [], 'selector-one', true, false);

        $this->assertStringContainsString('host-name example.test', $files['vmtas']);
        $this->assertNull($files['dkim_domains']);
        $this->assertNull($files['inbound']);
    }

    public function test_centos9_external_subdomain_matches_its_dkim_dns_record_name(): void
    {
        $files = (new AlphaLinkBulkInstallVmtaPlanner)->files('centos9', false, [[
            'ip' => '8.8.8.8', 'sub' => 'relay-two', 'domain' => 'example.test',
        ]], [], 'selector-one', true, true);

        $this->assertStringContainsString('host-name relay-two.example.test', $files['vmtas']);
        $this->assertSame("domain-key selector-one,relay-two.example.test,/etc/pmta/dkim\n", $files['dkim_domains']);
        $this->assertNull($files['inbound']);
    }

    public function test_it_rejects_a_platform_not_selected_by_the_reference_factory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AlphaLinkBulkInstallVmtaPlanner)->files('ubuntu', false, [], [], 'selector-one', false, false);
    }

    public function test_multiple_domains_share_one_ip_and_ehlo_but_use_independent_dkim_keys(): void
    {
        $files = (new AlphaLinkBulkInstallVmtaPlanner)->files('centos9', true, [[
            'ip' => '8.8.8.8',
            'sub' => 'mail',
            'domain' => 'example.test',
            'ehlo_hostname' => 'mail.example.test',
            'domains' => [['sub' => 'offers', 'domain' => 'other.test', 'is_our' => true]],
        ]], ['gmail'], 'legacy', true, true, [
            'mail.example.test' => ['selector' => 'one', 'path' => '/etc/pmta/dkim-keys/mail.example.test.pem'],
            'offers.other.test' => ['selector' => 'two', 'path' => '/etc/pmta/dkim-keys/offers.other.test.pem'],
        ]);

        $this->assertSame(2, substr_count($files['vmtas'], '<virtual-mta '));
        $this->assertSame(2, substr_count($files['vmtas'], 'host-name mail.example.test'));
        $this->assertSame(
            "domain-key one,mail.example.test,/etc/pmta/dkim-keys/mail.example.test.pem\n".
            "domain-key two,offers.other.test,/etc/pmta/dkim-keys/offers.other.test.pem\n",
            $files['dkim_domains'],
        );
        $this->assertStringContainsString('relay-domain offers.other.test', $files['inbound']);
    }

    public function test_multiple_domains_cannot_fall_back_to_the_shared_legacy_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AlphaLinkBulkInstallVmtaPlanner)->files('centos9', true, [[
            'ip' => '8.8.8.8', 'sub' => 'mail', 'domain' => 'example.test',
            'domains' => [['sub' => 'offers', 'domain' => 'other.test']],
        ]], [], 'legacy', true, true);
    }

    public function test_legacy_single_domain_without_dkim_does_not_require_a_selector(): void
    {
        $files = (new AlphaLinkBulkInstallVmtaPlanner)->files('centos9', false, [[
            'ip' => '8.8.8.8', 'sub' => '', 'domain' => 'example.test',
        ]], [], '', false, false);

        $this->assertNull($files['dkim_domains']);
        $this->assertStringContainsString('host-name example.test', $files['vmtas']);
    }

    public function test_every_selected_domain_needs_a_signing_identity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new AlphaLinkBulkInstallVmtaPlanner)->files('centos9', true, [[
            'ip' => '8.8.8.8', 'sub' => 'mail', 'domain' => 'example.test',
            'domains' => [['sub' => 'offers', 'domain' => 'other.test']],
        ]], [], 'legacy', true, true, [
            'mail.example.test' => ['selector' => 'one', 'path' => '/etc/pmta/dkim-keys/mail.example.test.pem'],
        ]);
    }
}
