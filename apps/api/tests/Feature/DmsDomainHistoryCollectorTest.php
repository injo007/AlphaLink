<?php

namespace Tests\Feature;

use App\Models\SendingDomain;
use App\Models\SendingDomainHistory;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInfrastructureSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DmsDomainHistoryCollectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_collector_refuses_dns_queries_and_writes(): void
    {
        config(['infrastructure.domain_history_enabled' => false]);
        Process::fake();
        $workspace = $this->workspace('domain-history-disabled');
        $this->domain($workspace, 'disabled.example.test');

        $this->artisan('domain:check_history')->assertExitCode(1);

        Process::assertNothingRan();
        $this->assertDatabaseCount('sending_domain_histories', 0);
    }

    public function test_enabled_collector_matches_dms_records_for_all_active_domains(): void
    {
        config(['infrastructure.domain_history_enabled' => true]);
        $workspaceA = $this->workspace('domain-history-a');
        $workspaceB = $this->workspace('domain-history-b');
        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $workspaceA->getKey(),
            'dkim_key' => 'alpha-selector',
        ]);
        WorkspaceInfrastructureSettings::query()->create([
            'workspace_id' => $workspaceB->getKey(),
            'dkim_key' => 'beta-selector',
        ]);

        $activeA = $this->domain($workspaceA, 'domain-a.example.test', isOur: false);
        $activeB = $this->domain($workspaceB, 'domain-b.example.test', isOur: true);
        $this->domain($workspaceA, 'inactive.example.test', isActive: false);
        $deleted = $this->domain($workspaceA, 'deleted.example.test');
        $deleted->delete();
        $commands = [];

        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = $process->command;
            $commands[] = $command;
            $query = (string) end($command);
            $domainSuffix = str_contains($query, 'domain-a.example.test') ? 'domain-a' : 'domain-b';

            $output = match (true) {
                str_contains($query, '._domainkey.') => '"v=DKIM1; " "k=rsa; p='.$domainSuffix.'"',
                str_starts_with($query, '_dmarc.') => '"v=DMARC1; p=reject"',
                str_ends_with($query, '.dbl.spamhaus.org') => $domainSuffix === 'domain-a' ? '127.0.1.2' : '',
                str_ends_with($query, '.black.junkemailfilter.com') => $domainSuffix === 'domain-b' ? '127.0.0.4' : '',
                in_array('txt', $command, true) => '"v=spf1 include:" "mail.example -all"'."\n".'"v=spf2 invalid"',
                default => "192.0.2.21\n192.0.2.22",
            };

            return Process::result($output);
        });

        $this->artisan('domain:check_history')
            ->expectsOutput('Checking domains')
            ->expectsOutputToContain('DOMAIN LISTED : domain-a.example.test')
            ->assertExitCode(0);

        $historyA = $activeA->histories()->firstOrFail();
        $historyB = $activeB->histories()->firstOrFail();
        $this->assertSame('192.0.2.21', $historyA->a_record);
        $this->assertSame('v=spf1 include:mail.example -all', $historyA->spf);
        $this->assertSame('v=DKIM1; k=rsa; p=domain-a', $historyA->dkim);
        $this->assertSame('v=DMARC1; p=reject', $historyA->dmarc);
        $this->assertTrue($historyA->is_listed_spamhaus);
        $this->assertFalse($historyA->is_listed_hostkarma);
        $this->assertFalse($historyB->is_listed_spamhaus);
        $this->assertTrue($historyB->is_listed_hostkarma);
        $this->assertSame(2, $this->workspaceHistoryCount());
        $this->assertCount(12, $commands);
        $this->assertContains([
            'dig', '+short', '+time=2', '+tries=1', '-t', 'txt',
            'alpha-selector._domainkey.domain-a.example.test',
        ], $commands);
        $this->assertContains([
            'dig', '+short', '+time=2', '+tries=1', '-t', 'txt',
            'beta-selector._domainkey.domain-b.example.test',
        ], $commands);
        $this->assertNotContains('inactive.example.test', array_map(static fn (array $command): string => end($command), $commands));
        $this->assertNotContains('deleted.example.test', array_map(static fn (array $command): string => end($command), $commands));
    }

    public function test_empty_and_timed_out_dns_answers_still_create_a_dms_shaped_snapshot(): void
    {
        config(['infrastructure.domain_history_enabled' => true]);
        Process::fake(fn () => Process::result('timed out'));
        $domain = $this->domain($this->workspace('domain-history-timeout'), 'timeout.example.test');

        $this->artisan('domain:check_history')->assertExitCode(0);

        $history = $domain->histories()->firstOrFail();
        $this->assertSame('', $history->a_record);
        $this->assertNull($history->spf);
        $this->assertNull($history->dkim);
        $this->assertNull($history->dmarc);
        $this->assertFalse($history->is_listed_spamhaus);
        $this->assertFalse($history->is_listed_hostkarma);
    }

    public function test_history_retention_uses_the_dms_one_month_casablanca_cutoff(): void
    {
        $this->travelTo(Carbon::parse('2026-10-24 12:00:00', 'Africa/Casablanca'));
        $domain = $this->domain($this->workspace('domain-history-prune'), 'prune.example.test');
        $expired = SendingDomainHistory::factory()->forDomain($domain)->create([
            'created_at' => Carbon::parse('2026-09-23 23:59:59', 'Africa/Casablanca'),
            'updated_at' => Carbon::parse('2026-09-23 23:59:59', 'Africa/Casablanca'),
        ]);
        $retained = SendingDomainHistory::factory()->forDomain($domain)->create([
            'created_at' => Carbon::parse('2026-09-25 00:00:00', 'Africa/Casablanca'),
            'updated_at' => Carbon::parse('2026-09-25 00:00:00', 'Africa/Casablanca'),
        ]);

        $this->assertSame([$expired->getKey()], $domain->histories()->getModel()->prunable()->pluck('id')->all());
        $this->assertDatabaseHas('sending_domain_histories', ['id' => $retained->getKey()]);
    }

    public function test_history_job_is_scheduled_daily_at_the_dms_local_time_when_enabled(): void
    {
        config(['infrastructure.domain_history_enabled' => true]);
        $this->artisan('schedule:list')
            ->expectsOutputToContain('php artisan domain:check_history')
            ->assertExitCode(0);
    }

    private function workspace(string $slug): Workspace
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }

    private function domain(
        Workspace $workspace,
        string $name,
        bool $isActive = true,
        bool $isOur = false,
    ): SendingDomain {
        return $workspace->sendingDomains()->create([
            'name' => $name,
            'state' => 'pending',
            'verification_token' => 'domain-history-token-'.str_replace('.', '-', $name),
            'created_by' => $workspace->created_by,
            'status' => 'new',
            'is_our' => $isOur,
            'is_active' => $isActive,
            'have_who_is' => false,
            'have_renew' => false,
            'expire_at' => '2027-12-31',
        ]);
    }

    private function workspaceHistoryCount(): int
    {
        return SendingDomainHistory::query()->count();
    }
}
