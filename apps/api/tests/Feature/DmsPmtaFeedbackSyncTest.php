<?php

namespace Tests\Feature;

use App\Jobs\SyncManagedServerPmtaFeedback;
use App\Models\Isp;
use App\Models\ManagedServer;
use App\Models\ServerCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Services\Analytics\PmtaFeedbackRemoteProcessor;
use App\Services\Analytics\WorkspaceDailyAnalyticsRebuilder;
use App\Services\Infrastructure\DmsInstallerSshConnection;
use App\Services\Infrastructure\DmsInstallerSshConnector;
use App\Services\Infrastructure\DmsInstallerSshSessionFactory;
use App\Services\Infrastructure\HostProbeException;
use App\Services\Infrastructure\SshHostKeyFingerprint;
use App\Services\Infrastructure\SshHostProbe;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DmsPmtaFeedbackSyncTest extends TestCase
{
    use RefreshDatabase;

    private const HOST_KEY = 'ssh-ed25519 c3ludGhldGljLXBtdGEtaG9zdC1rZXk=';

    private string $stagingDirectory;

    private int $serverIpCounter = 30;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stagingDirectory = sys_get_temp_dir().'/alphalink-pmta-feedback-test-'.Str::uuid();
        config(['infrastructure.pmta_feedback_staging_path' => $this->stagingDirectory]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        foreach (glob($this->stagingDirectory.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->stagingDirectory)) {
            rmdir($this->stagingDirectory);
        }
        parent::tearDown();
    }

    public function test_verified_server_feedback_is_imported_without_persisting_recipient_data_and_archived(): void
    {
        Carbon::setTestNow('2026-10-27 12:00:00');
        [$owner, $workspace, $server] = $this->serverFixture();
        $isp = Isp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'tracked_countries' => [],
            'quality_emails' => [],
        ]);
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $token = $this->dropToken($drop->getKey(), $server->getKey(), $isp->getKey());
        $date = '2026-10-27';
        $connection = new FakePmtaSftpConnection(self::HOST_KEY, [
            "/etc/pmta/delivered/treated/d-{$date}-part.csv" => $this->csv([[$date, 'ok', 'private@example.test', $token]]),
            "/etc/pmta/bounced/treated/b-{$date}-part.csv" => $this->csv([
                [$date, 'bad-mailbox', 'ignored', 'ignored', $token],
                [$date, 'mailbox-full', 'ignored', 'ignored', $token],
            ]),
        ]);
        $rebuilder = new CountingWorkspaceDailyAnalyticsRebuilder;
        $this->app->instance(WorkspaceDailyAnalyticsRebuilder::class, $rebuilder);
        $this->bindSftp($server, $connection);

        $result = app(PmtaFeedbackRemoteProcessor::class)->process($server, $date);

        $this->assertSame([
            'files_seen' => 2,
            'imported' => 3,
            'duplicate' => 0,
            'unmatched' => 0,
            'archived' => 2,
            'deferred' => 0,
        ], $result);
        $this->assertSame([], $connection->filesUnder('/etc/pmta/delivered/treated'));
        $this->assertSame([], $connection->filesUnder('/etc/pmta/bounced/treated'));
        $this->assertCount(1, $connection->filesUnder("/etc/pmta/delivered/archive/{$date}"));
        $this->assertCount(1, $connection->filesUnder("/etc/pmta/bounced/archive/{$date}"));
        $this->assertDatabaseCount('workspace_pmta_feedback_events', 3);
        $this->assertDatabaseHas('workspace_daily_analytics', [
            'workspace_id' => $workspace->getKey(),
            'source_type' => 'drop',
            'source_id' => (string) $drop->getKey(),
            'deliveries' => 1,
            'soft_bounces' => 1,
            'hard_bounces' => 1,
        ]);
        $this->actingAs($owner)->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertSee('Daily PMTA deliveries and bounce totals')
            ->assertSee(now()->day.': Deliveries 1')
            ->assertSee(now()->day.': Bounces 2');
        $this->getJson(route('clean.console.analytics.all-stats', ['from' => $date, 'to' => $date]))
            ->assertOk()
            ->assertJsonFragment(['deliveries' => 1])
            ->assertJsonFragment(['soft_bounces' => 1])
            ->assertJsonFragment(['hard_bounces' => 1]);
        $this->getJson(route('clean.console.analytics.mailer-revenue', ['from' => $date, 'to' => $date]))
            ->assertOk()
            ->assertJsonPath('0.deliveries', 1)
            ->assertJsonPath('0.soft_bounces', 1)
            ->assertJsonPath('0.hard_bounces', 1);
        $this->get(route('clean.console.analytics.mailer-revenue', ['from' => $date, 'to' => $date]))
            ->assertOk()
            ->assertSee('DELIVERIES')
            ->assertSee('SOFT BOUNCES')
            ->assertSee('HARD BOUNCES')
            ->assertSee('Imported PMTA delivery and bounce counts');
        $ispReport = $this->get(route('clean.console.analytics.isp-stats', [
            'from' => $date,
            'to' => $date,
            'isp_id' => $isp->getKey(),
            'groupby' => 'day',
        ]));
        $ispReport->assertOk()
            ->assertSee('DELIVERIES BY MAILER')
            ->assertSee('Synthetic ISP')
            ->assertSee('DELIVERIES');
        $chartMatchCount = preg_match('~<script type="application/json" data-isp-stats-chart-data>(.*?)</script>~s', (string) $ispReport->getContent(), $chartMatches);
        $this->assertSame(1, $chartMatchCount);
        $ispChart = json_decode($chartMatches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([1], array_column($ispChart['totals'], 'deliveries'));
        $this->assertSame([1], $ispChart['mailers'][0]['values']['deliveries']);
        $this->assertStringNotContainsString(
            'private@example.test',
            json_encode(DB::table('workspace_pmta_feedback_events')->get(), JSON_THROW_ON_ERROR),
        );
        $this->assertSame(2, $connection->downloadCount);
        $this->assertNotFalse($connection->stagedFileMode);
        $this->assertSame(0600, $connection->stagedFileMode & 0777);
        $this->assertSame(1, $rebuilder->rebuildCount);
        $directoryMode = fileperms($this->stagingDirectory);
        $this->assertNotFalse($directoryMode);
        $this->assertSame(0, $directoryMode & 0077);
        $this->assertSame([], glob($this->stagingDirectory.'/*') ?: []);
    }

    public function test_archive_failure_replays_idempotently_and_unmatched_rows_remain_in_process_for_recovery(): void
    {
        Carbon::setTestNow('2026-10-27 12:00:00');
        [$owner, $workspace, $server] = $this->serverFixture();
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $date = '2026-10-27';
        $token = $this->dropToken($drop->getKey(), $server->getKey());
        $connection = new FakePmtaSftpConnection(self::HOST_KEY, [
            "/etc/pmta/delivered/treated/d-{$date}-retry.csv" => $this->csv([[$date, 'ok', 'retry@example.test', $token]]),
        ]);
        $rebuilder = new CountingWorkspaceDailyAnalyticsRebuilder;
        $this->app->instance(WorkspaceDailyAnalyticsRebuilder::class, $rebuilder);
        $connection->failNextArchiveMove = true;
        $this->bindSftp($server, $connection);
        $processor = app(PmtaFeedbackRemoteProcessor::class);

        try {
            $processor->process($server, $date);
            $this->fail('A failed remote archive move must remain visible for queue retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('An imported PMTA feedback file could not be archived.', $exception->getMessage());
        }

        $this->assertDatabaseCount('workspace_pmta_feedback_events', 1);
        $this->assertSame(1, $rebuilder->rebuildCount);
        $this->assertCount(1, $connection->filesUnder("/etc/pmta/delivered/process/{$date}"));

        $this->assertSame([
            'files_seen' => 1,
            'imported' => 0,
            'duplicate' => 1,
            'unmatched' => 0,
            'archived' => 1,
            'deferred' => 0,
        ], $processor->process($server, $date));
        $this->assertDatabaseCount('workspace_pmta_feedback_events', 1);
        $this->assertSame(2, $rebuilder->rebuildCount);
        $this->assertCount(1, $connection->filesUnder("/etc/pmta/delivered/archive/{$date}"));

        $invalidSource = implode('-', [
            str_replace('-', '', (string) Str::uuid()),
            str_replace('-', '', (string) Str::uuid()),
            str_replace('-', '', (string) Str::uuid()), 'fresh',
            str_replace('-', '', (string) Str::uuid()), '1', str_replace('-', '', (string) $server->getKey()),
            '2', '3', '4', '5', '6', 'app', 'c',
        ]);
        $connection->addFile("/etc/pmta/delivered/treated/d-{$date}-unmatched.csv", $this->csv([[$date, 'ok', 'unmatched@example.test', $invalidSource]]));

        $this->assertSame([
            'files_seen' => 1,
            'imported' => 0,
            'duplicate' => 0,
            'unmatched' => 1,
            'archived' => 0,
            'deferred' => 1,
        ], $processor->process($server, $date));
        $this->assertCount(1, $connection->filesUnder("/etc/pmta/delivered/process/{$date}"));
        $this->assertCount(1, $connection->filesUnder("/etc/pmta/delivered/archive/{$date}"));
        $this->assertDatabaseCount('workspace_pmta_feedback_events', 1);
    }

    public function test_host_key_change_blocks_feedback_reads_and_server_dispatch_is_scoped_and_scheduled(): void
    {
        [$owner, $workspace, $server] = $this->serverFixture();
        $connection = new FakePmtaSftpConnection('ssh-ed25519 Y2hhbmdlZC1ob3N0LWtleQ==');
        $this->bindSftp($server, $connection);

        try {
            app(PmtaFeedbackRemoteProcessor::class)->process($server, today()->toDateString());
            $this->fail('The saved SSH fingerprint must gate every remote read.');
        } catch (HostProbeException $exception) {
            $this->assertSame('The SSH host key changed since the previous preflight.', $exception->getMessage());
        }
        $this->assertSame(0, $connection->remoteOperationCount);

        Queue::fake();
        Carbon::setTestNow('2026-10-27 12:30:00');
        $unverified = $this->createServer($owner, $workspace, ['state' => 'pending_preflight']);
        $inactive = $this->createServer($owner, $workspace, ['is_active' => false]);
        $uninstalled = $this->createServer($owner, $workspace, ['is_installed' => false]);
        $foreignOwner = User::factory()->create();
        $inactiveWorkspace = $this->createWorkspace($foreignOwner, 'inactive', 'feedback-inactive');
        $foreign = $this->createServer($foreignOwner, $inactiveWorkspace);

        $this->artisan('servers:process-pmta-feedback')->assertSuccessful();

        Queue::assertPushed(SyncManagedServerPmtaFeedback::class, 1);
        Queue::assertPushed(SyncManagedServerPmtaFeedback::class, fn (SyncManagedServerPmtaFeedback $job): bool => $job->serverId === (string) $server->getKey() && $job->accountingDate === '2026-10-27'
        );
        foreach ([$unverified, $inactive, $uninstalled, $foreign] as $excluded) {
            Queue::assertNotPushed(SyncManagedServerPmtaFeedback::class, fn (SyncManagedServerPmtaFeedback $job): bool => $job->serverId === (string) $excluded->getKey()
            );
        }

        $event = collect(app(Schedule::class)->events())->first(
            fn ($scheduled): bool => str_contains($scheduled->command, 'servers:process-pmta-feedback'),
        );
        $this->assertNotNull($event);
        $this->assertSame('*/2 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
    }

    public function test_recently_modified_feedback_is_left_in_process_until_it_is_stable_long_enough(): void
    {
        Carbon::setTestNow('2026-10-27 12:00:00');
        [$owner, $workspace, $server] = $this->serverFixture();
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $date = '2026-10-27';
        $path = "/etc/pmta/delivered/treated/d-{$date}-still-writing.csv";
        $connection = new FakePmtaSftpConnection(self::HOST_KEY, [
            $path => $this->csv([[$date, 'ok', 'recent@example.test', $this->dropToken($drop->getKey(), $server->getKey())]]),
        ]);
        $connection->fileAgeMinutes = 5;
        $this->bindSftp($server, $connection);

        $this->assertSame([
            'files_seen' => 1,
            'imported' => 0,
            'duplicate' => 0,
            'unmatched' => 0,
            'archived' => 0,
            'deferred' => 1,
        ], app(PmtaFeedbackRemoteProcessor::class)->process($server, $date));
        $this->assertSame([], $connection->filesUnder('/etc/pmta/delivered/treated'));
        $this->assertCount(1, $connection->filesUnder("/etc/pmta/delivered/process/{$date}"));
        $this->assertSame(0, $connection->downloadCount);
        $this->assertDatabaseCount('workspace_pmta_feedback_events', 0);
    }

    /** @return array{User, Workspace, ManagedServer} */
    private function serverFixture(): array
    {
        $owner = User::factory()->create();
        $workspace = $this->createWorkspace($owner, 'active', 'feedback-'.Str::lower(Str::random(8)));
        $server = $this->createServer($owner, $workspace);

        return [$owner, $workspace, $server];
    }

    private function createWorkspace(User $owner, string $status, string $slug): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'PMTA feedback workspace', 'slug' => $slug,
            'status' => $status, 'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }

    /** @param array<string, mixed> $overrides */
    private function createServer(User $owner, Workspace $workspace, array $overrides = []): ManagedServer
    {
        $fingerprint = (new SshHostKeyFingerprint)->fromPublicKey(self::HOST_KEY);
        $server = ManagedServer::query()->create(array_merge([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic PMTA server',
            'provider' => 'Synthetic provider', 'ip_address' => '198.51.100.'.$this->serverIpCounter++,
            'ssh_port' => 22, 'ssh_username' => 'root',
            'expected_host_fingerprint' => $fingerprint, 'state' => 'verified',
            'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('e', 64),
            'created_by' => $owner->getKey(),
        ], $overrides));
        ServerCredential::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(),
            'credential_type' => 'password', 'encrypted_secret' => Crypt::encryptString('synthetic-pmta-password'),
            'created_by' => $owner->getKey(),
        ]);

        return $server;
    }

    private function bindSftp(ManagedServer $server, FakePmtaSftpConnection $connection): void
    {
        $connector = new class($connection) implements DmsInstallerSshConnector
        {
            public function __construct(private readonly DmsInstallerSshConnection $connection) {}

            public function connect(string $host, int $port, int $timeoutSeconds): DmsInstallerSshConnection
            {
                return $this->connection;
            }
        };
        $this->app->instance(DmsInstallerSshSessionFactory::class, new DmsInstallerSshSessionFactory(
            $connector,
            new SshHostProbe,
            new SshHostKeyFingerprint,
        ));
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($stream, $row, escape: '');
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv === false ? '' : $csv;
    }

    private function dropToken(int|string $dropId, string $serverId, int|string $ispId = '1'): string
    {
        return implode('-', [
            (string) $dropId, '0', '0', 'fresh', '0', (string) $ispId, str_replace('-', '', $serverId),
            '4', '5', '6', '7', '8', 'app', 'd',
        ]);
    }
}

/** Synthetic SFTP server; no network connection or user PMTA host is touched. */
class FakePmtaSftpConnection implements DmsInstallerSshConnection
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, true> */
    private array $directories = [];

    public bool $authenticated = false;

    public int $downloadCount = 0;

    public ?int $stagedFileMode = null;

    public int $remoteOperationCount = 0;

    public int $fileAgeMinutes = 20;

    public bool $failNextArchiveMove = false;

    public function __construct(private readonly string $hostKey, array $files = [])
    {
        foreach ([
            '/etc/pmta/delivered/treated',
            '/etc/pmta/bounced/treated',
        ] as $directory) {
            $this->addDirectory($directory);
        }
        foreach ($files as $path => $contents) {
            $this->addFile($path, $contents);
        }
    }

    public function getServerPublicHostKey(): string|false
    {
        return $this->hostKey;
    }

    public function login(string $username, mixed $credential): bool
    {
        $this->authenticated = $username === 'root' && $credential === 'synthetic-pmta-password';

        return $this->authenticated;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function exec(string $command): string|false
    {
        return false;
    }

    public function uploadString(string $contents, string $destination): bool
    {
        return false;
    }

    public function uploadFile(string $localPath, string $destination): bool
    {
        return false;
    }

    public function downloadString(string $path): string|false
    {
        return false;
    }

    public function listDirectory(string $path): array|false
    {
        $this->remoteOperationCount++;
        if (! isset($this->directories[$path])) {
            return false;
        }

        $prefix = rtrim($path, '/').'/';
        $entries = ['.', '..'];
        foreach (array_keys($this->directories) as $directory) {
            if (str_starts_with($directory, $prefix)) {
                $entry = substr($directory, strlen($prefix));
                if ($entry !== '' && ! str_contains($entry, '/')) {
                    $entries[] = $entry;
                }
            }
        }
        foreach (array_keys($this->files) as $file) {
            if (str_starts_with($file, $prefix)) {
                $entry = substr($file, strlen($prefix));
                if ($entry !== '' && ! str_contains($entry, '/')) {
                    $entries[] = $entry;
                }
            }
        }

        return array_values(array_unique($entries));
    }

    public function isDirectory(string $path): bool
    {
        $this->remoteOperationCount++;

        return isset($this->directories[$path]);
    }

    public function createDirectory(string $path): bool
    {
        $this->remoteOperationCount++;
        $this->addDirectory($path);

        return true;
    }

    public function fileSize(string $path): int|false
    {
        $this->remoteOperationCount++;

        return isset($this->files[$path]) ? strlen($this->files[$path]) : false;
    }

    public function fileModifiedAt(string $path): int|false
    {
        $this->remoteOperationCount++;

        return isset($this->files[$path]) ? now()->subMinutes($this->fileAgeMinutes)->getTimestamp() : false;
    }

    public function downloadFile(string $remotePath, string $localPath): bool
    {
        $this->remoteOperationCount++;
        if (! isset($this->files[$remotePath])) {
            return false;
        }

        $this->downloadCount++;
        $written = file_put_contents($localPath, $this->files[$remotePath]) === strlen($this->files[$remotePath]);
        $this->stagedFileMode = fileperms($localPath);

        return $written;
    }

    public function moveFile(string $remotePath, string $destination): bool
    {
        $this->remoteOperationCount++;
        if ($this->failNextArchiveMove && str_contains($destination, '/archive/')) {
            $this->failNextArchiveMove = false;

            return false;
        }
        if (! isset($this->files[$remotePath]) || isset($this->files[$destination])) {
            return false;
        }

        $this->addDirectory(dirname($destination));
        $this->files[$destination] = $this->files[$remotePath];
        unset($this->files[$remotePath]);

        return true;
    }

    /** @return list<string> */
    public function filesUnder(string $directory): array
    {
        $prefix = rtrim($directory, '/').'/';

        return array_values(array_filter(array_keys($this->files), fn (string $path): bool => str_starts_with($path, $prefix)));
    }

    public function addFile(string $path, string $contents): void
    {
        $this->addDirectory(dirname($path));
        $this->files[$path] = $contents;
    }

    private function addDirectory(string $path): void
    {
        $current = '';
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            $current .= '/'.$segment;
            $this->directories[$current] = true;
        }
    }
}

class CountingWorkspaceDailyAnalyticsRebuilder extends WorkspaceDailyAnalyticsRebuilder
{
    public int $rebuildCount = 0;

    public function rebuildRange(Carbon $from, Carbon $to, ?string $workspaceId = null): int
    {
        $this->rebuildCount++;

        return parent::rebuildRange($from, $to, $workspaceId);
    }
}
