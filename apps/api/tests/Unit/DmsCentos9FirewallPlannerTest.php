<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsCentos9FirewallPlanner;
use PHPUnit\Framework\TestCase;

class DmsCentos9FirewallPlannerTest extends TestCase
{
    public function test_disabled_firewall_stops_and_disables_both_firewall_services(): void
    {
        $commands = (new DmsCentos9FirewallPlanner)->plan(
            false,
            22,
            2525,
            8080,
            3128,
            ['192.0.2.10'],
            ['198.51.100.11'],
            ['203.0.113.12'],
        );

        $this->assertSame([
            ['type' => 'command', 'command' => 'systemctl stop firewalld;'],
            ['type' => 'command', 'command' => 'systemctl disable firewalld;'],
            ['type' => 'command', 'command' => 'systemctl stop iptables;'],
            ['type' => 'command', 'command' => 'systemctl disable iptables;'],
        ], $commands);
    }

    public function test_enabled_firewall_reproduces_dms_zone_order_ports_and_source_lists(): void
    {
        $commands = (new DmsCentos9FirewallPlanner)->plan(
            true,
            2222,
            2525,
            8080,
            3128,
            ['192.0.2.10', '2001:db8::10'],
            ['198.51.100.11'],
            ['203.0.113.12'],
        );

        $this->assertSame([
            'dnf install -y firewalld;',
            'systemctl is-active -q dbus || systemctl restart dbus;',
            'systemctl unmask --now firewalld',
            'systemctl start firewalld;',
            'systemctl enable firewalld;',
            'firewall-cmd --permanent --zone=public --add-service={http,https,dns,smtp,ntp,dns};',
            'firewall-cmd --permanent --new-zone=dev;',
            'firewall-cmd --permanent --zone=dev --add-port=2222/tcp;',
            'firewall-cmd --permanent --zone=dev --add-port=143/tcp;',
            'firewall-cmd --permanent --zone=dev --add-port=993/tcp;',
            'firewall-cmd --permanent --zone=dev --add-port=2525/tcp;',
            'firewall-cmd --permanent --zone=dev --add-port=8080/tcp;',
            'firewall-cmd --permanent --zone=dev --add-port=16160/tcp;',
            'firewall-cmd --permanent --zone=dev --add-port=3128/tcp;',
            'firewall-cmd --permanent --zone=dev --add-source=192.0.2.10;',
            'firewall-cmd --permanent --zone=dev --add-source=2001:db8::10;',
            'firewall-cmd --permanent --new-zone=production;',
            'firewall-cmd --permanent --zone=production --add-port=8080/tcp;',
            'firewall-cmd --permanent --zone=production --add-port=3128/tcp;',
            'firewall-cmd --permanent --zone=production --add-source=198.51.100.11;',
            'firewall-cmd --permanent --new-zone=rdp;',
            'firewall-cmd --permanent --zone=rdp --add-port=3128/tcp;',
            'firewall-cmd --permanent --zone=rdp --add-source=203.0.113.12;',
            'firewall-cmd --permanent --zone=public --remove-service=ssh;',
            'firewall-cmd --reload;',
        ], array_column($commands, 'command'));
    }
}
