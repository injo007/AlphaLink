<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsBulkInstallInstallerMatrix;
use App\Services\Infrastructure\DmsInstallPlatform;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DmsBulkInstallInstallerMatrixTest extends TestCase
{
    public function test_it_maps_supported_os_and_pmta_variants_to_dms_assets_and_branch_differences(): void
    {
        $matrix = new DmsBulkInstallInstallerMatrix(new DmsInstallPlatform);

        $legacy = $matrix->plan('CentOS Linux 7 (Core)', 40, $this->options());
        $this->assertSame('centos7', $legacy['os_family']);
        $this->assertSame('yum', $legacy['package_manager']);
        $this->assertSame([
            'install/pmta_40/pmta.rpm',
            'install/pmta_40/pmtahttp_patch',
            'install/pmta_40/pmta.lic',
            'install/pmta_40/pmta_domains',
            'install/pmta_source',
            'install/pmta_config',
        ], $legacy['pmta']['referenced_assets']);
        $this->assertSame('/usr/sbin/pmtahttpd', $legacy['pmta']['patch_target']);
        $this->assertSame(1, $legacy['pmta']['admin_http_port_multiplier']);
        $this->assertNull($legacy['pmta']['reverse_proxy']);

        foreach ([45, 50] as $version) {
            $centos7 = $matrix->plan('CentOS Linux 7', $version, $this->options());
            $this->assertSame('yum', $centos7['package_manager']);
            $this->assertSame("install/pmta_{$version}/pmtad_patch", $centos7['pmta']['referenced_assets'][1]);
            $this->assertContains('install/nginx_repo', $centos7['pmta']['referenced_assets']);
            $this->assertSame('/usr/sbin/pmtad', $centos7['pmta']['patch_target']);
            $this->assertSame(2, $centos7['pmta']['admin_http_port_multiplier']);
            $this->assertSame('nginx', $centos7['pmta']['reverse_proxy']);
            $this->assertSame($version === 50
                ? ['http-redirect-to-https false', 'http-csrf-protection false']
                : [], $centos7['pmta']['extra_config_lines']);

            $centos9 = $matrix->plan('Rocky Linux 9.4', $version, $this->options());
            $this->assertSame('centos9', $centos9['os_family']);
            $this->assertSame('dnf', $centos9['package_manager']);
            $this->assertContains('install/nginx_config', $centos9['pmta']['referenced_assets']);
            $this->assertNotContains('install/nginx_repo', $centos9['pmta']['referenced_assets']);
        }

        $this->assertSame('centos9', $matrix->plan('AlmaLinux 9.6', 40, $this->options())['os_family']);
        $this->assertSame('centos9', $matrix->plan('CentOS Stream 9', 40, $this->options())['os_family']);
        $this->assertSame(['rpm_and_patch_both_absent' => true], $legacy['pmta']['dms_skip_gate']);
        $this->assertTrue($matrix->dmsSkipsPmtaVersionSetup(false, false));
        $this->assertFalse($matrix->dmsSkipsPmtaVersionSetup(true, false));
        $this->assertFalse($matrix->dmsSkipsPmtaVersionSetup(false, true));
        $this->assertFalse($matrix->dmsSkipsPmtaVersionSetup(true, true));
    }

    public function test_it_reports_selected_option_assets_and_source_skip_behavior(): void
    {
        $matrix = new DmsBulkInstallInstallerMatrix(new DmsInstallPlatform);
        $options = $this->options(true);
        $requirements = $matrix->plan('AlmaLinux 9', 50, $options)['selected_option_requirements'];

        $this->assertSame([
            'expected_assets' => ['install/mini_app.zip'],
            'optional_assets' => ['install/cf_ips.conf'],
            'when_mini_app_missing' => 'DMS still configures Apache but skips uploading and configuring the mini app.',
        ], $requirements['tracking']);
        $this->assertSame('install/squid_centos.conf', $requirements['proxy']['expected_assets'][0]);
        $this->assertSame('DMS returns without installing or enabling Squid.', $requirements['proxy']['when_asset_missing']);
        $this->assertSame('install/dovecot_centos.conf', $requirements['imap']['expected_assets'][0]);
        $this->assertSame('DMS returns without installing or enabling Dovecot.', $requirements['imap']['when_asset_missing']);
        $this->assertSame('install/dkim.json', $requirements['dkim']['expected_assets'][0]);
        $this->assertSame('DMS omits the DKIM DNS record when no key is configured.', $requirements['dkim']['when_key_missing']);
        $this->assertSame([], $requirements['https']['local_assets']);
        $this->assertArrayNotHasKey('spf', $requirements);
        $this->assertArrayNotHasKey('dmarc', $requirements);
    }

    public function test_it_rejects_unsupported_os_versions_and_option_shapes(): void
    {
        $matrix = new DmsBulkInstallInstallerMatrix(new DmsInstallPlatform);

        try {
            $matrix->plan('Ubuntu 24.04', 40, $this->options());
            $this->fail('Unsupported releases must not select a DMS installer.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('DMS installer matrix requires a supported CentOS 7 or 9 release.', $exception->getMessage());
        }

        try {
            $matrix->plan('CentOS Linux 7', 46, $this->options());
            $this->fail('Unsupported PMTA versions must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('DMS installer matrix supports PMTA versions 40, 45, and 50.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('DMS installer matrix requires all seven install options.');
        $matrix->plan('CentOS Linux 7', 40, ['tracking' => false]);
    }

    /** @return array{tracking: bool, proxy: bool, imap: bool, spf: bool, dkim: bool, dmarc: bool, https: bool} */
    private function options(bool $enabled = false): array
    {
        return [
            'tracking' => $enabled,
            'proxy' => $enabled,
            'imap' => $enabled,
            'spf' => $enabled,
            'dkim' => $enabled,
            'dmarc' => $enabled,
            'https' => $enabled,
        ];
    }
}
