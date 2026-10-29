<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsCentos9PmtaDeploymentPlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DmsCentos9PmtaDeploymentPlannerTest extends TestCase
{
    public function test_it_maps_pmta_40_assets_ports_access_rules_and_restart_order(): void
    {
        $plan = $this->planner()->plan(
            40,
            true,
            true,
            1212,
            8080,
            ['192.0.2.10'],
            ['198.51.100.20'],
            false,
            "source __IP__\n",
        );

        $this->assertFalse($plan['version_setup_skipped']);
        $this->assertContains([
            'type' => 'asset_upload',
            'source' => 'install/pmta_40/pmtahttp_patch',
            'destination' => '/usr/sbin/pmtahttpd',
        ], $plan['operations']);
        $commands = $this->commands($plan['operations']);
        $this->assertContains("echo -e 'http-access 127.0.0.1 admin\n' > /etc/pmta/settings/access;", $commands);
        $this->assertContains("echo -e 'source 192.0.2.10\n' >> /etc/pmta/settings/access;", $commands);
        $this->assertContains("sed -i 's/__PMTA_ADMIN_PORT__/16160/g' /etc/pmta/settings/config;", $commands);
        $this->assertContains('dnf install -y nginx', $commands);
        $nginx = $this->remoteContent($plan['operations'], '/etc/nginx/conf.d/default.conf');
        $this->assertStringContainsString('listen 8080;', $nginx);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:16160;', $nginx);
        $this->assertSame([
            'systemctl enable pmta;',
            'systemctl enable pmtahttp;',
            'systemctl restart pmta;',
            'systemctl restart pmtahttp;',
        ], array_slice($commands, -4));
    }

    public function test_it_maps_pmta_45_admin_port_and_nginx_restriction_behavior(): void
    {
        $plan = $this->planner()->plan(45, true, true, 1212, 8080, ['192.0.2.10'], ['198.51.100.20'], false, 'src __IP__');
        $commands = $this->commands($plan['operations']);
        $nginx = $this->remoteContent($plan['operations'], '/etc/nginx/conf.d/default.conf');

        $this->assertContains([
            'type' => 'asset_upload',
            'source' => 'install/pmta_45/pmtad_patch',
            'destination' => '/usr/sbin/pmtad',
        ], $plan['operations']);
        $this->assertContains("sed -i 's/__PMTA_ADMIN_PORT__/16160/g' /etc/pmta/settings/config;", $commands);
        $this->assertStringContainsString('listen 8080;', $nginx);
        $this->assertStringContainsString('allow 192.0.2.10;', $nginx);
        $this->assertStringContainsString('allow 198.51.100.20;', $nginx);
        $this->assertStringContainsString('allow 127.0.0.1;', $nginx);
        $this->assertStringContainsString('deny all;', $nginx);
        $this->assertStringContainsString('return 302 /;', $nginx);
        $this->assertContains("echo -e 'http-access 127.0.0.1 admin\n' > /etc/pmta/settings/access;", $commands);
        $this->assertStringContainsString('location = /command {', $nginx);
        $this->assertStringContainsString('# ALPHALINK_PMTA_MONITOR_ACCESS_RESTRICTED', $nginx);
        $this->assertStringNotContainsString('http-access 0/0 monitor', implode("\n", $commands));
        $this->assertNotContains("echo 'http-redirect-to-https false' >> /etc/pmta/settings/config", $commands);
    }

    public function test_saved_dms_admin_port_is_the_public_listener_and_doubled_for_the_backend(): void
    {
        $plan = $this->planner()->plan(45, true, true, 2525, 2588, [], [], false, '');
        $commands = $this->commands($plan['operations']);
        $nginx = $this->remoteContent($plan['operations'], '/etc/nginx/conf.d/default.conf');

        $this->assertContains("sed -i 's/__PMTA_ADMIN_PORT__/5176/g' /etc/pmta/settings/config;", $commands);
        $this->assertStringContainsString('listen 2588;', $nginx);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:5176;', $nginx);
    }

    public function test_pmta_45_public_monitor_setting_opens_nginx_only_and_keeps_backend_loopback_only(): void
    {
        $plan = $this->planner()->plan(45, true, true, 1212, 8080, ['192.0.2.10'], [], true, 'src __IP__');
        $commands = $this->commands($plan['operations']);
        $nginx = $this->remoteContent($plan['operations'], '/etc/nginx/conf.d/default.conf');

        $this->assertContains(
            "echo -e 'http-access 127.0.0.1 admin\n' > /etc/pmta/settings/access;",
            $commands,
        );
        $this->assertStringNotContainsString('http-access 0/0 monitor', implode("\n", $commands));
        $this->assertStringNotContainsString('http-access 0/0 admin', implode("\n", $commands));
        $this->assertStringContainsString('# ALPHALINK_PMTA_MONITOR_ACCESS_PUBLIC', $nginx);
        $this->assertStringContainsString('location = /command {', $nginx);
        $this->assertStringContainsString('deny all;', $nginx);
        $this->assertStringContainsString('listen 8080;', $nginx);
    }

    public function test_pmta_relay_source_ips_authorize_alpha_link_for_smtp_and_admin_without_public_monitor_access(): void
    {
        $source = "<source __IP__>\n    always-allow-relaying yes\n</source>\n";
        $plan = $this->planner()->plan(45, true, true, 2525, 1213, [], [], true, $source, ['198.51.100.44']);
        $commands = $this->commands($plan['operations']);
        $nginx = $this->remoteContent($plan['operations'], '/etc/nginx/conf.d/default.conf');

        $this->assertContains("echo -e 'http-access 127.0.0.1 admin\n' > /etc/pmta/settings/access;", $commands);
        $this->assertContains("echo -e '<source 198.51.100.44>\n    always-allow-relaying yes\n</source>\n' >> /etc/pmta/settings/access;", $commands);
        $this->assertStringNotContainsString('http-access 0/0 admin', implode("\n", $commands));
        $this->assertStringNotContainsString('http-access 198.51.100.44 admin', implode("\n", $commands));
        $this->assertStringContainsString("location = /command {\n    allow 198.51.100.44;\n    deny all;", $nginx);
        $this->assertStringContainsString('location ~* ^/(logs|getFile|listqueue|command|editConfig|enterLak|serviceControl)', $nginx);
    }

    public function test_invalid_pmta_relay_source_is_rejected_before_building_commands(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PMTA relay source IPs must be valid IPv4 addresses.');

        $this->planner()->plan(45, true, true, 2525, 1213, [], [], true, 'source __IP__', ['invalid']);
    }

    public function test_it_maps_pmta_50_directives_and_nginx_restricted_route_behavior(): void
    {
        $plan = $this->planner()->plan(50, true, true, 2525, 8181, [], [], true, "<source __IP__>\n    always-allow-relaying yes\n</source>\n", ['198.51.100.44']);
        $commands = $this->commands($plan['operations']);
        $nginx = $this->remoteContent($plan['operations'], '/etc/nginx/conf.d/default.conf');

        $this->assertContains("echo 'http-redirect-to-https false' >> /etc/pmta/settings/config", $commands);
        $this->assertContains("echo 'http-csrf-protection false' >> /etc/pmta/settings/config", $commands);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:16362;', $nginx);
        $this->assertStringContainsString("proxy_hide_header 'Content-Security-Policy';", $nginx);
        $this->assertStringContainsString('return 200 "{}";', $nginx);
        $this->assertStringContainsString("location = /command {\n    allow 198.51.100.44;\n    deny all;", $nginx);
        $this->assertContains([
            'type' => 'asset_upload',
            'source' => 'install/nginx_config',
            'destination' => '/etc/nginx/nginx.conf',
        ], $plan['operations']);
    }

    public function test_dms_skips_only_when_both_version_assets_are_absent_but_keeps_outer_commands(): void
    {
        $skipped = $this->planner()->plan(40, false, false, 1212, 8080, [], [], false, '');
        $partial = $this->planner()->plan(40, true, false, 1212, 8080, [], [], false, '');

        $this->assertTrue($skipped['version_setup_skipped']);
        $this->assertSame([
            'rpm -e PowerMTA',
            'rm -rf /etc/pmta; rm -rf /var/lib/pmta; rm -rf /var/log/pmta',
            'dnf install -y initscripts',
            'kill -9 $(lsof -t -i:25)',
            'systemctl enable pmta;',
            'systemctl enable pmtahttp;',
            'systemctl restart pmta;',
            'systemctl restart pmtahttp;',
        ], $this->commands($skipped['operations']));
        $this->assertFalse($partial['version_setup_skipped']);
        $this->assertContains([
            'type' => 'asset_upload',
            'source' => 'install/pmta_40/pmtahttp_patch',
            'destination' => '/usr/sbin/pmtahttpd',
        ], $partial['operations']);
    }

    public function test_it_rejects_unknown_versions_and_invalid_ports(): void
    {
        foreach ([[39, 1212, 8080], [40, 0, 8080], [40, 1212, 32768]] as [$version, $pmtaPort, $adminPort]) {
            try {
                $this->planner()->plan($version, true, true, $pmtaPort, $adminPort, [], [], false, '');
                $this->fail('Unsupported PMTA planner inputs must be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    private function planner(): DmsCentos9PmtaDeploymentPlanner
    {
        return new DmsCentos9PmtaDeploymentPlanner;
    }

    /** @param list<array<string, string>> $operations
     * @return list<string>
     */
    private function commands(array $operations): array
    {
        return array_values(array_map(
            fn (array $operation): string => $operation['command'],
            array_filter($operations, fn (array $operation): bool => $operation['type'] === 'command'),
        ));
    }

    /** @param list<array<string, string>> $operations */
    private function remoteContent(array $operations, string $destination): string
    {
        foreach ($operations as $operation) {
            if ($operation['type'] === 'remote_content' && $operation['destination'] === $destination) {
                return $operation['content'];
            }
        }

        $this->fail("No generated remote file was planned for {$destination}.");
    }
}
