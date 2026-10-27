<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\AudienceListHistory;
use App\Models\AudienceMixSnapshot;
use App\Models\AudienceMixSnapshotMember;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceDrop;
use App\Models\WorkspaceDropVisitor;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AudienceMixWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_mix_page_renders_the_source_mapped_controls_and_isp_query_prefill(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['owner'])
            ->get(route('clean.console.audience-mixes.index', [
                $fixture['workspace']->getKey(),
                'sponsor_id' => $fixture['sponsor']->getKey(),
                'isp' => $fixture['isp']->getKey(),
                'country' => 'US',
            ]))
            ->assertOk()
            ->assertSee('CREATE MIX')
            ->assertSee('RECIPIENT CRITERIA')
            ->assertSee('LOAD LISTS')
            ->assertSee('Email Gender')
            ->assertSee('Email domain extensions')
            ->assertSee('ADD ACTIVITY FILTER')
            ->assertSee('MIX SELECTED LISTS')
            ->assertSee('value="'.$fixture['isp']->getKey().'"', false)
            ->assertSee('const sponsorId = '.$fixture['sponsor']->getKey().';', false);
    }

    public function test_mix_options_are_workspace_scoped_and_follow_dms_isp_country_and_event_choices(): void
    {
        $fixture = $this->fixture();
        $contact = $this->contact($fixture['workspace'], 'options@example.test');
        $this->membership($fixture, $contact, 'open');
        $this->visitor($fixture, $contact, 'open', 'United States', 'mobile');

        $response = $this->actingAs($fixture['owner'])
            ->getJson(route('console.audience-mixes.options', [
                $fixture['workspace']->getKey(),
                'isp_id' => $fixture['isp']->getKey(),
                'sponsor_id' => $fixture['sponsor']->getKey(),
            ]))->assertOk()
            ->assertJsonPath('data.0.id', $fixture['list']->getKey())
            ->assertJsonPath('data.0.counts.fresh', 3)
            ->assertJsonPath('filters.actions.0', 'open')
            ->assertJsonPath('filters.verticals.0', 'insurance')
            ->assertJsonFragment(['value' => (string) $fixture['offer']->getKey(), 'label' => 'Synthetic activity offer', 'group' => 'Synthetic mix sponsor']);
        $this->assertContains('United States', $response->json('filters.countries'));
        $this->assertContains('mobile', $response->json('filters.devices'));

        $otherSponsor = $this->sponsor($fixture['workspace'], 'Other sponsor');
        $this->actingAs($fixture['owner'])
            ->getJson(route('console.audience-mixes.options', [
                $fixture['workspace']->getKey(),
                'isp_id' => $fixture['isp']->getKey(),
                'sponsor_id' => $otherSponsor->getKey(),
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('isp_id');
    }

    public function test_mix_materializes_matching_engagement_membership_types_without_exposing_addresses(): void
    {
        $fixture = $this->fixture();
        $matching = $this->contact($fixture['workspace'], 'match@example.test', ['sex' => 'women']);
        $nonmatching = $this->contact($fixture['workspace'], 'other@example.test', ['sex' => 'women']);
        $this->membership($fixture, $matching, 'fresh');
        $secondList = $this->additionalList($fixture, 'Second engagement list');
        $this->membership($fixture, $matching, 'open', $secondList);
        $this->membership($fixture, $nonmatching, 'fresh');
        $this->visitor($fixture, $matching, 'open', 'United States', 'mobile');
        $this->visitor($fixture, $matching, 'open', 'United States', 'mobile', $secondList);
        $this->visitor($fixture, $nonmatching, 'click', 'Canada', 'desktop');

        $response = $this->actingAs($fixture['owner'])->postJson(route('console.audience-mixes.store', $fixture['workspace']->getKey()), [
            'name' => 'women with opens',
            'isp_id' => $fixture['isp']->getKey(),
            'sponsor_id' => $fixture['sponsor']->getKey(),
            'country' => 'US',
            'audience_segments' => [
                $fixture['list']->getKey().'_fresh',
                $secondList->getKey().'_open',
            ],
            'email_sex' => 'women',
            'email_device' => 'mobile',
            'last_insert_between' => [now()->subDay()->toDateString(), now()->toDateString()],
            'activity_between' => [now()->subDay()->toDateString(), now()->toDateString()],
            'extensions' => ['example.test'],
            'filters' => [[
                'effect' => 'include',
                'field' => 'country',
                'values' => ['United States'],
                'actions' => ['open'],
            ]],
        ]);

        $response->assertCreated()
            ->assertJsonPath('recipient_count', 2)
            ->assertJsonPath('status', 'active')
            ->assertJsonMissingPath('email');
        $snapshot = AudienceMixSnapshot::query()->whereKey($response->json('id'))->firstOrFail();
        $this->assertSame('mix_women with opens_2', $snapshot->name);
        $this->assertSame([(string) $fixture['list']->getKey(), (string) $secondList->getKey()], $snapshot->criteria['source_list_ids']);
        $this->assertSame(2, $snapshot->members()->count());
        $this->assertSame(
            ['fresh', 'open'],
            $snapshot->members()->orderBy('position')->pluck('email_type')->all(),
        );
        $this->assertSame(
            [$matching->getKey(), $matching->getKey()],
            $snapshot->members()->orderBy('position')->pluck('contact_id')->all(),
        );
        $this->assertStringNotContainsString('match@example.test', $response->getContent());

        $this->actingAs($fixture['owner'])
            ->get(route('clean.console.audience-mixes.history', $fixture['workspace']->getKey()))
            ->assertOk()
            ->assertSee('MIX HISTORY')
            ->assertSee('mix_women with opens_2');
    }

    public function test_empty_mix_returns_the_dms_zero_result_and_does_not_persist_a_snapshot(): void
    {
        $fixture = $this->fixture();
        $contact = $this->contact($fixture['workspace'], 'zero@example.test', ['sex' => 'women']);
        $this->membership($fixture, $contact, 'fresh');

        $this->actingAs($fixture['owner'])
            ->postJson(route('console.audience-mixes.store', $fixture['workspace']->getKey()), [
                'isp_id' => $fixture['isp']->getKey(),
                'audience_segments' => [$fixture['list']->getKey().'_fresh'],
                'filters' => [[
                    'effect' => 'include',
                    'field' => 'country',
                    'values' => ['No event country'],
                    'actions' => ['open'],
                ]],
            ])
            ->assertStatus(400)
            ->assertJsonPath('data', 'Found 0 Emails.');

        $this->assertDatabaseCount('audience_mix_snapshots', 0);
        $this->assertDatabaseCount('audience_mix_snapshot_members', 0);
    }

    public function test_mix_reads_sex_from_each_selected_list_membership(): void
    {
        $fixture = $this->fixture();
        $contact = $this->contact($fixture['workspace'], 'multi-list@example.test', ['sex' => 'women']);
        $this->membership($fixture, $contact, 'fresh', null, ['sex' => 'women', 'vertical' => 'retail']);
        $secondList = $this->additionalList($fixture, 'Second niche list');
        $this->membership($fixture, $contact, 'open', $secondList, ['sex' => 'men', 'vertical' => 'finance']);

        $response = $this->actingAs($fixture['owner'])->postJson(route('console.audience-mixes.store', $fixture['workspace']->getKey()), [
            'name' => 'women by source list',
            'isp_id' => $fixture['isp']->getKey(),
            'country' => 'US',
            'audience_segments' => [
                $fixture['list']->getKey().'_fresh',
                $secondList->getKey().'_open',
            ],
            'email_sex' => 'women',
        ])->assertCreated()
            ->assertJsonPath('recipient_count', 1);

        $snapshot = AudienceMixSnapshot::query()->whereKey($response->json('id'))->firstOrFail();
        $this->assertSame([(string) $fixture['list']->getKey()], $snapshot->members()->pluck('source_list_id')->all());
        $this->assertSame([(string) $contact->getKey()], $snapshot->members()->pluck('contact_id')->all());
    }

    public function test_expired_mix_members_are_removed_and_owner_can_remix_saved_criteria(): void
    {
        $fixture = $this->fixture();
        $contact = $this->contact($fixture['workspace'], 'remix@example.test');
        $this->membership($fixture, $contact, 'fresh');
        $criteria = [
            'isp_id' => (int) $fixture['isp']->getKey(),
            'sponsor_id' => (int) $fixture['sponsor']->getKey(),
            'country' => null,
            'audience_segments' => [$fixture['list']->getKey().'_fresh'],
            'source_list_ids' => [(string) $fixture['list']->getKey()],
            'email_sex' => null,
            'email_device' => null,
            'last_insert_between' => [],
            'extensions' => [],
            'filters' => [],
            'activity_between' => [],
        ];
        $old = AudienceMixSnapshot::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'created_by' => $fixture['owner']->getKey(),
            'isp_id' => $fixture['isp']->getKey(),
            'name' => 'mix_old_1',
            'criteria' => $criteria,
            'recipient_count' => 1,
            'status' => 'active',
        ]);
        AudienceMixSnapshotMember::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'snapshot_id' => $old->getKey(),
            'contact_id' => $contact->getKey(),
            'source_list_id' => $fixture['list']->getKey(),
            'email_type' => 'fresh',
            'position' => 1,
        ]);
        $old->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();

        $this->actingAs($fixture['owner'])
            ->get(route('clean.console.audience-mixes.history', $fixture['workspace']->getKey()))
            ->assertOk()
            ->assertSee('EXPIRED');
        $this->assertSame('expired', $old->fresh()->status);
        $this->assertSame(0, $old->members()->count());

        $this->actingAs($fixture['owner'])
            ->postJson(route('console.audience-mixes.remix', [$fixture['workspace']->getKey(), $old->getKey()]), ['name' => 'rebuilt'])
            ->assertCreated()
            ->assertJsonPath('recipient_count', 1);

        $this->assertDatabaseHas('audience_mix_snapshots', [
            'workspace_id' => $fixture['workspace']->getKey(),
            'created_by' => $fixture['owner']->getKey(),
            'name' => 'mix_rebuilt_1',
            'status' => 'active',
        ]);
    }

    /** @return array{owner: User, workspace: Workspace, isp: mixed, list: AudienceList, sponsor: WorkspaceSponsor, offer: WorkspaceOffer, drop: WorkspaceDrop} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic mix workspace',
            'slug' => 'synthetic-mix-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);
        $isp = $workspace->isps()->create([
            'name' => 'Synthetic mix ISP',
            'is_active' => true,
            'tracked_countries' => ['US', 'CA'],
            'allowed_users' => [],
            'allowed_sponsors' => [],
        ]);
        $sponsor = $this->sponsor($workspace, 'Synthetic mix sponsor');
        $isp->forceFill(['allowed_sponsors' => [(int) $sponsor->getKey()]])->save();
        $list = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Synthetic engagement list',
            'slug' => 'synthetic-engagement-list-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic engagement list'),
            'isp_id' => $isp->getKey(),
            'country' => 'US',
            'allowed_users' => [],
            'allowed_sponsors' => [(int) $sponsor->getKey()],
        ]);
        AudienceListHistory::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'at' => now()->toDateString(),
            'fresh' => 3,
            'delivery' => 0,
            'open' => 0,
            'click' => 0,
            'lead' => 0,
            'unsubscribe' => 0,
        ]);
        $offer = WorkspaceOffer::query()->create([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'mix-fixture-1',
            'name' => 'Synthetic activity offer',
            'type' => 'cpa',
            'vertical' => 'insurance',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://offers.example.test/mix',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $drop = WorkspaceDrop::query()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $owner->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'isp_id' => $isp->getKey(),
            'send_type' => 'test',
            'servers' => [],
            'lists' => [],
            'filters' => [],
            'countries' => [],
            'send_status' => 'complete',
            'is_processed' => true,
        ]);

        return compact('owner', 'workspace', 'isp', 'list', 'sponsor', 'offer', 'drop');
    }

    private function contact(Workspace $workspace, string $email, ?array $profile = null): Contact
    {
        return Contact::query()->create([
            'workspace_id' => $workspace->getKey(),
            'email' => $email,
            'email_lookup_hash' => hash('sha256', $email),
            'lookup_key_version' => 1,
            'profile' => $profile,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    private function sponsor(Workspace $workspace, string $name): WorkspaceSponsor
    {
        return WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => $name,
            'affiliate_id' => (string) Str::uuid(),
            'url' => 'https://affiliate.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-api-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $fixture */
    /** @param array<string, mixed> $fixture */
    private function membership(
        array $fixture,
        Contact $contact,
        string $emailType,
        ?AudienceList $list = null,
        ?array $profile = null,
    ): ContactListMembership {
        return ContactListMembership::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'audience_list_id' => ($list ?? $fixture['list'])->getKey(),
            'contact_id' => $contact->getKey(),
            'email_type' => $emailType,
            'status' => 'active',
            'joined_at' => now(),
            'profile' => $profile,
        ]);
    }

    /** @param array<string, mixed> $fixture */
    private function additionalList(array $fixture, string $name): AudienceList
    {
        return AudienceList::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'created_by' => $fixture['owner']->getKey(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name),
            'isp_id' => $fixture['isp']->getKey(),
            'country' => 'US',
            'allowed_users' => [],
            'allowed_sponsors' => [(int) $fixture['sponsor']->getKey()],
        ]);
    }

    /** @param array<string, mixed> $fixture */
    private function visitor(array $fixture, Contact $contact, string $type, string $country, string $device, ?AudienceList $list = null): WorkspaceDropVisitor
    {
        return WorkspaceDropVisitor::query()->create([
            'workspace_id' => $fixture['workspace']->getKey(),
            'workspace_drop_id' => $fixture['drop']->getKey(),
            'type' => $type,
            'revenue' => 0,
            'ip' => '203.0.113.20',
            'country' => $country,
            'device' => $device,
            'isp_id' => $fixture['isp']->getKey(),
            'list_id' => (string) ($list ?? $fixture['list'])->getKey(),
            'email_id' => (string) $contact->getKey(),
        ]);
    }
}
