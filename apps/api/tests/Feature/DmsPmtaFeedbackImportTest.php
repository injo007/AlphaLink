<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ManagedServer;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Services\Analytics\ImportDmsPmtaFeedback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsPmtaFeedbackImportTest extends TestCase
{
    use RefreshDatabase;

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
