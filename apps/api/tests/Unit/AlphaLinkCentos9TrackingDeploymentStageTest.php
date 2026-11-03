<?php

namespace Tests\Unit;

use App\Services\Infrastructure\AlphaLinkCentos9TrackingDeploymentStage;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteCommandTransport;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteFileTransport;
use App\Services\Infrastructure\AlphaLinkInstallerRemoteReadableFileTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AlphaLinkCentos9TrackingDeploymentStageTest extends TestCase
{
    public function test_it_replays_reference_mini_app_and_apache_behavior_without_putting_the_key_in_commands(): void
    {
        $commands = new RecordingTrackingCommands;
        $files = new RecordingTrackingFiles('url=[IP];key=[KEY];server=[ID];decode=base64_decode($token, true)');

        (new AlphaLinkCentos9TrackingDeploymentStage)->run(
            $commands,
            $files,
            '203.0.113.18',
            42,
            'mail.example.test',
            ['a.mail.example.test', 'b.mail.example.test'],
            'synthetic zip bytes',
            'synthetic trusted IPs',
            'https://alpha.example.test',
            'synthetic-private-key',
            true,
        );

        $this->assertStringContainsString('/api/v1/health', $commands->commands[0]);
        $this->assertSame('dnf install -y httpd', $commands->commands[1]);
        $this->assertSame('mkdir -p /var/mini/', $commands->commands[2]);
        $this->assertSame('unzip -o /var/mini/mini_app.zip -d /var/mini', $commands->commands[3]);
        $this->assertContains('cd /var/mini && php artisan optimize', $commands->commands);
        $this->assertContains('chmod 600 /var/mini/.env', $commands->commands);
        $this->assertStringContainsString('APP_NAME="AlphaLink"', $files->uploads['/var/mini/.env']);
        $this->assertStringContainsString('SESSION_COOKIE=alphalink-session', $files->uploads['/var/mini/.env']);
        $this->assertMatchesRegularExpression('/APP_ENV=production\\nAPP_DEBUG=false\\nAPP_KEY=base64:[A-Za-z0-9+\\/=]+\\nAPP_URL=https:\/\/mail.example.test\\n/', $files->uploads['/var/mini/.env']);
        $this->assertSame('synthetic zip bytes', $files->uploads['/var/mini/mini_app.zip']);
        $this->assertStringContainsString('ServerName mail.example.test', $files->uploads['/etc/httpd/conf.d/mail_example_test.conf']);
        $this->assertStringContainsString('ServerAlias 203.0.113.18 a.mail.example.test b.mail.example.test', $files->uploads['/etc/httpd/conf.d/mail_example_test.conf']);
        $this->assertStringContainsString('RewriteRule ^/(.{255,})$ /index.php?__alphalink_long_uri=$1 [END,QSA,B]', $files->uploads['/etc/httpd/conf.d/mail_example_test.conf']);
        $this->assertStringContainsString("\$_SERVER['REQUEST_URI'] = '/'.ltrim(\$_GET['__alphalink_long_uri'], '/');", $files->uploads['/var/mini/public/index.php']);
        $this->assertSame('synthetic trusted IPs', $files->uploads['/etc/httpd/conf.d/remoteip.conf']);
        $this->assertSame(
            "url=https://alpha.example.test;key=synthetic-private-key;server=42;decode=base64_decode(strtr(\$token, '-_', '+/'), true)",
            $files->uploads['/var/mini/app/Helper.php'],
        );
        $this->assertStringContainsString('httpd -M', implode("\n", $commands->commands));
        $allCommands = implode("\n", $commands->commands);
        $this->assertStringContainsString('/ALPHALINKTRACK10rprobe', $allCommands);
        $this->assertStringContainsString('test -s /var/mini/public/.htaccess', $allCommands);
        $this->assertStringContainsString('Host: 203.0.113.18', $allCommands);
        $this->assertStringContainsString('ALPHALINK_TRACKING_ROUTE_READY', $allCommands);
        $this->assertStringContainsString('ALPHALINK_TRACKING_IP_ROUTE_READY', $allCommands);
        $this->assertStringContainsString('ALPHALINK_TRACKING_LONG_ROUTE_READY', $allCommands);
        $this->assertStringContainsString('Set-Cookie: alphalink-session=', $allCommands);
        $this->assertSame([
            '/var/mini/app/Helper.php',
            '/var/mini/app/Http/Controllers/RedirectController.php',
            '/var/mini/app/Http/Controllers/RedirectDropController.php',
            '/var/mini/public/index.php',
        ], $files->downloads);
        foreach (['RedirectController.php', 'RedirectDropController.php'] as $controllerFile) {
            $controller = $files->uploads['/var/mini/app/Http/Controllers/'.$controllerFile];
            $this->assertSame(4, substr_count($controller, '$token .= str_replace(\'/\', \'\', (string) $option);'));
        }
        $this->assertStringNotContainsString('synthetic-private-key', implode("\n", $commands->commands));
    }

    public function test_it_still_configures_and_enables_apache_when_reference_mini_app_asset_is_absent(): void
    {
        $commands = new RecordingTrackingCommands;
        $files = new RecordingTrackingFiles(false);

        (new AlphaLinkCentos9TrackingDeploymentStage)->run(
            $commands, $files, '203.0.113.18', 42, null, [], null, null, '', '',
        );

        $this->assertCount(7, $commands->commands);
        $this->assertSame('dnf install -y httpd', $commands->commands[0]);
        $this->assertSame('systemctl enable httpd', $commands->commands[6]);
        $this->assertSame([], $files->uploads);
        $this->assertSame([], $files->downloads);
    }

    public function test_it_stops_before_helper_configuration_when_required_placeholders_are_missing(): void
    {
        $commands = new RecordingTrackingCommands;
        $files = new RecordingTrackingFiles('unexpected helper');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The tracking app helper is unavailable or does not contain the required placeholders.');

        (new AlphaLinkCentos9TrackingDeploymentStage)->run(
            $commands, $files, '203.0.113.18', 42, 'mail.example.test', [], 'zip', null, 'https://alpha.example.test', 'private',
        );
    }

    public function test_tracking_install_fails_when_apache_rewrite_is_unavailable(): void
    {
        $commands = new class extends RecordingTrackingCommands
        {
            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;

                if (str_contains($command, 'ALPHALINK_CALLBACK_READY')) {
                    return 'ALPHALINK_CALLBACK_READY';
                }

                return '';
            }
        };
        $files = new RecordingTrackingFiles('url=[IP];key=[KEY];server=[ID];base64_decode($token, true)');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Apache mod_rewrite could not be enabled and validated for tracking links.');

        (new AlphaLinkCentos9TrackingDeploymentStage)->run(
            $commands, $files, '203.0.113.18', 42, 'mail.example.test', [], 'zip', null,
            'https://alpha.example.test', 'private',
        );
    }

    public function test_unreachable_callback_blocks_before_package_or_file_changes(): void
    {
        $commands = new class extends RecordingTrackingCommands
        {
            public function exec(string $command): bool|string
            {
                $this->commands[] = $command;

                return '';
            }
        };
        $files = new RecordingTrackingFiles(false);

        try {
            (new AlphaLinkCentos9TrackingDeploymentStage)->run(
                $commands, $files, '203.0.113.18', 42, 'mail.example.test', [],
                'synthetic zip bytes', null, 'https://alpha.example.test', 'synthetic-private-key',
            );
            $this->fail('Unreachable callback must block tracking deployment.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The installed server cannot reach the AlphaLink tracking callback over HTTPS.', $exception->getMessage());
        }

        $this->assertCount(1, $commands->commands);
        $this->assertSame([], $files->uploads);
    }
}

class RecordingTrackingCommands implements AlphaLinkInstallerRemoteCommandTransport
{
    /** @var list<string> */
    public array $commands = [];

    public function exec(string $command): bool|string
    {
        $this->commands[] = $command;

        if (str_contains($command, 'ALPHALINK_CALLBACK_READY')) {
            return 'ALPHALINK_CALLBACK_READY';
        }
        if (str_contains($command, 'ALPHALINK_TRACKING_READY')) {
            return 'ALPHALINK_TRACKING_READY';
        }
        if (str_contains($command, 'ALPHALINK_REWRITE_READY')) {
            return 'ALPHALINK_REWRITE_READY';
        }
        if (str_contains($command, 'ALPHALINK_TRACKING_ROUTE_READY')) {
            return 'ALPHALINK_TRACKING_ROUTE_READY';
        }
        if (str_contains($command, 'ALPHALINK_TRACKING_IP_ROUTE_READY')) {
            return 'ALPHALINK_TRACKING_IP_ROUTE_READY';
        }
        if (str_contains($command, 'ALPHALINK_TRACKING_LONG_ROUTE_READY')) {
            return 'ALPHALINK_TRACKING_LONG_ROUTE_READY';
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

    public function __construct(private readonly string|false $helper) {}

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

        if (str_ends_with($path, '/Helper.php')) {
            return $this->helper;
        }
        if (str_ends_with($path, '/public/index.php')) {
            return "<?php\ndefine('LARAVEL_START', microtime(true));\n";
        }

        return <<<'PHP'
<?php
class TrackingController
{
    public function redirect(Request $request, string $token, ?string $option = null): Response {
        $token = $request->filled('d') ? $request->get('d') : $token;
        $info = Helper::validateToken($token);
    }
    public function unsubscribe(Request $request, string $token, ?string $option = null): Response {
        $token = $request->filled('d') ? $request->get('d') : $token;
        $info = Helper::validateToken($token);
    }
    public function open(Request $request, string $token, ?string $option = null)
    {
        $info = Helper::validateToken($token);
    }
    public function bot(Request $request, string $token, ?string $option = null)
    {
        $info = Helper::validateToken($token);
            }
}
PHP;
    }
}
