<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsBulkInstallVmtaPlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DmsBulkInstallVmtaPlannerTest extends TestCase
{
    public function test_centos9_builds_per_ip_and_per_isp_vmtas_owned_domain_inbound_and_feedback_loop(): void
    {
        $files = (new DmsBulkInstallVmtaPlanner)->files('centos9', true, [
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
        $files = (new DmsBulkInstallVmtaPlanner)->files('centos7', false, [[
            'ip' => '8.8.8.8',
            'sub' => 'relay-two',
            'domain' => 'example.test',
        ]], [], 'selector-one', true, false);

        $this->assertStringContainsString('host-name example.test', $files['vmtas']);
        $this->assertNull($files['dkim_domains']);
        $this->assertNull($files['inbound']);
    }

    public function test_it_rejects_a_platform_not_selected_by_the_dms_factory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DmsBulkInstallVmtaPlanner)->files('ubuntu', false, [], [], 'selector-one', false, false);
    }
}
