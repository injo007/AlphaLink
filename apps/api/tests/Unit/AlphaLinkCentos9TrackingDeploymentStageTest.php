<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkCentos9TrackingDeploymentStage;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteCommandTransport;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteFileTransport;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteReadableFileTransport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AlphaLinkCentos9TrackingDeploymentStageTest extends TestCase
{
    public function test_it_deploys_first_party_tracking_sources_and_validates_host_routing(): void
    {
        $commands = new RecordingTrackingCommands;
        $files = new RecordingTrackingFiles;
        $source = ['index' => '<?php require_once __DIR__."/../TrackerRequestHandler.php";', 'handler' => '<?php final class AlphaLinkTrackerRequestHandler {}'];

        (new AlphaLinkCentos9TrackingDeploymentStage)->run(
            $commands,
            $files,
            '203.0.113.18',
            42,
            'mail.example.test',
            ['a.mail.example.test', 'b.mail.example.test'],
            $source,
            'synthetic trusted IPs',
            'https://alpha.example.test',
            'synthetic-private-key',
            true,
        );

        $this->assertStringContainsString('/api/v1/health', $commands->commands[0]);
        $this->assertSame('dnf install -y httpd', $commands->commands[1]);
        $this->assertStringContainsString('/var/mini/public', $commands->commands[2]);
        $this->assertSame($source['handler'], $files->uploads['/var/alphalink-tracker/TrackerRequestHandler.php']);
        $this->assertSame($source['index'], $files->uploads['/var/mini/public/index.php']);
        $this->assertStringContainsString('Require all denied', $files->uploads['/etc/httpd/conf.d/alphalink-tracking-access.conf']);
        $this->assertStringContainsString('Require all granted', $files->uploads['/etc/httpd/conf.d/alphalink-tracking-access.conf']);
        $this->assertStringContainsString("'api_url' => 'https://alpha.example.test'", $files->uploads['/etc/alphalink-tracker.php']);
        $this->assertStringContainsString("'ingest_key' => 'synthetic-private-key'", $files->uploads['/etc/alphalink-tracker.php']);
        $this->assertStringContainsString("'server_id' => '42'", $files->uploads['/etc/alphalink-tracker.php']);
        $this->assertStringContainsString('ServerName mail.example.test', $files->uploads['/etc/httpd/conf.d/mail_example_test.conf']);
        $this->assertStringContainsString('ServerAlias 203.0.113.18 a.mail.example.test b.mail.example.test', $files->uploads['/etc/httpd/conf.d/mail_example_test.conf']);
        $this->assertStringContainsString('RewriteRule ^/healthz$ /index.php [END,QSA]', $files->uploads['/etc/httpd/conf.d/mail_example_test.conf']);
        $this->assertStringContainsString('RewriteRule ^/([A-Za-z0-9]{10}[roub][A-Za-z0-9_-]{1,4096}(?:/[A-Za-z0-9_-]+)*)$ /index.php?__alphalink_long_uri=$1 [END,QSA,B]', $files->uploads['/etc/httpd/conf.d/mail_example_test.conf']);
        $this->assertStringNotContainsString('RewriteRule ^/(.*)$ /index.php', $files->uploads['/etc/httpd/conf.d/mail_example_test.conf']);
        $this->assertStringContainsString('DocumentRoot "/var/mini/public"', $files->uploads['/etc/httpd/conf.d/mail_example_test.conf']);
        $this->assertStringContainsString('AllowOverride None', $files->uploads['/etc/httpd/conf.d/mail_example_test.conf']);
        $this->assertSame('synthetic trusted IPs', $files->uploads['/etc/httpd/conf.d/remoteip.conf']);

        $allCommands = implode("\n", $commands->commands);
        $this->assertStringContainsString('httpd -M', $allCommands);
        $this->assertStringContainsString('/healthz', $allCommands);
        $this->assertStringContainsString('/ALPHALINKTRACK10rprobe', $allCommands);
        $this->assertStringContainsString('ALPHALINK_TRACKING_ROUTE_READY', $allCommands);
        $this->assertStringContainsString('ALPHALINK_TRACKING_IP_ROUTE_READY', $allCommands);
        $this->assertStringContainsString('ALPHALINK_TRACKING_LONG_ROUTE_READY', $allCommands);
        $this->assertStringContainsString('Location: https://www.google.com', $allCommands);
        $this->assertStringContainsString('X-AlphaLink-Tracking: active', $allCommands);
        $this->assertStringNotContainsString('synthetic-private-key', $allCommands);
        $this->assertStringNotContainsString('unzip', $allCommands);
        $this->assertStringNotContainsString('chown -R', $allCommands);
        $this->assertStringNotContainsString('rm -rf /var/mini', $allCommands);
        $this->assertSame([], $files->downloads);
    }

    public function test_it_still_configures_apache_when_tracking_is_not_selected(): void
    {
        $commands = new RecordingTrackingCommands;
        $files = new RecordingTrackingFiles;

        (new AlphaLinkCentos9TrackingDeploymentStage)->run(
            $commands, $files, '203.0.113.18', 42, null, [], null, null, '', '',
        );

        $this->assertCount(6, $commands->commands);
        $this->assertSame('dnf install -y httpd', $commands->commands[0]);
        $this->assertSame('systemctl enable httpd', $commands->commands[5]);
        $this->assertSame([], $files->uploads);
        $this->assertSame([], $files->downloads);
    }

    public function test_it_rejects_an_incomplete_source_package_before_uploading_files(): void
    {
        $commands = new RecordingTrackingCommands;
        $files = new RecordingTrackingFiles;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The AlphaLink tracking source files are incomplete.');

        (new AlphaLinkCentos9TrackingDeploymentStage)->run(
            $commands, $files, '203.0.113.18', 42, 'mail.example.test', [], ['index' => '<?php'], null,
            'https://alpha.example.test', 'synthetic-private-key',
        );
    }

    public function test_tracking_install_fails_when_apache_rewrite_is_unavailable(): void
    {
        $commands = new class extends RecordingTrackingCommands
        {
            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;

                return str_contains($command, 'ALPHALINK_CALLBACK_READY') ? 'ALPHALINK_CALLBACK_READY' : '';
            }
        };
        $files = new RecordingTrackingFiles;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Apache mod_rewrite could not be enabled and validated for tracking links.');

        (new AlphaLinkCentos9TrackingDeploymentStage)->run(
            $commands, $files, '203.0.113.18', 42, 'mail.example.test', [], $this->trackingSources(), null,
            'https://alpha.example.test', 'synthetic-private-key',
        );
    }

    public function test_unreachable_callback_blocks_before_uploading_tracking_sources(): void
    {
        $commands = new class extends RecordingTrackingCommands
        {
            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;

                return '';
            }
        };
        $files = new RecordingTrackingFiles;

        try {
            (new AlphaLinkCentos9TrackingDeploymentStage)->run(
                $commands, $files, '203.0.113.18', 42, 'mail.example.test', [], $this->trackingSources(), null,
                'https://alpha.example.test', 'synthetic-private-key',
            );
            $this->fail('Unreachable callback must block tracking deployment.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The installed server cannot reach the AlphaLink tracking callback over HTTPS.', $exception->getMessage());
        }

        $this->assertCount(1, $commands->commands);
        $this->assertSame([], $files->uploads);
    }

    /** @return array{index: string, handler: string} */
    private function trackingSources(): array
    {
        return ['index' => '<?php', 'handler' => '<?php final class AlphaLinkTrackerRequestHandler {}'];
    }
}

class RecordingTrackingCommands implements AlphaLinkInstallerRemoteCommandTransport
{
    /** @var list<string> */
    public array $commands = [];

    public function exec(string $command): bool|string
    {
        $this->commands[] = $command;

        foreach ([
            'ALPHALINK_CALLBACK_READY', 'ALPHALINK_TRACKING_READY', 'ALPHALINK_REWRITE_READY',
            'ALPHALINK_TRACKING_ROUTE_READY', 'ALPHALINK_TRACKING_IP_ROUTE_READY', 'ALPHALINK_TRACKING_LONG_ROUTE_READY',
        ] as $marker) {
            if (str_contains($command, $marker)) {
                return $marker;
            }
        }

        return '';
    }
}

class RecordingTrackingFiles implements AlphaLinkInstallerRemoteFileTransport, AlphaLinkInstallerRemoteReadableFileTransport
{
    /** @var array<string, string> */
    public array $uploads = [];

    /** @var list<string> */
    public array $downloads = [];

    public function upload(string $contents, string $destination): bool
    {
        $this->uploads[$destination] = $contents;

        return true;
    }

    public function uploadFile(string $localPath, string $destination): bool
    {
        $contents = file_get_contents($localPath);
        if (! is_string($contents)) {
            return false;
        }

        $this->uploads[$destination] = $contents;

        return true;
    }

    public function download(string $path): string|false
    {
        $this->downloads[] = $path;

        return false;
    }
}
