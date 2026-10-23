<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DmsBulkInstallRowRunner;
use App\Services\Infrastructure\DmsBulkInstallRunCoordinator;
use App\Services\Infrastructure\DmsBulkInstallTranscriptStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class InfrastructureBulkInstallLogsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private string $transcriptRoot;

    private DmsBulkInstallTranscriptStore $transcripts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Transcript demo',
            'slug' => 'transcript-demo',
            'created_by' => $this->owner->id,
        ]);
        $this->workspace->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->actingAs($this->owner);

        $this->transcriptRoot = storage_path('framework/testing/bulk-install-transcripts-'.Str::uuid());
        $this->transcripts = new DmsBulkInstallTranscriptStore($this->transcriptRoot);
        $this->app->instance(DmsBulkInstallTranscriptStore::class, $this->transcripts);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->transcriptRoot);

        parent::tearDown();
    }

    public function test_fake_install_run_persists_and_returns_the_exact_timestamped_dms_transcript(): void
    {
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        $runner = new class implements DmsBulkInstallRowRunner
        {
            public function install(array $row, array $options, callable $log): void
            {
                $log('Prepare Installation');
            }
        };

        Carbon::setTestNow(Carbon::parse('2026-10-23 15:04:05'));
        try {
            $result = (new DmsBulkInstallRunCoordinator($runner))->execute([
                'data' => [['id' => 'server-1', 'domain' => 'domain-1']],
                'key' => '1782155053000',
                'pmta_version' => 45,
                'tracking' => false,
                'proxy' => false,
                'imap' => false,
                'spf' => false,
                'dkim' => false,
                'dmarc' => false,
                'https' => false,
            ], fn (string $id): string => 'RELAY', function (string $line) use ($runId): void {
                $this->transcripts->append((string) $this->workspace->getKey(), $runId, $line);
            });
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame([
            '[23/10/2026-15:04:05] Installation Started',
            '[23/10/2026-15:04:05] - [RELAY:server-1] - Prepare Installation',
        ], $result['logs']);
        $this->assertSame([['server_id' => 'server-1', 'status' => 'succeeded']], $result['results']);
        $expected = implode(PHP_EOL, $result['logs']).PHP_EOL;

        $response = $this->getJson($this->logsUrl($runId))
            ->assertOk()
            ->assertHeader('content-type', 'application/json');

        $this->assertSame($expected, $response->json());
        $this->assertSame(json_encode($expected), $response->getContent());
    }

    public function test_a_transcript_cannot_be_read_through_another_workspace_path(): void
    {
        $otherWorkspace = $this->workspace('Other workspace', 'other-workspace', $this->owner);
        $runId = $this->transcripts->createRun((string) $otherWorkspace->getKey());
        $this->transcripts->append((string) $otherWorkspace->getKey(), $runId, '[23/10/2026-15:04:05] Installation Started');

        $this->getJson($this->logsUrl($runId))->assertNotFound();
    }

    public function test_nonmember_cannot_read_a_workspace_transcript(): void
    {
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->getJson($this->logsUrl($runId))
            ->assertNotFound();
    }

    public function test_workspace_operator_cannot_read_the_manager_install_transcript(): void
    {
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->id, ['role' => 'operator']);

        $this->actingAs($operator)
            ->getJson($this->logsUrl($runId))
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_read_a_transcript(): void
    {
        $runId = $this->transcripts->createRun((string) $this->workspace->getKey());
        Auth::logout();

        $this->getJson($this->logsUrl($runId))->assertUnauthorized();
    }

    public function test_missing_and_malformed_transcript_ids_return_not_found(): void
    {
        $this->getJson($this->logsUrl((string) Str::uuid()))->assertNotFound();
        $this->getJson($this->logsUrl('not-a-uuid'))->assertNotFound();
    }

    private function logsUrl(string $runId): string
    {
        return '/app/workspaces/'.$this->workspace->getKey().'/infrastructure/bulk-install/runs/'.$runId.'/logs';
    }

    private function workspace(string $name, string $slug, User $owner): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => $slug,
            'created_by' => $owner->id,
        ]);
        $workspace->users()->attach($owner->id, ['role' => 'owner']);

        return $workspace;
    }
}
