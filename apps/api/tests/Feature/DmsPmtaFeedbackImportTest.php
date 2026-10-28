<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignRevision;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\Analytics\ImportDmsPmtaFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsPmtaFeedbackImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_drop_source_ownership_is_resolved_in_bounded_streaming_batches(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'PMTA batch fixture', 'slug' => 'pmta-batch-fixture', 'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Batch PMTA',
            'provider' => 'Synthetic provider', 'ip_address' => '198.51.100.36',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified',
            'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('f', 64),
            'created_by' => $owner->getKey(),
        ]);
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $date = today()->toDateString();
        $rows = [];
        for ($index = 0; $index < 125; $index++) {
            $email = 'batch-'.$index.'@example.test';
            $contactId = (string) Str::uuid();
            DB::table('contacts')->insert([
                'id' => $contactId,
                'workspace_id' => $workspace->getKey(),
                'email' => $email,
                'email_lookup_hash' => hash('sha256', $email),
                'lookup_key_version' => 1,
                'status' => 'active',
                'idempotency_key' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $token = implode('-', [
                (string) $drop->getKey(), '0', str_replace('-', '', $contactId), 'fresh', '0', '1',
                str_replace('-', '', (string) $server->getKey()), '4', '5', '6', '7', '8', 'app', 'd',
            ]);
            $rows[] = [$date, 'ok', $email, $token];
        }
        $stream = $this->csv($rows);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(ImportDmsPmtaFeedback::class)->import($server, $stream, 'delivery', 'batch-drop.csv', $date);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $dropLookups = array_filter($queries, fn (array $query): bool => str_contains(strtolower($query['query']), 'from "workspace_drops"'));
        $contactLookups = array_filter($queries, fn (array $query): bool => str_contains(strtolower($query['query']), 'from "contacts"'));
        $eventInserts = array_filter($queries, fn (array $query): bool => str_contains(strtolower($query['query']), 'workspace_pmta_feedback_events')
            && str_starts_with(ltrim(strtolower($query['query'])), 'insert'));
        $this->assertSame(['imported' => 125, 'duplicate' => 0, 'unmatched' => 0], $result);
        $this->assertCount(2, $dropLookups);
        $this->assertCount(2, $contactLookups);
        $this->assertCount(2, $eventInserts);
        $this->assertDatabaseCount('workspace_pmta_feedback_events', 125);
        $this->assertDatabaseHas('workspace_pmta_feedback_events', ['source_type' => 'drop', 'source_list_id' => null]);

        rewind($stream);
        $replay = app(ImportDmsPmtaFeedback::class)->import($server, $stream, 'delivery', 'batch-drop.csv', $date);
        $this->assertSame(['imported' => 0, 'duplicate' => 125, 'unmatched' => 0], $replay);
        $this->assertDatabaseCount('workspace_pmta_feedback_events', 125);

        fclose($stream);
    }

    public function test_campaign_source_validation_batches_workspace_snapshot_and_membership_queries(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'PMTA campaign batch fixture', 'slug' => 'pmta-campaign-batch-fixture', 'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Campaign batch PMTA',
            'provider' => 'Synthetic provider', 'ip_address' => '198.51.100.37',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified',
            'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('9', 64),
            'created_by' => $owner->getKey(),
        ]);
        $list = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Campaign batch list', 'slug' => 'pmta-campaign-batch-list', 'status' => 'active',
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'pmta-campaign-batch-list'),
        ]);
        $sponsor = WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Campaign batch sponsor',
            'affiliate_id' => (string) Str::uuid(), 'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user', 'password' => 'fixture-password', 'api_key' => 'fixture-key',
            'type' => 'everflow', 'allowed_users' => [], 'is_active' => true,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(), 'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'pmta-campaign-batch-offer', 'name' => 'Campaign batch offer', 'type' => 'cpa',
            'vertical' => 'testing', 'geo_target' => ['US'], 'payout' => 1,
            'redirect' => 'https://offer.example.test', 'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live', 'allowed_users' => [], 'is_active' => true,
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'PMTA batch campaign', 'kind' => 'marketing', 'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(), 'revision_number' => 1,
            'payload' => ['send_setup' => ['offer_id' => $offer->getKey()]],
            'content_sha256' => hash('sha256', 'pmta-campaign-batch-revision'),
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'pmta-campaign-batch-revision-request'),
            'created_by' => $owner->getKey(), 'created_at' => now(),
        ]);
        $contacts = [];
        for ($index = 0; $index < 125; $index++) {
            $email = 'campaign-batch-'.$index.'@example.test';
            $contacts[] = Contact::query()->create([
                'workspace_id' => $workspace->getKey(), 'email' => $email,
                'email_lookup_hash' => hash('sha256', $email), 'lookup_key_version' => 1,
                'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
            ]);
        }
        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(), 'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(), 'offer_id' => $offer->getKey(),
            'suppression_sha256' => hash('sha256', 'empty campaign batch suppression'),
            'selection' => ['audience_segments' => [$list->getKey().'_fresh']],
            'recipient_count' => count($contacts),
            'recipients_sha256' => hash('sha256', implode('|', array_map(fn (Contact $contact): string => (string) $contact->getKey(), $contacts))), 'created_at' => now(),
        ]);
        $rows = [];
        foreach ($contacts as $position => $contact) {
            CampaignRecipientSnapshotItem::query()->create([
                'workspace_id' => $workspace->getKey(), 'snapshot_id' => $snapshot->getKey(),
                'contact_id' => $contact->getKey(), 'position' => $position + 1,
                'email_lookup_hash' => $contact->email_lookup_hash, 'source_list_id' => $list->getKey(),
                'source_email_type' => 'fresh', 'created_at' => now(),
            ]);
            $token = implode('-', [
                str_replace('-', '', (string) $campaign->getKey()),
                str_replace('-', '', (string) $snapshot->getKey()),
                str_replace('-', '', (string) $contact->getKey()), 'fresh',
                str_replace('-', '', (string) $list->getKey()), '1',
                str_replace('-', '', (string) $server->getKey()), '2', '3', '4', '5', '6', 'app', 'c',
            ]);
            $rows[] = [today()->toDateString(), 'ok', $contact->email, $token];
        }
        $stream = $this->csv($rows);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = app(ImportDmsPmtaFeedback::class)->import($server, $stream, 'delivery', 'campaign-batch.csv', today()->toDateString());
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $campaignReads = array_filter($queries, fn (array $query): bool => str_contains(strtolower($query['query']), 'select "id", "kind" from "campaigns"'));
        $snapshotReads = array_filter($queries, fn (array $query): bool => str_contains(strtolower($query['query']), 'select "id", "campaign_id" from "campaign_recipient_snapshots"'));
        $itemReads = array_filter($queries, fn (array $query): bool => str_contains(strtolower($query['query']), 'select "snapshot_id", "contact_id", "source_list_id", "source_email_type" from "campaign_recipient_snapshot_items"'));
        $eventInserts = array_filter($queries, fn (array $query): bool => str_contains(strtolower($query['query']), 'workspace_pmta_feedback_events')
            && str_starts_with(ltrim(strtolower($query['query'])), 'insert'));
        $this->assertSame(['imported' => 125, 'duplicate' => 0, 'unmatched' => 0], $result);
        $this->assertCount(2, $campaignReads);
        $this->assertCount(2, $snapshotReads);
        $this->assertCount(2, $itemReads);
        $this->assertCount(2, $eventInserts);
        $this->assertDatabaseCount('workspace_pmta_feedback_events', 125);

        fclose($stream);
    }

    public function test_drop_feedback_is_deduplicated_and_rebuilt_into_daily_counters(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'PMTA feedback fixture', 'slug' => 'pmta-feedback-fixture',
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Synthetic PMTA',
            'provider' => 'Synthetic provider', 'ip_address' => '198.51.100.32',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified',
            'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64),
            'created_by' => $owner->getKey(),
        ]);
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();

        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other PMTA feedback fixture', 'slug' => 'other-pmta-feedback-fixture',
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherServer = ManagedServer::query()->create([
            'workspace_id' => $otherWorkspace->getKey(), 'name' => 'Other synthetic PMTA',
            'provider' => 'Synthetic provider', 'ip_address' => '198.51.100.34',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified',
            'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('c', 64),
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherDrop = WorkspaceDrop::factory()->forWorkspace($otherWorkspace, $otherOwner)->create();
        DB::table('workspace_pmta_feedback_events')->insert([
            'workspace_id' => $otherWorkspace->getKey(),
            'managed_server_id' => $otherServer->getKey(),
            'record_key' => hash('sha256', 'other-workspace-feedback'),
            'at' => today()->toDateString(),
            'source_type' => 'drop',
            'source_id' => (string) $otherDrop->getKey(),
            'workspace_drop_id' => $otherDrop->getKey(),
            'metric' => 'deliveries',
            'dimension_ids' => json_encode(['server_id' => (string) $otherServer->getKey()], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);

        $token = implode('-', [
            (string) $drop->getKey(), '0', '0', 'fresh', '0', '1',
            str_replace('-', '', (string) $server->getKey()), '1', '1', '1', '1', '1', 'app', 'd',
        ]);
        $date = today()->toDateString();
        $delivery = $this->csv([[$date, 'ok', 'recipient@example.test', $token]]);
        $bounces = $this->csv([
            [$date, 'bad-mailbox', 'ignored', 'ignored', $token],
            [$date, 'policy-rejection', 'ignored', 'ignored', $token],
        ]);
        $importer = app(ImportDmsPmtaFeedback::class);

        $this->assertSame(['imported' => 1, 'duplicate' => 0, 'unmatched' => 0], $importer->import($server, $delivery, 'delivery', 'd-file.csv', $date));
        rewind($delivery);
        $this->assertSame(['imported' => 0, 'duplicate' => 1, 'unmatched' => 0], $importer->import($server, $delivery, 'delivery', 'd-file.csv', $date));
        $this->assertSame(['imported' => 2, 'duplicate' => 0, 'unmatched' => 0], $importer->import($server, $bounces, 'bounce', 'b-file.csv', $date));

        $this->assertSame(3, DB::table('workspace_pmta_feedback_events')->where('workspace_id', $workspace->getKey())->count());
        $daily = DB::table('workspace_daily_analytics')->where('workspace_id', $workspace->getKey())->where('workspace_drop_id', $drop->getKey())->first();
        $this->assertNotNull($daily);
        $this->assertSame(1, (int) $daily->deliveries);
        $this->assertSame(1, (int) $daily->hard_bounces);
        $this->assertSame(1, (int) $daily->soft_bounces);
        $this->assertSame(0, (int) $daily->opens);
        $this->assertDatabaseMissing('workspace_daily_analytics', [
            'workspace_id' => $otherWorkspace->getKey(),
            'source_type' => 'drop',
            'source_id' => (string) $otherDrop->getKey(),
        ]);

        $wrongServerToken = str_replace(str_replace('-', '', (string) $server->getKey()), str_repeat('0', 32), $token);
        $wrongServer = $this->csv([[$date, 'ok', 'recipient@example.test', $wrongServerToken]]);
        $this->assertSame(['imported' => 0, 'duplicate' => 0, 'unmatched' => 1], $importer->import($server, $wrongServer, 'delivery', 'wrong-server.csv', $date));

        $this->actingAs($owner)->get(route('console.dashboard.main'))
            ->assertOk()
            ->assertSee('TODAY DELIVERIES')
            ->assertSee('SOFT: 1')
            ->assertSee('HARD: 1');
        $this->getJson(route('clean.console.analytics.all-stats', ['from' => $date, 'to' => $date]))
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonFragment(['deliveries' => 1])
            ->assertJsonFragment(['soft_bounces' => 1])
            ->assertJsonFragment(['hard_bounces' => 1]);

        fclose($delivery);
        fclose($bounces);
        fclose($wrongServer);
    }

    public function test_delivery_and_hard_bounce_follow_dms_recipient_type_precedence(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'PMTA type fixture', 'slug' => 'pmta-type-fixture', 'created_by' => $owner->getKey(),
        ]);
        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Type PMTA',
            'provider' => 'Synthetic provider', 'ip_address' => '198.51.100.33',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified',
            'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('b', 64),
            'created_by' => $owner->getKey(),
        ]);
        $drop = WorkspaceDrop::factory()->forWorkspace($workspace, $owner)->create();
        $list = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'PMTA list', 'slug' => 'pmta-list', 'status' => 'active',
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => hash('sha256', 'pmta-list'),
        ]);
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(), 'email' => 'pmta@example.test',
            'email_lookup_hash' => hash('sha256', 'pmta@example.test'), 'lookup_key_version' => 1,
            'status' => 'active', 'idempotency_key' => (string) Str::uuid(),
        ]);
        $membership = ContactListMembership::query()->create([
            'workspace_id' => $workspace->getKey(), 'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(), 'email_type' => 'fresh', 'status' => 'active', 'joined_at' => now(),
        ]);
        $token = implode('-', [
            (string) $drop->getKey(), '0', str_replace('-', '', (string) $contact->getKey()), 'f',
            str_replace('-', '', (string) $list->getKey()), '1', str_replace('-', '', (string) $server->getKey()),
            '1', '1', '1', '1', '1', 'app', 'd',
        ]);
        $date = today()->toDateString();
        $importer = app(ImportDmsPmtaFeedback::class);
        $delivery = $this->csv([[$date, 'ok', 'pmta@example.test', $token]]);
        $bounce = $this->csv([[$date, 'bad-domain', 'ignored', 'ignored', $token]]);

        $importer->import($server, $delivery, 'delivery', 'type-delivery.csv', $date);
        $this->assertSame('delivery', $membership->fresh()->email_type);
        $importer->import($server, $bounce, 'bounce', 'type-bounce.csv', $date);
        $this->assertSame('hard_bounce', $membership->fresh()->email_type);
        $this->assertSame(2, DB::table('contact_list_membership_events')->where('workspace_id', $workspace->getKey())->count());

        $membership->forceFill(['email_type' => 'lead'])->save();
        rewind($bounce);
        $importer->import($server, $bounce, 'bounce', 'type-bounce-second.csv', $date);
        $this->assertSame('lead', $membership->fresh()->email_type);
        $this->assertSame(2, DB::table('contact_list_membership_events')->where('workspace_id', $workspace->getKey())->count());

        fclose($delivery);
        fclose($bounce);
    }

    public function test_warmup_feedback_requires_a_workspace_warmup_campaign_and_rebuilds_its_daily_row(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Warmup feedback fixture', 'slug' => 'warmup-feedback-fixture', 'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $server = ManagedServer::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'Warmup PMTA',
            'provider' => 'Synthetic provider', 'ip_address' => '198.51.100.35',
            'ssh_port' => 22, 'ssh_username' => 'root', 'state' => 'verified',
            'is_active' => true, 'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('d', 64),
            'created_by' => $owner->getKey(),
        ]);
        $warmup = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(), 'created_by' => $owner->getKey(),
            'name' => 'Warmup run', 'kind' => 'warmup', 'status' => 'completed',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $foreignOwner = User::factory()->create();
        $foreignWorkspace = Workspace::query()->create([
            'name' => 'Foreign warmup fixture', 'slug' => 'foreign-warmup-fixture', 'created_by' => $foreignOwner->getKey(),
        ]);
        $foreignWarmup = Campaign::query()->create([
            'workspace_id' => $foreignWorkspace->getKey(), 'created_by' => $foreignOwner->getKey(),
            'name' => 'Foreign warmup run', 'kind' => 'warmup', 'status' => 'completed',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $date = today()->toDateString();
        $serverToken = str_replace('-', '', (string) $server->getKey());
        $warmupToken = implode('-', [
            str_replace('-', '', (string) $warmup->getKey()), '0', '0', 'fresh', '0', '1', $serverToken,
            '5', '6', '7', '8', '9', 'app', 'w',
        ]);
        $foreignToken = str_replace(str_replace('-', '', (string) $warmup->getKey()), str_replace('-', '', (string) $foreignWarmup->getKey()), $warmupToken);
        $stream = $this->csv([
            [$date, 'ok', 'not-persisted@example.test', $warmupToken],
            [$date, 'ok', 'not-persisted@example.test', $foreignToken],
        ]);

        $this->assertSame(
            ['imported' => 1, 'duplicate' => 0, 'unmatched' => 1],
            app(ImportDmsPmtaFeedback::class)->import($server, $stream, 'delivery', 'warmup-run.csv', $date),
        );
        $this->assertDatabaseHas('workspace_daily_analytics', [
            'workspace_id' => $workspace->getKey(),
            'source_type' => 'warmup',
            'source_id' => $warmup->getKey(),
            'server_id' => (string) $server->getKey(),
            'ip_id' => '5',
            'domain_id' => '6',
            'deliveries' => 1,
        ]);
        $this->assertDatabaseMissing('workspace_daily_analytics', [
            'workspace_id' => $foreignWorkspace->getKey(),
            'source_type' => 'warmup',
            'source_id' => $foreignWarmup->getKey(),
        ]);
        $this->assertDatabaseHas('workspace_pmta_feedback_events', [
            'workspace_id' => $workspace->getKey(),
            'source_type' => 'warmup',
            'source_id' => $warmup->getKey(),
        ]);
        $this->assertStringNotContainsString('not-persisted@example.test', json_encode(DB::table('workspace_pmta_feedback_events')->first(), JSON_THROW_ON_ERROR));

        $this->actingAs($owner)->get(route('console.dashboard.main'))->assertOk();
        $this->get(route('clean.console.analytics.all-stats', ['from' => $date, 'to' => $date]))
            ->assertOk()
            ->assertSee('Seed Warmup run')
            ->assertSee('matching warmup/seed rows');
        $this->getJson(route('clean.console.analytics.all-stats', ['from' => $date, 'to' => $date]))
            ->assertOk()
            ->assertJsonFragment(['deliveries' => 1]);

        fclose($stream);
    }

    /** @param list<list<string>> $rows
     * @return resource
     */
    private function csv(array $rows)
    {
        $stream = fopen('php://temp', 'w+');
        foreach ($rows as $row) {
            fputcsv($stream, $row, escape: '');
        }
        rewind($stream);

        return $stream;
    }
}
