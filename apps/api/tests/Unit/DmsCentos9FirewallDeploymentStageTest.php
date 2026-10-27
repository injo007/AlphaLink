<?php

namespace Tests\Unit;

use App\Services\Infrastructure\DmsCentos9FirewallDeploymentStage;
use App\Services\Infrastructure\DmsCentos9FirewallPlanner;
use App\Services\Infrastructure\DmsInstallerRemoteCommandTransport;
use PHPUnit\Framework\TestCase;

class DmsCentos9FirewallDeploymentStageTest extends TestCase
{
    public function test_disabled_firewall_executes_the_source_command_order_even_when_commands_fail(): void
    {
        $transport = $this->transport(false);

        $this->stage()->run($transport, false, 22, 25, 8080, 3128, [], [], []);

        $this->assertSame([
            'systemctl stop firewalld;',
            'systemctl disable firewalld;',
            'systemctl stop iptables;',
            'systemctl disable iptables;',
        ], $transport->commands);
    }

    public function test_enabled_firewall_executes_every_planned_command_in_order(): void
    {
        $planner = new DmsCentos9FirewallPlanner;
        $transport = $this->transport(false);
        $inputs = [true, 2222, 25, 8080, 3128, ['198.51.100.10'], ['203.0.113.10'], ['192.0.2.10']];

        $this->stage($planner)->run($transport, ...$inputs);

        $planned = array_column($planner->plan(...$inputs), 'command');
        $this->assertSame($planned, $transport->commands);
        $this->assertSame('firewall-cmd --reload;', $transport->commands[array_key_last($transport->commands)]);
    }

    private function stage(?DmsCentos9FirewallPlanner $planner = null): DmsCentos9FirewallDeploymentStage
    {
        return new DmsCentos9FirewallDeploymentStage($planner ?? new DmsCentos9FirewallPlanner);
    }

    private function transport(bool|string $result): object
    {
        return new class($result) implements DmsInstallerRemoteCommandTransport
        {
            /** @var list<string> */
            public array $commands = [];

            public function __construct(private readonly bool|string $result) {}

            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;

                return $this->result;
            }
        };
    }
}
