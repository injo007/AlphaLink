<?php

namespace Tests\Unit;

use App\Models\ManagedServer;
use App\Services\Infrastructure\DmsInstallerSshConnection;
use App\Services\Infrastructure\DmsInstallerSshConnector;
use App\Services\Infrastructure\DmsInstallerSshSession;
use App\Services\Infrastructure\DmsInstallerSshSessionFactory;
use App\Services\Infrastructure\HostProbeException;
use App\Services\Infrastructure\SshHostKeyFingerprint;
use App\Services\Infrastructure\SshHostProbe;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use RuntimeException;
use Tests\TestCase;

class DmsInstallerSshSessionFactoryTest extends TestCase
{
    public function test_unverified_server_without_a_saved_fingerprint_never_opens_a_connection(): void
    {
        $connector = $this->createMock(DmsInstallerSshConnector::class);
        $connector->expects($this->never())->method('connect');
        $factory = $this->factory($connector);

        try {
            $factory->create($this->server(null, 'pending'), 'synthetic-password');
            $this->fail('A missing saved fingerprint must block SSH.');
        } catch (HostProbeException $exception) {
            $this->assertSame('Verify the server host before installation.', $exception->getMessage());
        }
    }

    public function test_changed_host_key_stops_before_authentication_and_transport_operations(): void
    {
        $connection = $this->connection($this->hostKey('changed'));
        $connector = $this->connectorReturning($connection);
        $factory = $this->factory($connector);

        try {
            $factory->create($this->server($this->fingerprint('saved')), 'synthetic-password');
            $this->fail('A changed host key must be rejected.');
        } catch (HostProbeException $exception) {
            $this->assertSame('The SSH host key changed since the previous preflight.', $exception->getMessage());
        }

        $this->assertSame([], $connection->calls);
    }

    public function test_matching_saved_host_key_authenticates_and_matches_dms_command_and_string_upload_contract(): void
    {
        $connection = $this->connection($this->hostKey('saved'), "  command output\n ");
        $connector = $this->connectorReturning($connection);
        $factory = $this->factory($connector);
        $session = $factory->create($this->server($this->fingerprint('saved')), 'synthetic-password');

        $this->assertSame('command output', $session->exec('printf synthetic'));
        $this->assertTrue($session->upload('synthetic file contents', '/tmp/synthetic.conf'));
        $this->assertTrue($session->uploadFile('/tmp/synthetic.rpm', '/tmp/synthetic.rpm'));
        $this->assertSame('synthetic downloaded contents', $session->download('/tmp/synthetic.conf'));
        $this->assertSame([
            ['login', 'root', 'synthetic-password'],
            ['exec', 'printf synthetic'],
            ['upload', 'synthetic file contents', '/tmp/synthetic.conf'],
            ['upload_file', '/tmp/synthetic.rpm', '/tmp/synthetic.rpm'],
            ['download', '/tmp/synthetic.conf'],
        ], $connection->calls);
    }

    public function test_authentication_failure_returns_a_sanitized_error_without_exposing_credentials(): void
    {
        $connection = $this->connection($this->hostKey('saved'), loginResult: false);
        $factory = $this->factory($this->connectorReturning($connection));

        try {
            $factory->create($this->server($this->fingerprint('saved')), 'synthetic-password');
            $this->fail('Failed authentication must not create a transport.');
        } catch (HostProbeException $exception) {
            $this->assertSame('SSH authentication failed. Check the stored credential.', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-password', $exception->getMessage());
        }
    }

    public function test_ssh_key_credential_is_loaded_before_authentication(): void
    {
        $connection = $this->connection($this->hostKey('saved'));
        $privateKey = EC::createKey('secp256r1')->toString('PKCS8');
        $session = $this->factory($this->connectorReturning($connection))
            ->create($this->server($this->fingerprint('saved')), $privateKey, 'ssh_key');

        $this->assertInstanceOf(PrivateKey::class, $connection->calls[0][2]);
        $this->assertInstanceOf(DmsInstallerSshSession::class, $session);
    }

    public function test_disconnected_session_returns_false_without_running_remote_operations(): void
    {
        $connection = $this->connection($this->hostKey('saved'), connected: false);
        $session = $this->factory($this->connectorReturning($connection))
            ->create($this->server($this->fingerprint('saved')), 'synthetic-password');

        $this->assertFalse($session->exec('printf synthetic'));
        $this->assertFalse($session->upload('synthetic file contents', '/tmp/synthetic.conf'));
        $this->assertFalse($session->uploadFile('/tmp/synthetic.rpm', '/tmp/synthetic.rpm'));
        $this->assertFalse($session->download('/tmp/synthetic.conf'));
        $this->assertSame([['login', 'root', 'synthetic-password']], $connection->calls);
    }

    public function test_remote_exec_timeout_fails_closed_instead_of_looking_like_empty_success(): void
    {
        $connection = $this->connection($this->hostKey('saved'), commandOutput: false);
        $session = $this->factory($this->connectorReturning($connection))
            ->create($this->server($this->fingerprint('saved')), 'synthetic-password');

        try {
            $session->exec('dnf install -y package');
            $this->fail('A timed-out command must not appear to have succeeded.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The remote installer command timed out or the SSH channel failed.', $exception->getMessage());
        }

        $this->assertSame([
            ['login', 'root', 'synthetic-password'],
            ['exec', 'dnf install -y package'],
        ], $connection->calls);
    }

    private function factory(DmsInstallerSshConnector $connector): DmsInstallerSshSessionFactory
    {
        return new DmsInstallerSshSessionFactory($connector, new SshHostProbe, new SshHostKeyFingerprint);
    }

    private function connectorReturning(DmsInstallerSshConnection $connection): DmsInstallerSshConnector
    {
        $connector = $this->createMock(DmsInstallerSshConnector::class);
        $connector->expects($this->once())
            ->method('connect')
            ->with('8.8.8.8', 22, 300)
            ->willReturn($connection);

        return $connector;
    }

    private function connection(string|false $hostKey, string|false $commandOutput = 'ok', bool $loginResult = true, bool $connected = true): object
    {
        return new class($hostKey, $commandOutput, $loginResult, $connected) implements DmsInstallerSshConnection
        {
            /** @var list<array<mixed>> */
            public array $calls = [];

            public function __construct(
                private string|false $hostKey,
                private string|false $commandOutput,
                private bool $loginResult,
                private bool $connected,
            ) {}

            public function getServerPublicHostKey(): string|false
            {
                return $this->hostKey;
            }

            public function login(string $username, mixed $credential): bool
            {
                $this->calls[] = ['login', $username, $credential];

                return $this->loginResult;
            }

            public function isConnected(): bool
            {
                return $this->connected;
            }

            public function isAuthenticated(): bool
            {
                return $this->loginResult;
            }

            public function exec(string $command): string|false
            {
                $this->calls[] = ['exec', $command];

                return $this->commandOutput;
            }

            public function uploadString(string $contents, string $destination): bool
            {
                $this->calls[] = ['upload', $contents, $destination];

                return true;
            }

            public function uploadFile(string $localPath, string $destination): bool
            {
                $this->calls[] = ['upload_file', $localPath, $destination];

                return true;
            }

            public function downloadString(string $path): string|false
            {
                $this->calls[] = ['download', $path];

                return 'synthetic downloaded contents';
            }
        };
    }

    private function server(?string $fingerprint, string $state = 'verified'): ManagedServer
    {
        return new ManagedServer([
            'ip_address' => '8.8.8.8',
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => $state,
            'expected_host_fingerprint' => $fingerprint,
        ]);
    }

    private function hostKey(string $blob): string
    {
        return 'ssh-ed25519 '.base64_encode($blob);
    }

    private function fingerprint(string $blob): string
    {
        return (new SshHostKeyFingerprint)->fromPublicKey($this->hostKey($blob));
    }
}
