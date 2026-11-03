<?php

namespace Tests\Unit;

use App\Models\ManagedServer;
use App\Models\SendingDomain;
use App\Models\Workspace;
use App\Services\Infrastructure\AlphaLinkBulkInstallDomainDnsRecordWriter;
use App\Services\Infrastructure\AlphaLinkBulkInstallRowRunner;
use App\Services\Infrastructure\AlphaLinkBulkInstallRuntimeInputs;
use App\Services\Infrastructure\AlphaLinkCentos9BulkInstallRowRunner;
use App\Services\Infrastructure\AlphaLinkCentos9InstallWorkflow;
use App\Services\Infrastructure\AlphaLinkInstallerServerCredentialResolver;
use App\Services\Infrastructure\AlphaLinkInstallerSshSession;
use App\Services\Infrastructure\AlphaLinkInstallerSshSessionFactory;
use App\Services\Infrastructure\AlphaLinkPmtaDeploymentGate;
use App\Services\Infrastructure\AlphaLinkRegistrarChangeDomainDnsRecordWriter;
use App\Services\Infrastructure\HostProbeException;
use App\Services\Infrastructure\SshHostProbe;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Tests\TestCase;

class AlphaLinkCentos9BulkInstallRowRunnerTest extends TestCase
{
    public function test_installer_runner_and_registrar_writer_are_bound_but_execution_remains_disabled(): void
    {
        config(['infrastructure.bulk_install_execution_enabled' => false]);

        $runner = app(AlphaLinkBulkInstallRowRunner::class);
        $dnsWriter = app(AlphaLinkBulkInstallDomainDnsRecordWriter::class);

        $this->assertInstanceOf(AlphaLinkCentos9BulkInstallRowRunner::class, $runner);
        $this->assertInstanceOf(AlphaLinkRegistrarChangeDomainDnsRecordWriter::class, $dnsWriter);
        $this->assertFalse(config('infrastructure.bulk_install_execution_enabled'));
    }

    public function test_it_requires_a_prepared_row_before_any_gate_or_connection(): void
    {
        [$runner, $gate, $inputs, $credentials, $sessions, $workflow] = $this->runner();
        $gate->expects($this->never())->method('evaluate');
        $inputs->expects($this->never())->method('resolve');
        $credentials->expects($this->never())->method('resolve');
        $sessions->expects($this->never())->method('create');
        $workflow->expects($this->never())->method('run');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The installation row was not prepared.');
        $runner->install(['id' => 'server', 'domain' => 'domain'], $this->installOptions(), static function (): void {});
    }

    public function test_it_rechecks_pmta_evidence_before_loading_secrets_or_opening_ssh(): void
    {
        [$runner, $gate, $inputs, $credentials, $sessions, $workflow] = $this->runner();
        $gate->method('evaluate')->willReturn(['installation_authorized' => false]);
        $inputs->expects($this->never())->method('resolve');
        $credentials->expects($this->never())->method('resolve');
        $sessions->expects($this->never())->method('create');
        $workflow->expects($this->never())->method('run');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PMTA deployment prerequisites are not verified.');
        $runner->install($this->preparedRow(), $this->installOptions(), static function (): void {});
    }

    public function test_it_rejects_cross_workspace_context_before_gate_or_ssh(): void
    {
        [$runner, $gate, $inputs, $credentials, $sessions, $workflow] = $this->runner();
        $gate->expects($this->never())->method('evaluate');
        $inputs->expects($this->never())->method('resolve');
        $credentials->expects($this->never())->method('resolve');
        $sessions->expects($this->never())->method('create');
        $workflow->expects($this->never())->method('run');
        $row = $this->preparedRow();
        $row['prepared']['domain']->workspace_id = 'another-workspace';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The prepared server and domain must belong to the same workspace.');
        $runner->install($row, $this->installOptions(), static function (): void {});
    }

    public function test_it_passes_verified_prepared_context_and_saved_credentials_to_the_workflow(): void
    {
        [$runner, $gate, $inputs, $credentials, $sessions, $workflow, $hostProbe] = $this->runner();
        $gate->method('evaluate')->willReturn(['installation_authorized' => true]);
        $inputs->expects($this->once())
            ->method('resolve')
            ->with(
                $this->callback(static fn (array $options): bool => $options['tracking'] === false && $options['dkim'] === false),
                $this->isInstanceOf(Workspace::class),
                40,
            )
            ->willReturn(['settings' => ['synthetic' => true], 'assets' => []]);
        $credentials->expects($this->once())->method('resolve')->willReturn(['secret' => 'synthetic password', 'type' => 'password']);
        $hostProbe->expects($this->once())->method('inspect')->willReturn([
            'fingerprint' => 'SHA256:synthetic-pin',
            'facts' => ['os_pretty_name' => 'AlmaLinux 9', 'architecture' => 'x86_64', 'pmta_binary_present' => false],
        ]);
        $session = $this->createMock(AlphaLinkInstallerSshSession::class);
        $sessions->expects($this->once())->method('create')
            ->with($this->isInstanceOf(ManagedServer::class), 'synthetic password', 'password')
            ->willReturn($session);
        $workflow->expects($this->once())->method('run');

        $runner->install($this->preparedRow(), $this->installOptions(), static function (): void {});
    }

    public function test_it_rejects_pmta_detected_by_the_fresh_host_probe_before_install_session(): void
    {
        [$runner, $gate, $inputs, $credentials, $sessions, $workflow, $hostProbe] = $this->runner();
        $gate->method('evaluate')->willReturn(['installation_authorized' => true]);
        $inputs->method('resolve')->willReturn(['settings' => [], 'assets' => []]);
        $credentials->method('resolve')->willReturn(['secret' => 'synthetic password', 'type' => 'password']);
        $hostProbe->expects($this->once())->method('inspect')->willReturn([
            'fingerprint' => 'SHA256:synthetic-pin',
            'facts' => ['os_pretty_name' => 'AlmaLinux 9', 'architecture' => 'x86_64', 'pmta_binary_present' => true],
        ]);
        $sessions->expects($this->never())->method('create');
        $workflow->expects($this->never())->method('run');

        $this->expectException(HostProbeException::class);
        $this->expectExceptionMessage('The current host identity or PMTA installed state is not clear for a new installation.');
        $runner->install($this->preparedRow(), $this->installOptions(), static function (): void {});
    }

    /** @return array{AlphaLinkCentos9BulkInstallRowRunner, AlphaLinkPmtaDeploymentGate&MockObject, AlphaLinkBulkInstallRuntimeInputs&MockObject, AlphaLinkInstallerServerCredentialResolver&MockObject, AlphaLinkInstallerSshSessionFactory&MockObject, AlphaLinkCentos9InstallWorkflow&MockObject, SshHostProbe&MockObject} */
    private function runner(): array
    {
        $gate = $this->createMock(AlphaLinkPmtaDeploymentGate::class);
        $inputs = $this->createMock(AlphaLinkBulkInstallRuntimeInputs::class);
        $credentials = $this->createMock(AlphaLinkInstallerServerCredentialResolver::class);
        $sessions = $this->createMock(AlphaLinkInstallerSshSessionFactory::class);
        $workflow = $this->createMock(AlphaLinkCentos9InstallWorkflow::class);
        $dnsWriter = $this->createMock(AlphaLinkBulkInstallDomainDnsRecordWriter::class);
        $hostProbe = $this->createMock(SshHostProbe::class);

        return [new AlphaLinkCentos9BulkInstallRowRunner($gate, $inputs, $credentials, $sessions, $workflow, $dnsWriter, $hostProbe), $gate, $inputs, $credentials, $sessions, $workflow, $hostProbe];
    }

    /** @return array<string, mixed> */
    private function preparedRow(): array
    {
        return [
            'id' => 'server-one',
            'domain' => 'domain-one',
            'prepared' => [
                'workspace' => (new Workspace)->forceFill(['id' => 'workspace-one']),
                'server' => (new ManagedServer)->forceFill([
                    'id' => 'server-one', 'workspace_id' => 'workspace-one',
                    'host_facts' => ['os_pretty_name' => 'AlmaLinux 9', 'architecture' => 'x86_64'],
                    'expected_host_fingerprint' => 'SHA256:synthetic-pin',
                ]),
                'domain' => (new SendingDomain)->forceFill(['id' => 'domain-one', 'workspace_id' => 'workspace-one']),
                'ips' => ['8.8.8.8'],
                'isps' => [],
            ],
        ];
    }

    /** @return array<string, bool|int> */
    private function installOptions(): array
    {
        return [
            'pmta_version' => 40,
            'tracking' => false, 'proxy' => false, 'imap' => false, 'spf' => false,
            'dkim' => false, 'dmarc' => false, 'https' => false,
        ];
    }
}
