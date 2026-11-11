<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\AppErrorLogReader;
use App\Services\OpenObserve\ErrorIntelligenceService;
use App\Services\OpenObserve\OpenObserveClient;
use App\Services\OpenObserve\OpenObserveLogHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class OpenObserveErrorIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['access_role' => User::ACCESS_ROLE_OWNER]);
        $this->workspace = Workspace::query()->create([
            'name' => 'Intelligence Workspace',
            'slug' => 'intel-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
    }

    public function test_intelligence_status_reports_fallback_mode_when_disabled(): void
    {
        config()->set('openobserve.enabled', false);

        /** @var ErrorIntelligenceService $service */
        $service = $this->app->make(ErrorIntelligenceService::class);
        $status = $service->getStatus();

        $this->assertFalse($status['configured']);
        $this->assertFalse($status['healthy']);
        $this->assertSame('local_fallback', $status['mode']);
    }

    public function test_intelligence_service_incorporates_failed_jobs_and_local_logs(): void
    {
        // Seed a failed job
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Jobs\SendCampaignDispatchJob', 'attempts' => 3]),
            'exception' => "RuntimeException: PMTA delivery failure\nat /app/SendJob.php:45\nsecret_token=super-secret",
            'failed_at' => now(),
        ]);

        /** @var ErrorIntelligenceService $service */
        $service = $this->app->make(ErrorIntelligenceService::class);
        $data = $service->getIntelligence();

        $this->assertArrayHasKey('hits', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('severity_breakdown', $data);
        $this->assertArrayHasKey('module_breakdown', $data);
        $this->assertGreaterThanOrEqual(1, $data['failed_jobs_count']);

        // Check failed job presence
        $failedJobHit = collect($data['hits'])->firstWhere('is_failed_job', true);
        $this->assertNotNull($failedJobHit);
        $this->assertStringContainsString('SendCampaignDispatchJob', $failedJobHit['summary']);
        $this->assertSame('horizon', $failedJobHit['module']);
    }

    public function test_app_errors_endpoint_returns_json_intelligence(): void
    {
        $response = $this->getJson(route('console.miscellaneous.app-errors', [
            'workspace' => $this->workspace->getKey(),
            'json' => 1,
        ]));

        $response->assertOk()
            ->assertJsonStructure([
                'mode',
                'total_errors',
                'critical_count',
                'failed_jobs_count',
                'top_module',
                'trend',
                'severity_breakdown',
                'module_breakdown',
                'hits',
                'pagination' => ['page', 'per_page', 'total', 'total_pages'],
                'status' => ['configured', 'healthy', 'mode'],
            ]);
    }

    public function test_sync_endpoint_requires_owner_or_admin_and_returns_sync_summary(): void
    {
        // Owner can call sync
        $response = $this->postJson(route('console.miscellaneous.app-errors.sync', $this->workspace->getKey()), [
            'limit' => 50,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['success', 'synced']);

        // Viewer/operator is forbidden
        $operator = User::factory()->create(['access_role' => User::ACCESS_ROLE_OPERATOR]);
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $this->actingAs($operator)
            ->postJson(route('console.miscellaneous.app-errors.sync', $this->workspace->getKey()))
            ->assertForbidden();
    }

    public function test_openobserve_log_handler_formats_and_redacts_sensitive_values(): void
    {
        $client = $this->createMock(OpenObserveClient::class);
        $client->method('isConfigured')->willReturn(true);
        $capturedRecords = [];

        $client->expects($this->once())
            ->method('ingest')
            ->willReturnCallback(function (array $records) use (&$capturedRecords) {
                $capturedRecords = $records;
                return ['success' => true, 'count' => count($records), 'error' => null];
            });

        $handler = new OpenObserveLogHandler($client);

        $record = new LogRecord(
            datetime: new \DateTimeImmutable('2026-11-11 12:00:00'),
            channel: 'production',
            level: Level::Error,
            message: 'User alice@example.com failed payment from 192.168.1.100 token=super_secret_token bearer synthetic',
            context: ['exception' => new \RuntimeException('Database failure on db.internal')],
            extra: ['request_id' => 'req-12345'],
        );

        $handler->handle($record);

        $this->assertCount(1, $capturedRecords);
        $ingested = $capturedRecords[0];

        $this->assertSame('ERROR', $ingested['level']);
        $this->assertSame('req-12345', $ingested['request_id']);
        $this->assertStringNotContainsString('alice@example.com', $ingested['message']);
        $this->assertStringNotContainsString('192.168.1.100', $ingested['message']);
        $this->assertStringNotContainsString('super_secret_token', $ingested['message']);
        $this->assertStringContainsString('[email]', $ingested['message']);
        $this->assertStringContainsString('[ip]', $ingested['message']);
    }

    public function test_artisan_sync_command_handles_offline_gracefully(): void
    {
        config()->set('openobserve.enabled', false);

        $this->artisan('openobserve:sync')
            ->assertFailed();
    }

    public function test_fingerprinting_clusters_recurring_errors(): void
    {
        /** @var ErrorIntelligenceService $service */
        $service = $this->app->make(ErrorIntelligenceService::class);

        $items = [
            [
                'id' => '1',
                '_timestamp' => 1000,
                'date' => '2026-11-11 12:00:00',
                'level' => 'ERROR',
                'module' => 'api',
                'summary' => 'Undefined variable $foo in blade template',
                'message' => 'Undefined variable $foo in blade template',
                'file' => 'index.blade.php',
                'line' => 45,
                'exception' => ['class' => 'ErrorException', 'trace' => '#0 /app/Test.php(45): run()'],
            ],
            [
                'id' => '2',
                '_timestamp' => 2000,
                'date' => '2026-11-11 12:05:00',
                'level' => 'ERROR',
                'module' => 'api',
                'summary' => 'Undefined variable $foo in blade template',
                'message' => 'Undefined variable $foo in blade template',
                'file' => 'index.blade.php',
                'line' => 45,
                'exception' => ['class' => 'ErrorException', 'trace' => '#0 /app/Test.php(45): run()'],
            ],
        ];

        $clusters = $service->clusterHits($items);

        $this->assertCount(1, $clusters);
        $this->assertSame(2, $clusters[0]['occurrences_count']);
        $this->assertSame('2026-11-11 12:00:00', $clusters[0]['first_seen']);
        $this->assertSame('2026-11-11 12:05:00', $clusters[0]['last_seen']);
    }

    public function test_archive_logs_endpoint_rotates_and_enforces_retention(): void
    {
        $response = $this->postJson(route('console.miscellaneous.app-errors.archive-logs', $this->workspace->getKey()));
        $response->assertOk()
            ->assertJsonStructure(['success', 'retained_count']);
    }
}
