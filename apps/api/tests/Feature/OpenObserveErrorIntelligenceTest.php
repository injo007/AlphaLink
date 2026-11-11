<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAppSettings;
use App\Services\AppErrorLogReader;
use App\Services\OpenObserve\ErrorIntelligenceService;
use App\Services\OpenObserve\OpenObserveClient;
use App\Services\OpenObserve\OpenObserveLogHandler;
use App\Services\Telegram\TelegramAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_telegram_custom_alert_redacts_secrets_and_preserves_custom_message(): void
    {
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'settings' => [
                'telegram_bot_token' => '123456:ABC-synthetic-token',
                'telegram_channel_it' => '-100999888777',
                'telegram_channel' => '-100111222333',
            ],
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
        ]);

        $response = $this->postJson(route('console.miscellaneous.app-errors.telegram.send', $this->workspace->getKey()), [
            'channel_type' => 'it',
            'custom_message' => 'Investigating outage for admin@example.com from 10.20.30.40',
            'error_summary' => 'Runtime failure token=super_secret_xyz',
            'level' => 'CRITICAL',
            'module' => 'horizon',
            'file' => 'SendJob.php:88',
            'occurrences_count' => 7,
            'include_summary' => true,
            'include_link' => true,
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'sent_channels' => ['it'],
            ]);

        Http::assertSent(function ($request) {
            $text = (string) ($request->data()['text'] ?? '');
            return str_contains($request->url(), 'api.telegram.org/bot123456:ABC-synthetic-token/sendMessage')
                && $request->data()['chat_id'] === '-100999888777'
                && str_contains($text, 'Investigating outage for [email] from [ip]')
                && str_contains($text, 'token=[redacted]')
                && str_contains($text, 'Occurrences:</b> 7x')
                && ! str_contains($text, 'super_secret_xyz')
                && ! str_contains($text, 'admin@example.com');
        });
    }

    public function test_telegram_rules_save_preserves_existing_bot_token_and_enforces_cooldown(): void
    {
        WorkspaceAppSettings::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'settings' => [
                'telegram_bot_token' => '987654:XYZ-encrypted-token',
                'telegram_channel_it' => '-100555666777',
            ],
        ]);

        $saveResponse = $this->postJson(route('console.miscellaneous.app-errors.telegram.config', $this->workspace->getKey()), [
            'enabled' => true,
            'target_channel' => 'it',
            'severity_threshold' => 'CRITICAL',
            'cooldown_minutes' => 30,
            'custom_prefix' => 'On-Call Alert',
            'custom_template' => '[{level}] {summary} ({occurrences}x)',
        ]);

        $saveResponse->assertOk()->assertJson(['success' => true]);

        // Ensure existing encrypted bot token was NOT overwritten
        $freshSettings = $this->workspace->fresh()->appSettings->settings;
        $this->assertSame('987654:XYZ-encrypted-token', $freshSettings['telegram_bot_token']);
        $this->assertSame('On-Call Alert', $freshSettings['error_alert_rules']['custom_prefix']);

        // Test rate-limiting cooldown on automated dispatch
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        /** @var TelegramAlertService $service */
        $service = $this->app->make(TelegramAlertService::class);
        $record = [
            'fingerprint' => 'fp-cooldown-test',
            'level' => 'CRITICAL',
            'module' => 'infrastructure',
            'summary' => 'PMTA socket unreachable',
            'file' => 'PmtaService.php',
            'occurrences_count' => 1,
        ];

        $this->assertTrue($service->dispatchAutomatedAlertIfEligible($this->workspace->fresh(), $record));
        $this->assertFalse($service->dispatchAutomatedAlertIfEligible($this->workspace->fresh(), $record)); // Blocked by 30m cooldown!

        Http::assertSentCount(1);
    }

    public function test_operator_cannot_access_telegram_alert_endpoints(): void
    {
        $operator = User::factory()->create(['access_role' => User::ACCESS_ROLE_OPERATOR]);
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $this->actingAs($operator)
            ->postJson(route('console.miscellaneous.app-errors.telegram.send', $this->workspace->getKey()), [
                'channel_type' => 'it',
                'error_summary' => 'Test',
            ])
            ->assertForbidden();
    }
}
