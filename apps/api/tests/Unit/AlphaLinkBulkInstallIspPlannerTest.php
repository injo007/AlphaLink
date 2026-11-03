<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkBulkInstallIspPlanner;
use App\Services\Infrastructure\AlphaLinkBulkInstallVmtaPlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AlphaLinkBulkInstallIspPlannerTest extends TestCase
{
    public function test_it_lowercases_all_names_and_retains_the_first_case_insensitive_occurrence(): void
    {
        $this->assertSame(
            ['gmail', 'yahoo', 'outlook'],
            (new AlphaLinkBulkInstallIspPlanner)->buildVmtaNames(['Gmail', 'Yahoo', 'gmail', 'Outlook', 'YAHOO']),
        );
    }

    public function test_it_passes_the_reference_isp_names_to_each_per_ip_vmta_branch(): void
    {
        $isps = (new AlphaLinkBulkInstallIspPlanner)->buildVmtaNames(['Gmail', 'Yahoo', 'gmail']);
        $files = (new AlphaLinkBulkInstallVmtaPlanner)->files('centos7', false, [[
            'ip' => '8.8.8.8',
            'sub' => '',
            'domain' => 'example.test',
        ]], $isps, 'selector-one', false, false);

        $this->assertSame(
            "<virtual-mta mta-8.8.8.8>\n".
            "   smtp-source-ip 8.8.8.8\n".
            "   host-name example.test\n".
            "</virtual-mta>\n".
            "<virtual-mta mta-8.8.8.8-gmail>\n".
            "   smtp-source-ip 8.8.8.8\n".
            "   host-name example.test\n".
            "</virtual-mta>\n".
            "<virtual-mta mta-8.8.8.8-yahoo>\n".
            "   smtp-source-ip 8.8.8.8\n".
            "   host-name example.test\n".
            "</virtual-mta>\n",
            $files['vmtas'],
        );
    }

    public function test_it_rejects_non_string_isp_names_and_accepts_an_empty_table(): void
    {
        $planner = new AlphaLinkBulkInstallIspPlanner;

        $this->assertSame([], $planner->buildVmtaNames([]));

        $this->expectException(InvalidArgumentException::class);
        $planner->buildVmtaNames(['Gmail', null]);
    }
}
