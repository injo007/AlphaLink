<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\AudienceMixSnapshot;
use App\Models\AudienceMixSnapshotMember;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItemAddress;
use App\Models\CampaignRevision;
use App\Models\CampaignTestMailAttempt;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerIpHistory;
use App\Models\ManagedServerMta;
use App\Models\PmtaActionHistory;
use App\Models\PmtaVirtualMta;
use App\Models\SendingDomain;
use App\Models\SuppressionEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceOfferAsset;
use App\Models\WorkspaceSponsor;
use App\Services\Campaigns\DmsCampaignRecipientSnapshotBuilder;
use App\Services\Campaigns\DmsPmtaAdminRestart;
use App\Services\Campaigns\PmtaCampaignTestTransport;
use App\Services\Infrastructure\DmsBulkInstallRuntimeInputs;
use App\Services\PmtaVirtualMtaConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

class DmsCampaignSendCatalogTest extends TestCase
{
    use RefreshDatabase;

    private mixed $originalContactLookupKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalContactLookupKey = config('alphalink.contact_lookup_key');
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        config(['alphalink.contact_lookup_key' => $this->originalContactLookupKey]);
        parent::tearDown();
    }

    public function test_owner_receives_dms_filtered_campaign_choices_and_workspace_pmta_panel_port(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $sponsor = $this->sponsor($workspace);
        $offer = $this->offer($workspace, $sponsor, [
            'is_active' => true,
            'suppression_path' => 'fresh-suppression.txt',
            'suppression_updated_at' => now()->toDateString(),
        ]);
        $creativeAsset = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'type' => 'creative',
            'value' => 'offers/'.$workspace->getKey().'/'.$offer->getKey().'/assets/creative/fixture.png',
        ]);
        WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'type' => 'from',
            'value' => 'Fixture Sender <sender@example.test>',
        ]);
        WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'type' => 'subject',
            'value' => 'Fixture subject',
        ]);
        $this->offer($workspace, $sponsor, [
            'is_active' => true,
            'suppression_path' => 'stale-suppression.txt',
            'suppression_updated_at' => now()->subDays(8)->toDateString(),
        ]);
        $disabledOffer = $this->offer($workspace, $sponsor, [
            'is_active' => false,
            'suppression_path' => 'disabled-suppression.txt',
            'suppression_updated_at' => now()->toDateString(),
        ]);
        $isp = $workspace->isps()->create([
            'name' => 'Fixture ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [(int) $sponsor->getKey()],
        ]);
        $list = $this->list($workspace, $owner, $isp->getKey(), $sponsor->getKey());
        $mix = AudienceMixSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'isp_id' => $isp->getKey(),
            'name' => 'mix_fixture_1',
            'criteria' => ['source_list_ids' => [(string) $list->getKey()]],
            'recipient_count' => 1,
            'status' => 'active',
        ]);
        AudienceMixSnapshotMember::query()->create([
            'workspace_id' => $workspace->getKey(),
            'snapshot_id' => $mix->getKey(),
            'contact_id' => ContactListMembership::query()->where('audience_list_id', $list->getKey())->value('contact_id'),
            'source_list_id' => $list->getKey(),
            'email_type' => 'fresh',
            'position' => 1,
        ]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'send.example.test',
            'state' => 'verified',
            'verification_token' => 'fixture-domain-token',
            'created_by' => $owner->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);
        $server = $this->server($workspace, $owner, '203.0.113.40');
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.41',
            'type' => 'ipv4',
            'dns' => 'mta.send.example.test',
            'is_active' => true,
        ]);
        SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'redirect.send.example.test',
            'state' => 'verified',
            'verification_token' => 'fixture-redirect-token',
            'created_by' => $owner->getKey(),
            'status' => 'redirect',
            'is_our' => true,
            'is_active' => true,
        ]);
        $pushedVmta = PmtaVirtualMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'created_by' => $owner->getKey(),
            'ip' => $ip->ip,
            'domain' => 'vmta.send.example.test',
            'name' => 'v_1',
            'duplicate_index' => 1,
            'route' => false,
            'is_active' => true,
        ]);
        ManagedServerMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'ip' => $ip->ip,
            'dns' => $ip->dns,
            'ip_type' => 'ipv4',
            'mta_type' => 'vmta',
            'username' => 'fixture-vmta',
            'name' => 'v_1',
            'pmta_virtual_mta_id' => $pushedVmta->getKey(),
            'is_active' => true,
            'user_id' => $owner->getKey(),
        ]);
        $workspace->infrastructureSettings()->create([
            'pmta_admin_port' => 2447,
        ]);

        $response = $this->actingAs($owner)->getJson(route('clean.console.campaigns.send-options', [
            $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'isp_id' => $isp->getKey(),
            'country' => 'US',
            'server_ids' => [$server->getKey()],
            'route' => 'ip',
            'ip_type' => 'ipv4',
        ]))->assertOk();

        $response->assertJsonPath('data.sponsors.0.id', $sponsor->getKey())
            ->assertJsonPath('data.offers.0.id', $offer->getKey())
            ->assertJsonMissingPath('data.offers.1')
            ->assertJsonPath('data.isps.0.id', $isp->getKey())
            ->assertJsonPath('data.lists.0.id', $list->getKey())
            ->assertJsonPath('data.servers.0.id', $server->getKey())
            ->assertJsonPath('data.servers.0.pmta_panel_url', '//203.0.113.40:2447/')
            ->assertJsonPath('data.ips.0.id', $ip->getKey())
            ->assertJsonPath('data.campaign_domains.0.value', 'redirect.send.example.test')
            ->assertJsonPath('data.campaign_domains.0.name', 'REDIRECT')
            ->assertJsonPath('data.campaign_domains.1.value', 'mta.send.example.test')
            ->assertJsonPath('data.settings.pmta_admin_port', 2447)
            ->assertJsonMissingPath('data.offers.1.id');
        $response->assertJsonFragment([
            'id' => $mix->getKey().'_mix',
            'list_id' => null,
            'list_ids' => [(string) $list->getKey()],
            'name' => $mix->name,
            'country' => 'MIX',
            'email_type' => 'mix',
            'recipient_count' => 1,
            'locked' => false,
            'is_mix' => true,
        ]);

        $response->assertJsonFragment([
            'type' => 'from',
            'value' => 'Fixture Sender <sender@example.test>',
        ]);
        $response->assertJsonFragment([
            'id' => $creativeAsset->getKey(),
            'type' => 'creative',
            'public_url' => route('public.offer-assets.file', [
                $workspace->getKey(),
                $offer->getKey(),
                $creativeAsset->getKey(),
            ], true),
        ]);

        $vmtaResponse = $this->actingAs($owner)->getJson(route('clean.console.campaigns.send-options', [
            $workspace->getKey(),
            'server_ids' => [$server->getKey()],
            'route' => 'vmta',
            'ip_type' => 'ipv4',
        ]))->assertOk();
        $vmtaResponse->assertJsonPath('data.ips.0.name', 'v_1')
            ->assertJsonPath('data.ips.0.server_id', $server->getKey())
            ->assertJsonPath('data.ips.0.vmta_domain', 'vmta.send.example.test');

        $this->assertNotSame($offer->getKey(), $disabledOffer->getKey());
    }

    public function test_operator_catalog_requires_both_sponsor_and_offer_access_and_scopes_isp_lists_and_servers(): void
    {
        $owner = User::factory()->create();
        $operator = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $sponsor = $this->sponsor($workspace, [(int) $operator->getKey()]);
        $offer = $this->offer($workspace, $sponsor, [
            'allowed_users' => [(int) $operator->getKey()],
            'is_active' => true,
            'suppression_path' => 'fresh-suppression.txt',
            'suppression_updated_at' => now()->toDateString(),
        ]);
        $deniedOffer = $this->offer($workspace, $sponsor, [
            'allowed_users' => [],
            'is_active' => true,
            'suppression_path' => 'fresh-suppression.txt',
            'suppression_updated_at' => now()->toDateString(),
        ]);
        $isp = $workspace->isps()->create([
            'name' => 'Assigned ISP',
            'is_active' => true,
            'allowed_users' => [(int) $operator->getKey()],
            'allowed_sponsors' => [(int) $sponsor->getKey()],
        ]);
        $list = $this->list($workspace, $owner, $isp->getKey(), $sponsor->getKey(), [(int) $operator->getKey()]);
        $server = $this->server($workspace, $owner, '203.0.113.42');
        $server->allowedUsers()->attach($operator->getKey(), ['workspace_id' => $workspace->getKey()]);
        $deniedServer = $this->server($workspace, $owner, '203.0.113.43');
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'operator.example.test',
            'state' => 'verified',
            'verification_token' => 'operator-domain-token',
            'created_by' => $owner->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.44',
            'type' => 'ipv4',
            'dns' => 'relay.operator.example.test',
            'is_active' => true,
        ]);
        ManagedServerMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'ip' => $ip->ip,
            'dns' => $ip->dns,
            'ip_type' => 'ipv4',
            'mta_type' => 'smtp',
            'username' => 'fixture-smtp',
            'is_active' => true,
            'user_id' => $operator->getKey(),
        ]);

        $response = $this->actingAs($operator)->getJson(route('console.campaigns.send-options', [
            $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'isp_id' => $isp->getKey(),
            'server_ids' => [$server->getKey(), $deniedServer->getKey()],
            'route' => 'smtp',
            'ip_type' => 'all',
        ]))->assertOk();

        $response->assertJsonPath('data.sponsors.0.id', $sponsor->getKey())
            ->assertJsonPath('data.offers.0.id', $offer->getKey())
            ->assertJsonMissingPath('data.offers.1')
            ->assertJsonPath('data.isps.0.id', $isp->getKey())
            ->assertJsonPath('data.lists.0.id', $list->getKey())
            ->assertJsonPath('data.servers.0.id', $server->getKey())
            ->assertJsonMissingPath('data.servers.1')
            ->assertJsonPath('data.ips.0.id', $ip->getKey())
            ->assertJsonPath('data.ips.0.username', 'fixture-smtp');

        $this->assertNotSame($offer->getKey(), $deniedOffer->getKey());
    }

    public function test_send_catalog_is_workspace_scoped_and_rejects_invalid_selector_filters(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');

        $this->actingAs($owner)
            ->getJson(route('console.campaigns.send-options', [$workspace->getKey(), 'route' => 'unsafe']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('route');

        $otherWorkspace = $this->workspace(User::factory()->create(), 'owner');
        $this->getJson(route('console.campaigns.send-options', $otherWorkspace->getKey()))
            ->assertNotFound();
    }

    public function test_marketing_draft_persists_the_validated_dms_targeting_and_send_setup(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $lookupKey = str_repeat('synthetic-campaign-contact-key-', 2);
        config(['alphalink.contact_lookup_key' => $lookupKey]);

        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $negative = $workspace->campaignNegatives()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Synthetic DMS negative',
            'path' => 'campaign-negatives/synthetic.txt',
            'original_filename' => 'synthetic.txt',
            'sha256' => hash('sha256', 'fixture negative'),
            'size_bytes' => 16,
        ]);
        $sponsor = $this->sponsor($workspace);
        $offer = $this->offer($workspace, $sponsor, [
            'is_active' => true,
            'suppression_path' => 'fresh-suppression.txt',
            'suppression_updated_at' => now()->toDateString(),
        ]);
        $fromAsset = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'type' => 'from',
            'value' => 'Fixture Sender <sender@example.test>',
            'is_active' => true,
        ]);
        $subjectAsset = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'type' => 'subject',
            'value' => 'Fixture offer subject',
            'is_active' => true,
        ]);
        $creativeAsset = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'type' => 'creative',
            'value' => 'fixture/creative.png',
            'is_active' => true,
        ]);
        $htmlAsset = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'type' => 'html',
            'value' => '<html><body><img src="http://[domain]/creative.png"></body></html>',
            'is_active' => true,
        ]);
        $isp = $workspace->isps()->create([
            'name' => 'Fixture ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [(int) $sponsor->getKey()],
        ]);
        $list = $this->list($workspace, $owner, $isp->getKey(), $sponsor->getKey());
        $primaryContact = ContactListMembership::query()
            ->where('audience_list_id', $list->getKey())
            ->firstOrFail()
            ->contact;
        $otherOfferContact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(),
            'email' => 'other-offer@example.test',
            'email_lookup_hash' => hash_hmac('sha256', 'other-offer@example.test', $lookupKey),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        ContactListMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $otherOfferContact->getKey(),
            'status' => 'active',
            'joined_at' => now()->addSecond(),
        ]);
        $secondList = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Synthetic second campaign audience',
            'slug' => 'synthetic-campaign-audience-second',
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic-campaign-audience-second'),
            'isp_id' => $isp->getKey(),
            'country' => 'US',
            'allowed_users' => [],
            'allowed_sponsors' => [(int) $sponsor->getKey()],
        ]);
        $secondListMembership = ContactListMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $secondList->getKey(),
            'contact_id' => $otherOfferContact->getKey(),
            'status' => 'active',
            'joined_at' => now()->addSeconds(2),
        ]);
        $firstListMembership = ContactListMembership::query()
            ->where('audience_list_id', $list->getKey())
            ->where('contact_id', $otherOfferContact->getKey())
            ->firstOrFail();
        $mix = AudienceMixSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'isp_id' => $isp->getKey(),
            'name' => 'mix_snapshot_1',
            'criteria' => ['source_list_ids' => [(string) $list->getKey()]],
            'recipient_count' => 1,
            'status' => 'active',
        ]);
        AudienceMixSnapshotMember::query()->create([
            'workspace_id' => $workspace->getKey(),
            'snapshot_id' => $mix->getKey(),
            'contact_id' => $otherOfferContact->getKey(),
            'source_list_id' => $list->getKey(),
            'source_membership_id' => $firstListMembership->getKey(),
            'email_type' => 'fresh',
            'position' => 1,
        ]);
        $unrelatedOffer = $this->offer($workspace, $sponsor, ['name' => 'Unrelated offer']);
        foreach ([[$primaryContact, $offer], [$otherOfferContact, $unrelatedOffer]] as [$contact, $excludedOffer]) {
            SuppressionEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'contact_id' => $contact->getKey(),
                'event_type' => 'added',
                'reason' => 'unsubscribe',
                'scope_type' => 'offer',
                'scope_key' => (string) $excludedOffer->getKey(),
                'source' => 'operator_request',
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', (string) Str::uuid()),
                'actor_user_id' => $owner->getKey(),
                'effective_at' => now()->subSecond(),
            ]);
        }
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'send.example.test',
            'state' => 'verified',
            'verification_token' => 'fixture-domain-token',
            'created_by' => $owner->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);
        $server = $this->server($workspace, $owner, '203.0.113.50');
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.51',
            'type' => 'ipv4',
            'dns' => 'mta.send.example.test',
            'is_active' => true,
        ]);
        SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'redirect.send.example.test',
            'state' => 'verified',
            'verification_token' => 'fixture-redirect-token',
            'created_by' => $owner->getKey(),
            'status' => 'redirect',
            'is_our' => true,
            'is_active' => true,
        ]);

        $campaignPayload = [
            'idempotency_key' => (string) Str::uuid(),
            'name' => 'Synthetic source-mapped campaign',
            'kind' => 'marketing',
            'from_name' => 'Fixture Sender',
            'from_email' => 'sender@example.test',
            'subject' => 'Synthetic source-mapped subject',
            'return_path' => '[rnd]@bounce.example.test',
            'email_test_addresses' => "seed@example.test\nseed@example.test\nsecond@example.test\n",
            'domain' => 'mta.send.example.test',
            'header' => "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [from] <info@[domain]>\nSubject: [subject]\nDate: [date]",
            'body' => '<p>[body]</p>',
            'text_body' => 'Synthetic source-mapped body.',
            'send_setup' => [
                'sponsor_id' => $sponsor->getKey(),
                'offer_id' => $offer->getKey(),
                'negative_id' => $negative->getKey(),
                'from_asset_ids' => [$fromAsset->getKey()],
                'subject_asset_ids' => [$subjectAsset->getKey()],
                'creative_asset_id' => $creativeAsset->getKey(),
                'html_asset_id' => $htmlAsset->getKey(),
                'placeholder_combination' => '1',
                'placeholder_all_combination' => '0',
                'placeholder_inputs' => [
                    ['data_text' => "  First value  \n\nSecond value\n", 'rotate_every' => '3'],
                    ['data_text' => " \n", 'rotate_every' => '1'],
                ],
                'offer_country' => 'US',
                'isp_id' => $isp->getKey(),
                'country' => 'US',
                'audience_segments' => [$list->getKey().'_fresh', $secondList->getKey().'_fresh', $mix->getKey().'_mix'],
                'order_by' => 'random',
                'start_from' => 3,
                'route_option' => 'ip',
                'ip_type' => 'ipv4',
                'server_ids' => [$server->getKey()],
                'ip_ids' => [$ip->getKey()],
                'send_type' => 'delay',
                'total_send' => 20,
                'fraction_ip' => 1,
                'sleep_email' => 0,
                'sleep_ip' => 0,
                'seed_every' => 0,
                'bounce_limit' => 1000,
                'duplicate_data' => 1,
            ],
        ];
        $campaignPayload['email_test_addresses'] .= str_repeat(' ', 50_001);
        $campaignPayload['header'] .= "\n".str_repeat("X-Production-Scale: payload\n", 9_999).'X-Production-Scale: terminal';
        $campaignPayload['body'] = str_repeat('B', 2_000_001);
        $campaignPayload['send_setup']['placeholder_inputs'][0]['data_text'] .= str_repeat(' ', 50_001);
        $campaignPayload['send_setup']['total_send'] = 2_147_483_648;
        $response = $this->actingAs($owner)->post(route('console.campaigns.store', $workspace->getKey()), $campaignPayload);

        $campaign = Campaign::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
        $response->assertRedirect(route('console.mailer.campaigns'));
        $setup = $campaign->latestRevision->payload['send_setup'];
        $this->assertArrayNotHasKey('from_email', $campaign->latestRevision->payload);
        $this->assertArrayNotHasKey('text_body', $campaign->latestRevision->payload);
        $this->assertArrayNotHasKey('html_body', $campaign->latestRevision->payload);
        $this->assertSame('[rnd]@bounce.example.test', $campaign->latestRevision->payload['return_path']);
        $this->assertSame(['seed@example.test', 'seed@example.test', 'second@example.test'], $campaign->latestRevision->payload['email_tests']);
        $this->assertSame('mta.send.example.test', $setup['domain']);
        $this->assertSame($campaignPayload['header'], $campaign->latestRevision->payload['header']);
        $this->assertSame($campaignPayload['body'], $campaign->latestRevision->payload['body']);
        $this->assertSame($sponsor->getKey(), $setup['sponsor_id']);
        $this->assertSame($offer->getKey(), $setup['offer_id']);
        $this->assertSame($negative->getKey(), $setup['negative_id']);
        $this->assertSame([$fromAsset->getKey()], $setup['from_asset_ids']);
        $this->assertSame([$subjectAsset->getKey()], $setup['subject_asset_ids']);
        $this->assertSame($creativeAsset->getKey(), $setup['creative_asset_id']);
        $this->assertSame($htmlAsset->getKey(), $setup['html_asset_id']);
        $this->assertTrue($setup['placeholder_combination']);
        $this->assertFalse($setup['placeholder_all_combination']);
        $this->assertSame([
            ['data' => ['First value', 'Second value'], 'rotate_every' => 3],
        ], $setup['placeholders']);
        $this->assertSame($isp->getKey(), $setup['isp_id']);
        $this->assertSame([$list->getKey(), $secondList->getKey()], $setup['audience_list_ids']);
        $this->assertSame([$list->getKey().'_fresh', $secondList->getKey().'_fresh', $mix->getKey().'_mix'], $setup['audience_segments']);
        $this->assertSame('dms_email_type_segments', $setup['targeting_basis']);
        $snapshot = CampaignRecipientSnapshot::query()->where('campaign_id', $campaign->getKey())->sole();
        $this->assertSame(1, $snapshot->recipient_count);
        $this->assertSame(1, $snapshot->offer_suppressed_count);
        $this->assertSame(2, $snapshot->duplicate_count);
        $snapshotContactIds = DB::table('campaign_recipient_snapshot_items')
            ->where('snapshot_id', $snapshot->getKey())
            ->pluck('contact_id')
            ->all();
        $this->assertSame([$otherOfferContact->getKey()], $snapshotContactIds);
        $this->assertDatabaseCount('campaign_recipient_snapshot_items', 1);
        $snapshotItemId = (string) DB::table('campaign_recipient_snapshot_items')
            ->where('snapshot_id', $snapshot->getKey())
            ->value('id');
        $this->assertEqualsCanonicalizing(
            [$firstListMembership->getKey(), $secondListMembership->getKey()],
            DB::table('campaign_recipient_snapshot_item_sources')
                ->where('snapshot_item_id', $snapshotItemId)
                ->pluck('source_membership_id')
                ->all(),
        );
        $this->assertSame(2, DB::table('campaign_recipient_snapshot_item_sources')
            ->where('snapshot_item_id', $snapshotItemId)->count());
        $this->assertSame([$server->getKey()], $setup['server_ids']);
        $this->assertSame([(string) $ip->getKey()], $setup['ip_ids']);
        $this->assertSame('random', $setup['order_by']);
        $this->assertSame(3, $setup['start_from']);
        $this->assertSame(1, $snapshot->start_from);
        $this->assertSame($campaignPayload['send_setup']['total_send'], $setup['parameters']['total_send']);
        $this->assertSame(0, Campaign::query()->where('status', 'sending')->count());

        $editUrl = route('clean.console.campaigns.edit', $campaign->getKey());
        $missingIpPayload = $campaignPayload;
        unset($missingIpPayload['send_setup']['ip_ids']);
        $missingIpPayload['idempotency_key'] = (string) Str::uuid();
        $missingIpPayload['expected_revision_id'] = $campaign->latestRevision->getKey();
        $this->actingAs($owner)->from($editUrl)
            ->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), $missingIpPayload)
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors([
                'send_setup.ip_ids' => 'Choose at least one IP in the Selected IPs list before saving. Test Campaign does not require a separate IP selection; it uses the current Header and Email Test addresses with the selected server inventory.',
            ]);
        $this->assertSame(1, $campaign->revisions()->count());

        $unchangedPayload = $campaignPayload;
        $unchangedPayload['idempotency_key'] = (string) Str::uuid();
        $unchangedPayload['expected_revision_id'] = $campaign->latestRevision->getKey();
        $this->actingAs($owner)->from($editUrl)
            ->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), $unchangedPayload)
            ->assertRedirect($editUrl)
            ->assertSessionHasErrors(['campaign' => 'Nothing Changed.']);
        $this->assertSame(1, $campaign->revisions()->count());
        $this->assertSame(1, CampaignRecipientSnapshot::query()->where('campaign_id', $campaign->getKey())->count());

        $revisionPayload = $campaignPayload;
        $revisionPayload['idempotency_key'] = (string) Str::uuid();
        $revisionPayload['expected_revision_id'] = $campaign->latestRevision->getKey();
        $revisionPayload['domain'] = 'redirect.send.example.test';
        $revisionPayload['header'] .= "\nX-Revision: 2";
        $revisionPayload['send_setup']['placeholder_inputs'][0]['data_text'] = "Revised one\nRevised two";
        $revisionPayload['send_setup']['placeholder_inputs'][0]['rotate_every'] = '5';
        $revisedCreativeAsset = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'type' => 'creative',
            'value' => 'fixture/revised-creative.png',
            'is_active' => true,
        ]);
        $revisedHtmlAsset = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'type' => 'html',
            'value' => '<html><body>Revised [domain]</body></html>',
            'is_active' => true,
        ]);
        $revisionPayload['send_setup']['creative_asset_id'] = $revisedCreativeAsset->getKey();
        $revisionPayload['send_setup']['html_asset_id'] = $revisedHtmlAsset->getKey();
        $revisionPayload['send_setup']['total_send'] = 30;
        $this->actingAs($owner)->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), $revisionPayload)
            ->assertRedirect(route('console.mailer.campaigns'));

        $campaign->refresh()->load('latestRevision');
        $revisionSnapshot = CampaignRecipientSnapshot::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('revision_id', $campaign->latestRevision->getKey())
            ->sole();
        $this->assertSame('redirect.send.example.test', $campaign->latestRevision->payload['send_setup']['domain']);
        $this->assertSame($campaignPayload['header']."\nX-Revision: 2", $campaign->latestRevision->payload['header']);
        $this->assertSame([
            ['data' => ['Revised one', 'Revised two'], 'rotate_every' => 5],
        ], $campaign->latestRevision->payload['send_setup']['placeholders']);
        $this->assertSame($revisedCreativeAsset->getKey(), $campaign->latestRevision->payload['send_setup']['creative_asset_id']);
        $this->assertSame($revisedHtmlAsset->getKey(), $campaign->latestRevision->payload['send_setup']['html_asset_id']);
        $this->assertSame(30, $campaign->latestRevision->payload['send_setup']['parameters']['total_send']);
        $this->assertSame($snapshot->recipients_sha256, $revisionSnapshot->recipients_sha256);
        $this->assertSame(
            DB::table('campaign_recipient_snapshot_items')->where('snapshot_id', $snapshot->getKey())->pluck('contact_id')->all(),
            DB::table('campaign_recipient_snapshot_items')->where('snapshot_id', $revisionSnapshot->getKey())->pluck('contact_id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            DB::table('campaign_recipient_snapshot_item_sources as sources')
                ->join('campaign_recipient_snapshot_items as items', 'items.id', '=', 'sources.snapshot_item_id')
                ->where('items.snapshot_id', $snapshot->getKey())
                ->pluck('sources.source_membership_id')
                ->all(),
            DB::table('campaign_recipient_snapshot_item_sources as sources')
                ->join('campaign_recipient_snapshot_items as items', 'items.id', '=', 'sources.snapshot_item_id')
                ->where('items.snapshot_id', $revisionSnapshot->getKey())
                ->pluck('sources.source_membership_id')
                ->all(),
        );
        $stalePayload = $revisionPayload;
        $stalePayload['idempotency_key'] = (string) Str::uuid();
        $this->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), $stalePayload)
            ->assertStatus(409);
        $this->assertSame(2, $campaign->revisions()->count());

        $otherOffer = $this->offer($workspace, $sponsor, ['name' => 'Different revision offer']);
        $otherOfferFrom = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $otherOffer->getKey(),
            'type' => 'from',
            'value' => 'Different revision sender <other@example.test>',
            'is_active' => true,
        ]);
        $foreignRevisionPayload = $revisionPayload;
        $foreignRevisionPayload['idempotency_key'] = (string) Str::uuid();
        $foreignRevisionPayload['expected_revision_id'] = $campaign->latestRevision->getKey();
        $foreignRevisionPayload['send_setup']['from_asset_ids'] = [$otherOfferFrom->getKey()];
        $this->actingAs($owner)->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), $foreignRevisionPayload)
            ->assertSessionHasErrors('send_setup.from_asset_ids');
        $this->assertSame(2, $campaign->revisions()->count());

        $invalidRevisionPayload = $revisionPayload;
        $invalidRevisionPayload['idempotency_key'] = (string) Str::uuid();
        $invalidRevisionPayload['expected_revision_id'] = $campaign->latestRevision->getKey();
        $invalidRevisionPayload['domain'] = 'inactive-or-foreign.example.test';
        $this->actingAs($owner)->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), $invalidRevisionPayload)
            ->assertSessionHasErrors('domain');
        $this->assertSame(2, $campaign->revisions()->count());

        $foreignOffer = $this->offer($workspace, $sponsor, ['name' => 'A different eligible offer']);
        $foreignFromAsset = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $foreignOffer->getKey(),
            'type' => 'from',
            'value' => 'Different offer <other@example.test>',
            'is_active' => true,
        ]);
        $invalidPayload = $campaignPayload;
        $invalidPayload['idempotency_key'] = (string) Str::uuid();
        $invalidPayload['send_setup']['from_asset_ids'] = [$foreignFromAsset->getKey()];
        $this->actingAs($owner)->post(route('console.campaigns.store', $workspace->getKey()), $invalidPayload)
            ->assertSessionHasErrors('send_setup.from_asset_ids');
        $this->assertSame(1, Campaign::query()->where('workspace_id', $workspace->getKey())->count());

        $foreignCreativeAsset = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $foreignOffer->getKey(),
            'type' => 'creative',
            'value' => 'foreign/creative.png',
            'is_active' => true,
        ]);
        $invalidCreativePayload = $campaignPayload;
        $invalidCreativePayload['idempotency_key'] = (string) Str::uuid();
        $invalidCreativePayload['send_setup']['creative_asset_id'] = $foreignCreativeAsset->getKey();
        $this->actingAs($owner)->post(route('console.campaigns.store', $workspace->getKey()), $invalidCreativePayload)
            ->assertSessionHasErrors('send_setup.creative_asset_id');
        $this->assertSame(1, Campaign::query()->where('workspace_id', $workspace->getKey())->count());

        $otherUser = User::factory()->create();
        $workspace->users()->attach($otherUser->getKey(), ['role' => 'operator']);
        $otherUsersNegative = $workspace->campaignNegatives()->create([
            'created_by' => $otherUser->getKey(),
            'name' => 'Not owner visible',
            'path' => 'campaign-negatives/not-owner-visible.txt',
            'original_filename' => 'not-owner-visible.txt',
            'sha256' => hash('sha256', 'other user fixture'),
            'size_bytes' => 18,
        ]);
        $invalidNegativePayload = $campaignPayload;
        $invalidNegativePayload['idempotency_key'] = (string) Str::uuid();
        $invalidNegativePayload['send_setup']['negative_id'] = $otherUsersNegative->getKey();
        $this->actingAs($owner)->post(route('console.campaigns.store', $workspace->getKey()), $invalidNegativePayload)
            ->assertSessionHasErrors('send_setup.negative_id');
        $this->assertSame(1, Campaign::query()->where('workspace_id', $workspace->getKey())->count());

        $invalidDomainPayload = $campaignPayload;
        $invalidDomainPayload['idempotency_key'] = (string) Str::uuid();
        $invalidDomainPayload['domain'] = 'not-approved.example.test';
        $this->actingAs($owner)->post(route('console.campaigns.store', $workspace->getKey()), $invalidDomainPayload)
            ->assertSessionHasErrors('domain');
        $this->assertSame(1, Campaign::query()->where('workspace_id', $workspace->getKey())->count());

        $missingEmailTestsPayload = $campaignPayload;
        $missingEmailTestsPayload['idempotency_key'] = (string) Str::uuid();
        $missingEmailTestsPayload['email_test_addresses'] = '';
        $this->actingAs($owner)->post(route('console.campaigns.store', $workspace->getKey()), $missingEmailTestsPayload)
            ->assertSessionHasErrors('email_test_addresses');
        $this->assertSame(1, Campaign::query()->where('workspace_id', $workspace->getKey())->count());

        $invalidCombinationPayload = $campaignPayload;
        $invalidCombinationPayload['idempotency_key'] = (string) Str::uuid();
        $invalidCombinationPayload['send_setup']['placeholder_combination'] = '1';
        $invalidCombinationPayload['send_setup']['placeholder_all_combination'] = '1';
        $this->actingAs($owner)->post(route('console.campaigns.store', $workspace->getKey()), $invalidCombinationPayload)
            ->assertSessionHasErrors('send_setup.placeholder_all_combination');
        $this->assertSame(1, Campaign::query()->where('workspace_id', $workspace->getKey())->count());

        $this->get(route('clean.console.campaigns.show', $campaign->getKey()))
            ->assertOk()
            ->assertSee('Saved DMS campaign selections')
            ->assertSee($sponsor->name)
            ->assertSee($offer->name)
            ->assertSee('Synthetic DMS negative')
            ->assertSee($isp->name)
            ->assertSee($list->name)
            ->assertSee($server->ip_address)
            ->assertDontSee('Send one test email')
            ->assertSee('[rnd]@bounce.example.test')
            ->assertSee('seed@example.test')
            ->assertSee('second@example.test')
            ->assertSee('has not implemented or verified DMS’s native sender')
            ->assertSee('id="pmta-test-server"', false)
            ->assertSee(':1212')
            ->assertDontSee('Save the workspace PMTA listener port in installation settings before testing.');

        $pushedVmta = PmtaVirtualMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'created_by' => $owner->getKey(),
            'ip' => $ip->ip,
            'domain' => 'pushed-vmta.send.example.test',
            'name' => 'v_1',
            'duplicate_index' => 1,
            'route' => false,
            'is_active' => true,
        ]);
        $vmtaChoice = ManagedServerMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'ip' => $ip->ip,
            'dns' => $ip->dns,
            'ip_type' => 'ipv4',
            'mta_type' => 'vmta',
            'name' => 'v_1',
            'pmta_virtual_mta_id' => $pushedVmta->getKey(),
            'is_active' => true,
            'user_id' => $owner->getKey(),
        ]);
        $vmtaCampaignPayload = $campaignPayload;
        $vmtaCampaignPayload['idempotency_key'] = (string) Str::uuid();
        $vmtaCampaignPayload['name'] = 'VMTA domain normalization fixture';
        $vmtaCampaignPayload['domain'] = 'stale-domain-from-ip.example.test';
        $vmtaCampaignPayload['send_setup']['route_option'] = 'vmta';
        $vmtaCampaignPayload['send_setup']['ip_ids'] = [$vmtaChoice->getKey()];

        $this->actingAs($owner)->post(route('console.campaigns.store', $workspace->getKey()), $vmtaCampaignPayload)
            ->assertRedirect(route('console.mailer.campaigns'));
        $vmtaCampaign = Campaign::query()->where('name', 'VMTA domain normalization fixture')->firstOrFail();
        $this->assertSame('pushed-vmta.send.example.test', $vmtaCampaign->latestRevision->payload['send_setup']['domain']);

        $vmtaRevisionPayload = $vmtaCampaignPayload;
        $vmtaRevisionPayload['idempotency_key'] = (string) Str::uuid();
        $vmtaRevisionPayload['expected_revision_id'] = $vmtaCampaign->latestRevision->getKey();
        $vmtaRevisionPayload['domain'] = 'stale-domain-from-edit.example.test';
        $vmtaRevisionPayload['header'] .= "\nX-VMTA-domain-edit: 1";
        $this->actingAs($owner)->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $vmtaCampaign->getKey()]), $vmtaRevisionPayload)
            ->assertRedirect(route('console.mailer.campaigns'));
        $this->assertSame('pushed-vmta.send.example.test', $vmtaCampaign->fresh()->latestRevision->payload['send_setup']['domain']);

        $offer->forceFill(['status' => 'paused', 'is_active' => false])->save();
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2588]);
        config(['infrastructure.pmta_port' => 2525]);
        $editResponse = $this->get(route('clean.console.campaigns.edit', $campaign->getKey()));
        $editResponse
            ->assertOk()
            ->assertSee('name="email_test_addresses"', false)
            ->assertSee('name="send_setup[server_ids][]"', false)
            ->assertSee('data-generate-url=', false)
            ->assertSee('campaign-header-shuffle')
            ->assertSee('campaign-header-encode')
            ->assertSee('campaign-shortlink-open')
            ->assertSee('campaign-servers-reload')
            ->assertSee('campaign-actions-trigger')
            ->assertSee('<i class="fas fa-bars" aria-hidden="true"></i>', false)
            ->assertSee('fas fa-edit', false)
            ->assertSee('fas fa-vial', false)
            ->assertSee('fas fa-grip-horizontal', false)
            ->assertSee('fas fa-server', false)
            ->assertSee('id="campaign-actions-panel"', false)
            ->assertSee('campaign-context-menu')
            ->assertSee('data-campaign-menu-action="edit"', false)
            ->assertSee('data-campaign-menu-action="test"', false)
            ->assertSee('data-campaign-menu-action="pmta-admin"', false)
            ->assertSee('data-campaign-menu-action="pmta-panel"', false)
            ->assertSee('campaign-server-search-dialog')
            ->assertSee('Server names or IP addresses, one per line')
            ->assertSee('bootstrap_select/bootstrap-select.js', false)
            ->assertSee('send-ips-available')
            ->assertSee('send-ips-selected')
            ->assertSee('fixture/revised-creative.png')
            ->assertSee('Revised [domain]')
            ->assertDontSee('Loading selected creative')
            ->assertDontSee('name="test_recipient"', false)
            ->assertSee('name="expected_revision_id"', false);
        $editHtml = $editResponse->getContent();
        $this->assertSame(2, preg_match_all('/<button\\b(?=[^>]*type="button")(?=[^>]*role="menuitem")(?=[^>]*data-campaign-menu-action="test")(?=[^>]*data-test-url="[^"]*\\/campaigns\\/manual-test")[^>]*>/', $editHtml));
        $this->assertSame(0, preg_match_all('/<button\\b(?=[^>]*type="submit")(?=[^>]*data-campaign-menu-action="test")[^>]*>/', $editHtml));

        $pausedOfferRevision = $revisionPayload;
        $pausedOfferRevision['idempotency_key'] = (string) Str::uuid();
        $pausedOfferRevision['expected_revision_id'] = $campaign->latestRevision->getKey();
        $pausedOfferRevision['header'] .= "\nX-Edit-After-Pause: 1";
        $this->actingAs($owner)->post(route('console.campaigns.revisions.store', [$workspace->getKey(), $campaign->getKey()]), $pausedOfferRevision)
            ->assertRedirect(route('console.mailer.campaigns'));
        $campaign->refresh()->load('latestRevision');
        $pausedOfferSnapshot = CampaignRecipientSnapshot::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('revision_id', $campaign->latestRevision->getKey())
            ->sole();
        $this->assertStringContainsString('X-Edit-After-Pause: 1', $campaign->latestRevision->payload['header']);
        $this->assertSame($snapshot->recipients_sha256, $pausedOfferSnapshot->recipients_sha256);

        $resolvedStream = (new PmtaCampaignTestTransport)->forServer($server, 2525)->getStream();
        $this->assertInstanceOf(SocketStream::class, $resolvedStream);
        $this->assertSame($server->ip_address, $resolvedStream->getHost());
        $this->assertSame(2525, $resolvedStream->getPort());

        $transport = Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->once();
        $transport->shouldReceive('send')->once();
        $transport->shouldReceive('stop')->once();
        $factory = Mockery::mock(PmtaCampaignTestTransport::class);
        $factory->shouldReceive('forServer')->once()->withArgs(fn (ManagedServer $selected, int $port): bool => $selected->is($server) && $port === 2525)->andReturn($transport);
        $this->app->instance(PmtaCampaignTestTransport::class, $factory);
        $pmtaPayload = [
            'revision_id' => $campaign->latestRevision->getKey(),
            'server_id' => $server->getKey(),
            'recipient' => 'seed@example.test',
            'from_email' => 'sender@example.test',
            'subject' => 'Synthetic PMTA test',
            'body' => '<p>Synthetic test body</p>',
            'idempotency_key' => (string) Str::uuid(),
            'confirm_authorized_recipient' => '1',
        ];
        $this->post(route('console.campaigns.pmta-test', [$workspace->getKey(), $campaign->getKey()]), $pmtaPayload)
            ->assertSessionHas('status', 'The selected server’s PMTA SMTP listener accepted this test message. Mailbox delivery is unconfirmed.');
        $this->assertDatabaseHas('campaign_test_mail_attempts', ['mailer' => 'pmta_smtp', 'status' => 'accepted_by_transport']);
        $this->post(route('console.campaigns.pmta-test', [$workspace->getKey(), $campaign->getKey()]), $pmtaPayload)
            ->assertSessionHas('status', 'This PMTA test submission was already recorded; no duplicate was sent.');
        $this->assertSame(1, CampaignTestMailAttempt::query()->count());
        $this->post(route('console.campaigns.pmta-test', [$workspace->getKey(), $campaign->getKey()]), [
            ...$pmtaPayload, 'subject' => 'Changed with reused key',
        ])->assertStatus(409);
        $this->post(route('console.campaigns.pmta-test', [$workspace->getKey(), $campaign->getKey()]), [
            ...$pmtaPayload, 'recipient' => 'not-saved@example.test', 'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(422);
        $this->assertSame(1, CampaignTestMailAttempt::query()->count());
        $this->get(route('console.mailer.campaigns'))
            ->assertOk()
            ->assertSee('Synthetic source-mapped campaign')
            ->assertSee($isp->name)
            ->assertSee($offer->name)
            ->assertSee($server->name)
            ->assertSee('href="//203.0.113.50:2588/"', false)
            ->assertSee('Open PMTA Web Monitor on SYNTHETIC SEND SERVER')
            ->assertSee('TOTAL SEND')
            ->assertSee('20')
            ->assertSee('DELAY')
            ->assertSee('DRAFT')
            ->assertSee('SEND UNAVAILABLE')
            ->assertSee('The native DMS campaign sender is not implemented in AlphaLink.')
            ->assertDontSee('Native DMS sending, scheduling, and delivery history are not available in AlphaLink yet.');
    }

    public function test_campaign_snapshot_pages_large_selected_audience_and_preserves_sorted_order(): void
    {
        $lookupKey = str_repeat('synthetic-snapshot-contact-key-', 2);
        config(['alphalink.contact_lookup_key' => $lookupKey]);

        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $sponsor = $this->sponsor($workspace);
        $offer = $this->offer($workspace, $sponsor);
        $isp = $workspace->isps()->create([
            'name' => 'Snapshot paging ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [(int) $sponsor->getKey()],
        ]);
        $list = $this->list($workspace, $owner, $isp->getKey(), $sponsor->getKey());
        $secondList = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Synthetic campaign audience duplicate source',
            'slug' => 'synthetic-campaign-audience-duplicate-source',
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic-campaign-audience-duplicate-source'),
            'isp_id' => $isp->getKey(),
            'country' => 'US',
            'allowed_users' => [],
            'allowed_sponsors' => [(int) $sponsor->getKey()],
        ]);
        $secondListEmail = 'synthetic-'.$secondList->getKey().'@example.test';
        $secondListContact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(),
            'email' => $secondListEmail,
            'email_lookup_hash' => hash_hmac('sha256', $secondListEmail, $lookupKey),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        ContactListMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $secondList->getKey(),
            'contact_id' => $secondListContact->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Paged recipient snapshot',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => ['kind' => 'marketing'],
            'content_sha256' => hash('sha256', 'paged-recipient-snapshot'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'paged-recipient-snapshot-request'),
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);

        $expectedEmails = [];
        $now = now();
        $contactRows = [];
        $membershipRows = [];
        $expectedEmails[] = 'synthetic-'.$list->getKey().'@example.test';
        $expectedEmails[] = $secondListEmail;
        for ($index = 1; $index <= 1000; $index++) {
            $email = sprintf('page-%04d@example.test', $index);
            $contactId = (string) Str::uuid();
            $expectedEmails[] = $email;
            $contactRows[] = [
                'id' => $contactId,
                'workspace_id' => $workspace->getKey(),
                'email' => Crypt::encryptString($email),
                'email_lookup_hash' => hash_hmac('sha256', $email, $lookupKey),
                'lookup_key_version' => 1,
                'status' => 'active',
                'idempotency_key' => (string) Str::uuid(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $membershipRows[] = [
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->getKey(),
                'audience_list_id' => $list->getKey(),
                'contact_id' => $contactId,
                'email_type' => 'fresh',
                'status' => 'active',
                'joined_at' => $now->copy()->addSeconds($index),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $membershipRows[] = [
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->getKey(),
                'audience_list_id' => $secondList->getKey(),
                'contact_id' => $contactId,
                'email_type' => 'fresh',
                'status' => 'active',
                'joined_at' => $now->copy()->addSeconds($index),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($membershipRows) === 80) {
                DB::table('contacts')->insert($contactRows);
                DB::table('contact_list_memberships')->insert($membershipRows);
                $contactRows = [];
                $membershipRows = [];
            }
        }
        if ($contactRows !== []) {
            DB::table('contacts')->insert($contactRows);
            DB::table('contact_list_memberships')->insert($membershipRows);
        }

        $snapshot = app(DmsCampaignRecipientSnapshotBuilder::class)->build(
            $workspace,
            $campaign,
            (string) $revision->getKey(),
            [
                'offer_id' => $offer->getKey(),
                'isp_id' => $isp->getKey(),
                'audience_segments' => [$list->getKey().'_fresh', $secondList->getKey().'_fresh'],
                'order_by' => 'asc',
                'start_from' => 7,
            ],
        );

        $items = DB::table('campaign_recipient_snapshot_items')
            ->where('snapshot_id', $snapshot->getKey())
            ->orderBy('position')
            ->get(['id', 'contact_id', 'position']);
        $this->assertSame(1002, $snapshot->recipient_count);
        $this->assertSame(1000, $snapshot->duplicate_count);
        $this->assertSame(7, $snapshot->start_from);
        $this->assertCount(1002, $items);

        $contacts = Contact::query()
            ->whereIn('id', $items->pluck('contact_id'))
            ->get()
            ->keyBy(fn (Contact $contact): string => (string) $contact->getKey());
        $actualEmails = $items
            ->map(fn ($item): string => (string) $contacts->get((string) $item->contact_id)->email)
            ->all();
        sort($expectedEmails, SORT_STRING);
        $this->assertSame($expectedEmails, $actualEmails);
        $this->assertSame(2002, DB::table('campaign_recipient_snapshot_item_sources')
            ->whereIn('snapshot_item_id', $items->pluck('id'))
            ->count());

        $this->assertSame(1002, DB::table('campaign_recipient_snapshot_item_addresses as address')
            ->join('campaign_recipient_snapshot_items as item', 'item.id', '=', 'address.snapshot_item_id')
            ->where('item.snapshot_id', $snapshot->getKey())
            ->count());
        $firstItem = $items->first();
        $originalFirstEmail = (string) $contacts->get((string) $firstItem->contact_id)->email;
        $encryptedFirstEmail = DB::table('campaign_recipient_snapshot_item_addresses')
            ->where('snapshot_item_id', $firstItem->id)
            ->value('email');
        $this->assertNotSame($originalFirstEmail, $encryptedFirstEmail);
        $storedAddress = CampaignRecipientSnapshotItemAddress::query()->findOrFail($firstItem->id);
        $this->assertSame($originalFirstEmail, $storedAddress->email);
        $this->assertArrayNotHasKey('email', $storedAddress->toArray());

        $firstContact = $contacts->get((string) $firstItem->contact_id);
        $firstContact->forceFill(['email' => 'changed-after-snapshot@example.test'])->save();
        $this->assertSame($originalFirstEmail, $storedAddress->fresh()->email);

        $copiedRevision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 2,
            'payload' => ['kind' => 'marketing'],
            'content_sha256' => hash('sha256', 'snapshot address copy'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'snapshot address copy request'),
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);
        $copiedSnapshot = app(DmsCampaignRecipientSnapshotBuilder::class)->copyToRevision(
            $snapshot,
            (string) $copiedRevision->getKey(),
        );
        $copiedItemId = DB::table('campaign_recipient_snapshot_items')
            ->where('snapshot_id', $copiedSnapshot->getKey())
            ->where('position', $firstItem->position)
            ->value('id');
        $this->assertSame(
            $originalFirstEmail,
            CampaignRecipientSnapshotItemAddress::query()->findOrFail($copiedItemId)->email,
        );

        $tamperedContact = Contact::query()->findOrFail($items->get(1)->contact_id);
        $tamperedContact->forceFill(['email_lookup_hash' => str_repeat('0', 64)])->save();
        try {
            app(DmsCampaignRecipientSnapshotBuilder::class)->build(
                $workspace,
                $campaign,
                (string) $copiedRevision->getKey(),
                [
                    'offer_id' => $offer->getKey(),
                    'isp_id' => $isp->getKey(),
                    'audience_segments' => [$list->getKey().'_fresh', $secondList->getKey().'_fresh'],
                    'order_by' => 'asc',
                    'start_from' => 7,
                ],
            );
            $this->fail('A changed contact identity must not be captured as an immutable campaign recipient.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('send_setup.audience_segments', $exception->errors());
        }
    }

    public function test_manual_campaign_test_allows_unlimited_retries_and_logs_created_campaign_tests(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.80');
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Repeated campaign test fixture',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => [
                'email_tests' => ['synthetic-recipient@example.test'],
                'header' => "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [from] <info@[domain]>\nSubject: [subject]\nDate: [date]",
                'body' => '<p>Current unsaved body</p>',
                'send_setup' => [
                    'server_ids' => [$server->getKey()],
                    'route_option' => 'ip',
                    'ip_type' => 'ipv4',
                    'send_type' => 'delay',
                ],
            ],
            'content_sha256' => hash('sha256', 'repeated campaign test fixture'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'repeated campaign test fixture request'),
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(), 'name' => 'mail.example.test',
            'state' => 'verified', 'verification_token' => 'synthetic-token',
            'created_by' => $owner->getKey(), 'status' => 'free', 'is_active' => true,
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(), 'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(), 'ip' => '203.0.113.81',
            'type' => 'ipv4', 'dns' => 'mail.example.test', 'is_active' => true,
        ]);
        config(['infrastructure.pmta_port' => 2525]);
        $transport = Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->times(31);
        $transport->shouldReceive('send')->times(31)->withArgs(fn ($message, $envelope): bool => str_contains($message->toString(), '<info@mail.example.test>')
            && str_contains($message->toString(), 'To: synthetic-recipient@example.test')
            && str_contains($message->toString(), '<p>Current unsaved body</p>')
            && $envelope->getSender()->getAddress() === 'info@mail.example.test'
            && $envelope->getRecipients()[0]->getAddress() === 'synthetic-recipient@example.test');
        $transport->shouldReceive('stop')->times(31);
        $factory = Mockery::mock(PmtaCampaignTestTransport::class);
        $factory->shouldReceive('forServer')->times(31)
            ->withArgs(fn (ManagedServer $selected, int $port): bool => $selected->is($server) && $port === 2525)
            ->andReturn($transport);
        $this->app->instance(PmtaCampaignTestTransport::class, $factory);

        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'test_campaign_id' => $campaign->getKey(),
            'expected_revision_id' => $revision->getKey(),
            'email_test_addresses' => 'synthetic-recipient@example.test',
            'header' => "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nfrom: [from] <info@[domain]>\nSubject: [subject]\nDate: [date]",
            'body' => '<p>Current unsaved body</p>',
            'send_setup' => ['server_ids' => [$server->getKey()], 'route_option' => 'ip', 'ip_type' => 'ipv4', 'send_type' => 'delay'],
        ];
        $response = $this->actingAs($owner)->postJson(route('console.campaigns.manual-test', $workspace->getKey()), $payload);
        $response->assertOk()
            ->assertJsonPath('outcome', 'accepted_by_transport')
            ->assertJsonPath('data', 'Selected PMTA servers accepted the current-form Email Test messages. Mailbox delivery is unconfirmed.')
            ->assertJsonStructure(['attempt_id', 'next_idempotency_key']);
        $this->assertFalse($response->isRedirect(), 'The asynchronous campaign test request must return in place without a page redirect.');
        $this->assertDatabaseHas('campaign_manual_test_attempts', [
            'status' => 'accepted_by_transport',
            'campaign_id' => $campaign->getKey(),
        ]);
        $storedSnapshot = (string) DB::table('campaign_manual_test_attempts')->where('campaign_id', $campaign->getKey())->value('test_snapshot');
        $this->assertNotSame('', $storedSnapshot);
        $this->assertStringNotContainsString('synthetic-recipient@example.test', $storedSnapshot);
        $nextKey = $response->json('next_idempotency_key');
        $this->assertNotSame($payload['idempotency_key'], $nextKey);
        $this->postJson(route('console.campaigns.manual-test', $workspace->getKey()), $payload)
            ->assertOk()
            ->assertJsonPath('data', 'PMTA already accepted this test submission. No duplicate was sent; mailbox delivery is unconfirmed.')
            ->assertJsonPath('attempt_id', $response->json('attempt_id'));
        $this->assertSame(1, DB::table('campaign_manual_test_attempts')->count());
        $this->post(route('console.campaigns.manual-test', $workspace->getKey()), [...$payload, 'body' => '<p>Changed message</p>'])
            ->assertStatus(409);
        $this->post(route('console.campaigns.manual-test', $workspace->getKey()), [...$payload, 'idempotency_key' => (string) Str::uuid(), 'email_test_addresses' => 'not-an-email'])
            ->assertSessionHasErrors('email_test_addresses');
        $this->assertSame(1, DB::table('campaign_manual_test_attempts')->count());

        for ($attempt = 2; $attempt <= 30; $attempt++) {
            $this->post(route('console.campaigns.manual-test', $workspace->getKey()), [
                ...$payload,
                'idempotency_key' => (string) Str::uuid(),
            ])->assertSessionHas('status', 'Selected PMTA servers accepted the current-form Email Test messages. Mailbox delivery is unconfirmed.');
        }

        DB::table('campaign_manual_test_attempts')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'actor_user_id' => $owner->getKey(),
            'managed_server_id' => $server->getKey(),
            'recipient_sha256' => hash('sha256', json_encode(['synthetic-recipient@example.test'], JSON_THROW_ON_ERROR)),
            'idempotency_key_hash' => hash('sha256', (string) Str::uuid()),
            'request_fingerprint' => hash('sha256', 'synthetic uncertain attempt'),
            'status' => 'outcome_unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->post(route('console.campaigns.manual-test', $workspace->getKey()), [
            ...$payload,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHas('status', 'Selected PMTA servers accepted the current-form Email Test messages. Mailbox delivery is unconfirmed.');

        $this->assertSame(32, DB::table('campaign_manual_test_attempts')->where('campaign_id', $campaign->getKey())->count());
        $this->get(route('clean.console.campaigns.show', $campaign->getKey()))
            ->assertOk()
            ->assertSee('id="campaign-test-logs"', false)
            ->assertSee('Campaign test logs')
            ->assertSee('Repeated campaign test fixture')
            ->assertSee('Showing 25 of 32')
            ->assertSee('SYNTHETIC SEND SERVER')
            ->assertSee('PMTA accepted; mailbox delivery unconfirmed')
            ->assertSee('View test configuration')
            ->assertSee('synthetic-recipient@example.test')
            ->assertSee('Header submitted')
            ->assertSee('Current unsaved body');
        $this->get(route('clean.console.campaigns.show', $campaign->getKey()).'?test_logs_page=2')
            ->assertOk()
            ->assertSee('Showing 7 of 32')
            ->assertSee('PMTA accepted; mailbox delivery unconfirmed');
        $this->get(route('console.mailer.campaigns'))
            ->assertOk()
            ->assertSee(route('clean.console.campaigns.show', $campaign->getKey()).'#campaign-test-logs', false);
    }

    public function test_manual_campaign_test_reports_relay_denial_without_requiring_domain_dns_proof(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.82');
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'pending-mail.example.test',
            'state' => 'pending',
            'verification_token' => 'synthetic-pending-token',
            'created_by' => $owner->getKey(),
            'status' => 'free',
            'is_active' => true,
        ]);
        ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.83',
            'type' => 'ipv4',
            'dns' => 'pending-mail.example.test',
            'is_active' => true,
        ]);
        $workspace->infrastructureSettings()->create(['pmta_port' => 2525]);

        $rejectedTransport = Mockery::mock(SmtpTransport::class);
        $rejectedTransport->shouldReceive('start')->once();
        $rejectedTransport->shouldReceive('send')->once()->andThrow(
            new UnexpectedResponseException(
                'Expected response code "250" but got "550", with message "550 relaying denied".',
                550
            )
        );
        $rejectedTransport->shouldReceive('stop')->once();

        $acceptedTransport = Mockery::mock(SmtpTransport::class);
        $acceptedTransport->shouldReceive('start')->once();
        $acceptedTransport->shouldReceive('send')->once();
        $acceptedTransport->shouldReceive('stop')->once();

        $factory = Mockery::mock(PmtaCampaignTestTransport::class);
        $factory->shouldReceive('forServer')->twice()
            ->withArgs(fn (ManagedServer $selected, int $port): bool => $selected->is($server) && $port === 2525)
            ->andReturn($rejectedTransport, $acceptedTransport);
        $this->app->instance(PmtaCampaignTestTransport::class, $factory);

        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'email_test_addresses' => 'synthetic-recipient@example.test',
            'header' => "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nFrom: info@[domain]\nSubject: Test\nDate: [date]",
            'body' => '<p>Pending-domain test</p>',
            'send_setup' => ['server_ids' => [$server->getKey()], 'route_option' => 'ip', 'ip_type' => 'ipv4', 'send_type' => 'delay'],
        ];

        $rejection = $this->actingAs($owner)->postJson(route('console.campaigns.manual-test', $workspace->getKey()), $payload);
        $rejection->assertUnprocessable()
            ->assertJsonPath('data', "PMTA denied relay access for AlphaLink's sending host; no test message was accepted. Save AlphaLink's stable public IPv4 egress in App Settings → PMTA RELAY SOURCE IPS and ensure that source is authorized on this server. Campaign Test does not require sender-domain DNS verification.")
            ->assertJsonPath('outcome', 'rejected_by_transport');
        $retryKey = $rejection->json('next_idempotency_key');
        $this->assertNotSame($payload['idempotency_key'], $retryKey);

        $this->assertDatabaseHas('campaign_manual_test_attempts', [
            'status' => 'rejected_by_transport',
            'managed_server_id' => $server->getKey(),
            'exception_type' => 'UnexpectedResponseException',
        ]);
        $failure = DB::table('audit_events')->where('action', 'campaign.manual_test_failed')->first();
        $this->assertNotNull($failure);
        $this->assertSame('relay_denied', json_decode($failure->properties, true, flags: JSON_THROW_ON_ERROR)['failure_reason']);
        $this->assertSame(550, json_decode($failure->properties, true, flags: JSON_THROW_ON_ERROR)['smtp_response_code']);

        $this->postJson(route('console.campaigns.manual-test', $workspace->getKey()), [
            ...$payload,
            'idempotency_key' => $retryKey,
        ])->assertOk()
            ->assertJsonPath('outcome', 'accepted_by_transport');

        $this->assertSame(1, DB::table('campaign_manual_test_attempts')->where('status', 'rejected_by_transport')->count());
        $this->assertSame(1, DB::table('campaign_manual_test_attempts')->where('status', 'accepted_by_transport')->count());
    }

    public function test_manual_campaign_test_routes_through_the_selected_or_default_pushed_vmta(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.100');
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'vmta-campaign-test.example.test',
            'state' => 'verified',
            'verification_token' => 'synthetic-vmta-campaign-test-token',
            'created_by' => $owner->getKey(),
            'status' => 'free',
            'is_active' => true,
        ]);
        $firstIp = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.101',
            'type' => 'ipv4',
            'dns' => 'ip-first.vmta-test.example.test',
            'is_active' => true,
        ]);
        $selectedIp = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.102',
            'type' => 'ipv4',
            'dns' => 'ip-selected.vmta-test.example.test',
            'is_active' => true,
        ]);
        $firstPushedVmta = PmtaVirtualMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $firstIp->getKey(),
            'created_by' => $owner->getKey(),
            'ip' => $firstIp->ip,
            'domain' => 'vmta-first.example.test',
            'name' => 'v_1',
            'duplicate_index' => 1,
            'route' => false,
            'is_active' => true,
        ]);
        $selectedPushedVmta = PmtaVirtualMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $selectedIp->getKey(),
            'created_by' => $owner->getKey(),
            'ip' => $selectedIp->ip,
            'domain' => 'vmta-selected.example.test',
            'name' => 'v_2',
            'duplicate_index' => 2,
            'route' => false,
            'is_active' => true,
        ]);
        $firstVmta = ManagedServerMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $firstIp->getKey(),
            'ip' => $firstIp->ip,
            'dns' => $firstIp->dns,
            'ip_type' => 'ipv4',
            'mta_type' => 'vmta',
            'name' => 'v_1',
            'pmta_virtual_mta_id' => $firstPushedVmta->getKey(),
            'is_active' => true,
            'user_id' => $owner->getKey(),
        ]);
        $selectedVmta = ManagedServerMta::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'managed_server_ip_id' => $selectedIp->getKey(),
            'ip' => $selectedIp->ip,
            'dns' => $selectedIp->dns,
            'ip_type' => 'ipv4',
            'mta_type' => 'vmta',
            'name' => 'v_2',
            'pmta_virtual_mta_id' => $selectedPushedVmta->getKey(),
            'is_active' => true,
            'user_id' => $owner->getKey(),
        ]);
        $workspace->infrastructureSettings()->create(['pmta_port' => 2525]);

        $sentMessages = [];
        $transport = Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->twice();
        $transport->shouldReceive('send')->twice()->withArgs(function ($message, $envelope) use (&$sentMessages): bool {
            $sentMessages[] = [
                'message' => $message->toString(),
                'recipient' => $envelope->getRecipients()[0]->getAddress(),
            ];

            return true;
        });
        $transport->shouldReceive('stop')->twice();
        $factory = Mockery::mock(PmtaCampaignTestTransport::class);
        $factory->shouldReceive('forServer')->twice()
            ->withArgs(fn (ManagedServer $selected, int $port): bool => $selected->is($server) && $port === 2525)
            ->andReturn($transport);
        $this->app->instance(PmtaCampaignTestTransport::class, $factory);

        $basePayload = [
            'email_test_addresses' => 'synthetic-vmta-recipient@example.test',
            'header' => "MIME-Version: 1.0\nTo: [to]\nFrom: sender@[domain]\nSubject: VMTA test\nX-virtual-MTA: user-selected-wrong-vmta\n\tcontinued-value\nDate: [date]",
            'body' => 'Selected route IP=[ip]; route domain=[domain]; literal URL=https://campaign-domain.example.test/path',
            'domain' => 'campaign-domain.example.test',
            'send_setup' => [
                'server_ids' => [(string) $server->getKey()],
                'route_option' => 'vmta',
                'ip_type' => 'ipv4',
                'send_type' => 'delay',
            ],
        ];

        $this->actingAs($owner)->postJson(route('console.campaigns.manual-test', $workspace->getKey()), [
            ...$basePayload,
            'idempotency_key' => (string) Str::uuid(),
            'send_setup' => [...$basePayload['send_setup'], 'ip_ids' => [(int) $selectedVmta->getKey()]],
        ])->assertOk()->assertJsonPath('outcome', 'accepted_by_transport');

        $this->postJson(route('console.campaigns.manual-test', $workspace->getKey()), [
            ...$basePayload,
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('outcome', 'accepted_by_transport');

        $this->actingAs($owner)->getJson(route('console.campaigns.send-options', [
            $workspace->getKey(),
            'server_ids' => [$server->getKey()],
            'route' => 'vmta',
            'ip_type' => 'ipv4',
        ]))->assertOk()
            ->assertJsonPath('data.ips.0.vmta_domain', 'vmta-first.example.test')
            ->assertJsonPath('data.ips.1.vmta_domain', 'vmta-selected.example.test')
            ->assertJsonFragment(['value' => 'vmta-first.example.test', 'name' => 'v_1', 'dns' => 'vmta-first.example.test'])
            ->assertJsonFragment(['value' => 'vmta-selected.example.test', 'name' => 'v_2', 'dns' => 'vmta-selected.example.test']);

        $this->assertCount(2, $sentMessages);
        $this->assertSame('synthetic-vmta-recipient@example.test', $sentMessages[0]['recipient']);
        $this->assertSame('synthetic-vmta-recipient@example.test', $sentMessages[1]['recipient']);
        $this->assertStringContainsString('Selected route IP=203.0.113.102', $sentMessages[0]['message']);
        $this->assertStringContainsString('Selected route IP=203.0.113.101', $sentMessages[1]['message']);
        $this->assertStringContainsString('sender@vmta-selected.example.test', $sentMessages[0]['message']);
        $this->assertStringContainsString('sender@vmta-first.example.test', $sentMessages[1]['message']);
        $this->assertStringContainsString('route domain=vmta-selected.example.test', $sentMessages[0]['message']);
        $this->assertStringContainsString('route domain=vmta-first.example.test', $sentMessages[1]['message']);
        $this->assertStringContainsString('https://vmta-selected.example.test/path', $sentMessages[0]['message']);
        $this->assertStringContainsString('https://vmta-first.example.test/path', $sentMessages[1]['message']);
        $expectedSelectedPmtaName = 'vmta-'.$selectedIp->ip.'-'.$selectedPushedVmta->getKey().'-v_2';
        $expectedFirstPmtaName = 'vmta-'.$firstIp->ip.'-'.$firstPushedVmta->getKey().'-v_1';
        $this->assertStringContainsString('X-virtual-MTA: '.$expectedSelectedPmtaName, $sentMessages[0]['message']);
        $this->assertStringContainsString('X-virtual-MTA: '.$expectedFirstPmtaName, $sentMessages[1]['message']);
        foreach ($sentMessages as $sent) {
            $this->assertSame(1, preg_match_all('/^X-virtual-MTA:/mi', $sent['message']));
            $this->assertStringNotContainsString('user-selected-wrong-vmta', $sent['message']);
            $this->assertStringNotContainsString('continued-value', $sent['message']);
        }

        $snapshots = DB::table('campaign_manual_test_attempts')->orderBy('created_at')->pluck('test_snapshot');
        $this->assertSame($expectedSelectedPmtaName, json_decode(Crypt::decryptString((string) $snapshots[0]), true, flags: JSON_THROW_ON_ERROR)['virtual_mtas'][0]['pmta_name']);
        $this->assertSame($expectedFirstPmtaName, json_decode(Crypt::decryptString((string) $snapshots[1]), true, flags: JSON_THROW_ON_ERROR)['virtual_mtas'][0]['pmta_name']);
        $this->assertSame('vmta-selected.example.test', json_decode(Crypt::decryptString((string) $snapshots[0]), true, flags: JSON_THROW_ON_ERROR)['virtual_mtas'][0]['domain']);
        $this->assertSame('vmta-first.example.test', json_decode(Crypt::decryptString((string) $snapshots[1]), true, flags: JSON_THROW_ON_ERROR)['virtual_mtas'][0]['domain']);
        $selectedConfiguration = app(PmtaVirtualMtaConfigBuilder::class)->build([[
            'id' => (int) $selectedPushedVmta->getKey(),
            'ip' => $selectedPushedVmta->ip,
            'dns' => $selectedPushedVmta->domain,
            'domain' => $selectedPushedVmta->domain,
            'route_host' => null,
            'duplicate_index' => $selectedPushedVmta->duplicate_index,
        ]], false, 40);
        $this->assertStringContainsString('<virtual-mta '.$expectedSelectedPmtaName.'>', $selectedConfiguration);
    }

    public function test_manual_ip_campaign_tests_pin_ipv4_and_ipv6_source_vmtas(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.10');
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'source-route.example.test',
            'state' => 'verified',
            'verification_token' => 'synthetic-source-route-token',
            'created_by' => $owner->getKey(),
            'status' => 'free',
            'is_active' => true,
        ]);
        $ipv4 = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.11',
            'type' => 'ipv4',
            'dns' => 'ipv4.source-route.example.test',
            'is_active' => true,
        ]);
        $ipv6 = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '2001:db8::65',
            'type' => 'ipv6',
            'dns' => 'ipv6.source-route.example.test',
            'is_active' => true,
        ]);
        $workspace->infrastructureSettings()->create(['pmta_port' => 2525]);

        $sentMessages = [];
        $transport = Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->twice();
        $transport->shouldReceive('send')->twice()->withArgs(function ($message, $envelope) use (&$sentMessages): bool {
            $sentMessages[] = [
                'message' => $message->toString(),
                'recipient' => $envelope->getRecipients()[0]->getAddress(),
            ];

            return true;
        });
        $transport->shouldReceive('stop')->twice();
        $factory = Mockery::mock(PmtaCampaignTestTransport::class);
        $factory->shouldReceive('forServer')->twice()
            ->withArgs(fn (ManagedServer $selected, int $port): bool => $selected->is($server) && $port === 2525)
            ->andReturn($transport);
        $this->app->instance(PmtaCampaignTestTransport::class, $factory);

        $attemptIds = [];
        foreach ([['ipv4', $ipv4, 'mta-203.0.113.11'], ['ipv6', $ipv6, 'mta-2001.db8..65']] as [$ipType, $ip, $pmtaName]) {
            $response = $this->actingAs($owner)->postJson(route('console.campaigns.manual-test', $workspace->getKey()), [
                'idempotency_key' => (string) Str::uuid(),
                'email_test_addresses' => 'synthetic-source-recipient@example.test',
                'header' => "MIME-Version: 1.0\nTo: [to]\nFrom: sender@[domain]\nSubject: Source route\nDate: [date]\nX-virtual-MTA: untrusted-value\n\tcontinued-untrusted-value",
                'body' => 'Source IP: [ip]',
                'send_setup' => [
                    'server_ids' => [(string) $server->getKey()],
                    'ip_ids' => [(int) $ip->getKey()],
                    'route_option' => 'ip',
                    'ip_type' => $ipType,
                    'send_type' => 'delay',
                ],
            ]);
            $response->assertOk()->assertJsonPath('outcome', 'accepted_by_transport');
            $attemptIds[] = (string) $response->json('attempt_id');
        }

        $this->assertCount(2, $sentMessages);
        foreach ([['mta-203.0.113.11', '203.0.113.11'], ['mta-2001.db8..65', '2001:db8::65']] as $index => [$pmtaName, $sourceIp]) {
            $message = $sentMessages[$index]['message'];
            $this->assertSame(1, preg_match_all('/^X-virtual-MTA:/mi', $message));
            $this->assertStringContainsString('X-virtual-MTA: '.$pmtaName, $message);
            $this->assertStringNotContainsString('untrusted-value', $message);
            $this->assertStringNotContainsString('continued-untrusted-value', $message);
            $this->assertStringContainsString('Source IP: '.$sourceIp, $message);

            $snapshot = (string) DB::table('campaign_manual_test_attempts')->where('id', $attemptIds[$index])->value('test_snapshot');
            $this->assertSame($pmtaName, json_decode(Crypt::decryptString($snapshot), true, flags: JSON_THROW_ON_ERROR)['virtual_mtas'][0]['pmta_name']);
        }
    }

    public function test_campaign_test_uses_post_reinstall_domain_and_saved_ip_assets_and_placeholders(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.90');
        $previousDomain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'before-reinstall.example.test',
            'state' => 'verified',
            'verification_token' => 'synthetic-old-domain-token',
            'created_by' => $owner->getKey(),
            'status' => 'used',
            'is_active' => true,
        ]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'pending-tags.example.test',
            'state' => 'pending',
            'verification_token' => 'synthetic-pending-tags-token',
            'created_by' => $owner->getKey(),
            'status' => 'free',
            'is_active' => true,
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => $server->ip_address,
            'type' => 'ipv4',
            'dns' => 'mta.pending-tags.example.test',
            'is_active' => true,
        ]);
        ManagedServerIpHistory::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_ip_id' => $ip->getKey(),
            'ptr' => 'ptr.pending-tags.example.test',
        ]);
        $sponsor = $this->sponsor($workspace);
        $offer = $this->offer($workspace, $sponsor);
        $from = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'type' => 'from',
            'value' => 'Fixture Sender',
        ]);
        $subject = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $workspace->getKey(),
            'offer_id' => $offer->getKey(),
            'type' => 'subject',
            'value' => 'Hello [text:alpha|beta]',
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Tag replacement campaign fixture',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => [
                'send_setup' => ['isp_id' => 37],
            ],
            'content_sha256' => hash('sha256', 'tag replacement fixture'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'tag replacement fixture request'),
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);
        $workspace->infrastructureSettings()->create(['pmta_port' => 2525]);

        $sentMessages = [];
        $transport = Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->times(3);
        $transport->shouldReceive('send')->times(3)->withArgs(function ($message, $envelope) use (&$sentMessages): bool {
            $sentMessages[] = [
                'message' => $message->toString(),
                'sender' => $envelope->getSender()->getAddress(),
                'recipient' => $envelope->getRecipients()[0]->getAddress(),
            ];

            return true;
        });
        $transport->shouldReceive('stop')->times(3);
        $factory = Mockery::mock(PmtaCampaignTestTransport::class);
        $factory->shouldReceive('forServer')->times(3)
            ->withArgs(fn (ManagedServer $selected, int $port): bool => $selected->is($server) && $port === 2525)
            ->andReturn($transport);
        $this->app->instance(PmtaCampaignTestTransport::class, $factory);

        $body = 'Server=[server]; IP=[ip]; Domain=[domain]; Main=[main_domain]; PTR=[rdns]; To=[to]; Name=[name]; From=[from]; Subject=[subject]; IDs=[ids]/[idi]/[idd]/[ide]; Placeholder=[placeholder1]; Random=[rndn_8]; Boundary=[bnd_30]; Token=[token]';
        $response = $this->actingAs($owner)->postJson(route('console.campaigns.manual-test', $workspace->getKey()), [
            'idempotency_key' => (string) Str::uuid(),
            'test_campaign_id' => $campaign->getKey(),
            'expected_revision_id' => $revision->getKey(),
            'return_path' => '[rndn_8]@[domain]',
            'email_test_addresses' => "first@example.test\nsecond@example.test\nthird@example.test",
            'header' => "MIME-Version: 1.0\nTo: [to]\nFrom: [from] <info@[domain]>\nSubject: [subject]\nX-DMS: [server]|[main_domain]|[ip]|[domain]|[rdns]|[ids]|[idi]|[idd]|[ide]|[placeholder1]",
            'body' => $body,
            'domain' => $previousDomain->name,
            'send_setup' => [
                'server_ids' => [(string) $server->getKey()],
                'route_option' => 'ip',
                'ip_type' => 'ipv4',
                'send_type' => 'delay',
                'isp_id' => 37,
                'from_asset_ids' => [(int) $from->getKey()],
                'subject_asset_ids' => [(int) $subject->getKey()],
                'placeholder_inputs' => [['data_text' => "first-value\nsecond-value", 'rotate_every' => 2]],
            ],
        ]);

        $response->assertOk()->assertJsonPath('outcome', 'accepted_by_transport');
        $this->assertCount(3, $sentMessages);
        $this->assertMatchesRegularExpression('/^\d{8}@mta\.pending-tags\.example\.test$/', $sentMessages[0]['sender']);
        $this->assertStringContainsString('info@mta.pending-tags.example.test', $sentMessages[0]['message']);
        $this->assertSame('first@example.test', $sentMessages[0]['recipient']);
        $this->assertSame('second@example.test', $sentMessages[1]['recipient']);
        $this->assertSame('third@example.test', $sentMessages[2]['recipient']);
        $this->assertStringContainsString('From: Fixture Sender <info@mta.pending-tags.example.test>', $sentMessages[0]['message']);

        foreach ($sentMessages as $index => $sent) {
            $expectedPlaceholder = $index < 2 ? 'first-value' : 'second-value';
            $this->assertStringContainsString('mta.pending-tags.example.test', $sent['message']);
            $this->assertStringContainsString('ptr.pending-tags.example.test', $sent['message']);
            $this->assertStringContainsString('203.0.113.90', $sent['message']);
            $this->assertStringContainsString('X-virtual-MTA: mta-203.0.113.90', $sent['message']);
            $this->assertStringNotContainsString($previousDomain->name, $sent['message']);
            $this->assertStringContainsString($expectedPlaceholder, $sent['message']);
            $this->assertMatchesRegularExpression('/Random=\d{8}/', $sent['message']);
            $this->assertMatchesRegularExpression('/Boundary=[A-Za-z0-9]{18}/', $sent['message']);
            $this->assertMatchesRegularExpression('/Subject: Hello (alpha|beta)/', $sent['message']);
        }

        $thirdMessage = $sentMessages[2]['message'];
        $this->assertStringContainsString('Server=SYNTHETIC SEND SERVER'.$server->getKey(), $thirdMessage);
        $this->assertStringContainsString('Main=mta.pending-tags.example.test', $thirdMessage);
        $this->assertStringContainsString('PTR=ptr.pending-tags.example.test', $thirdMessage);
        $this->assertStringContainsString('Placeholder=second-value', $thirdMessage);
        $this->assertMatchesRegularExpression('/^\d{8}@mta\.pending-tags\.example\.test$/', $sentMessages[2]['sender']);
        $this->assertSame(1, preg_match('/Token=([A-Za-z0-9+\/]+)/', $thirdMessage, $tokenMatch));
        $decodedToken = base64_decode($tokenMatch[1], true);
        $this->assertIsString($decodedToken);
        $tokenPrefix = str_replace('-', '', (string) $campaign->getKey()).'-0-0-t-0-37-0-0-0-0-0-0-';
        $this->assertStringStartsWith($tokenPrefix, $decodedToken);
        $tokenSuffix = explode('-', substr($decodedToken, strlen($tokenPrefix)));
        $this->assertCount(2, $tokenSuffix);
        $this->assertMatchesRegularExpression('/^[0-9a-f]+_[a-f0-9]{8}$/', $tokenSuffix[0]);
        $this->assertSame(hash('adler32', str_replace('-', '', (string) $campaign->getKey()).'_0_0', false), $tokenSuffix[1]);
    }

    public function test_campaign_register_automatic_test_uses_saved_revision_and_selected_send_type(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.90');
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'auto-test.example.test',
            'state' => 'verified',
            'verification_token' => 'synthetic-auto-test-token',
            'created_by' => $owner->getKey(),
            'status' => 'free',
            'is_active' => true,
        ]);
        $ip = ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.91',
            'type' => 'ipv4',
            'dns' => 'mta.auto-test.example.test',
            'is_active' => true,
        ]);
        $workspace->infrastructureSettings()->create(['pmta_port' => 2525]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Saved automatic-test fixture',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => false,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $header = "MIME-version: 1.0\nContent-type: text/html\nTo: [to]\nFrom: synthetic-sender@auto-test.example.test\nSubject: Saved campaign subject\nDate: [date]";
        $body = '<p>Saved campaign body</p>';
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => [
                'return_path' => '[rnd]@[domain]',
                'email_tests' => ['synthetic-recipient@example.test'],
                'header' => $header,
                'body' => $body,
                'send_setup' => [
                    'server_ids' => [(string) $server->getKey()],
                    'route_option' => 'ip',
                    'ip_type' => 'ipv4',
                    'send_type' => 'delay',
                    'from_asset_ids' => [],
                    'subject_asset_ids' => [],
                    'domain' => '',
                ],
            ],
            'content_sha256' => hash('sha256', 'saved automatic test fixture'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'saved automatic test request'),
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);

        $transport = Mockery::mock(SmtpTransport::class);
        $transport->shouldReceive('start')->once();
        $transport->shouldReceive('send')->once()->withArgs(fn ($message, $envelope): bool => str_contains($message->toString(), 'Subject: Saved campaign subject')
            && str_contains($message->toString(), 'To: synthetic-recipient@example.test')
            && str_contains($message->toString(), $body)
            && $envelope->getRecipients()[0]->getAddress() === 'synthetic-recipient@example.test');
        $transport->shouldReceive('stop')->once();
        $factory = Mockery::mock(PmtaCampaignTestTransport::class);
        $factory->shouldReceive('forServer')->once()->withArgs(fn (ManagedServer $selected, int $port): bool => $selected->is($server) && $port === 2525)->andReturn($transport);
        $this->app->instance(PmtaCampaignTestTransport::class, $factory);

        $indexUrl = route('console.mailer.campaigns');
        $disabledIndexUrl = $indexUrl.'?disabled=1';
        $this->actingAs($owner)->get($disabledIndexUrl)
            ->assertOk()
            ->assertSee('id="campaign-send-type-'.$campaign->getKey().'"', false)
            ->assertSee('name="send_type" form="campaign-auto-test-'.$campaign->getKey().'"', false)
            ->assertSee('value="pickup"', false)
            ->assertSee('id="campaign-auto-test-'.$campaign->getKey().'"', false)
            ->assertSee('data-ip-count="0"', false)
            ->assertSee('action="'.route('clean.console.campaigns.auto-test', $campaign->getKey()).'"', false)
            ->assertSee('name="idempotency_key"', false)
            ->assertSee('data-logs-url="'.route('clean.console.campaigns.show', $campaign->getKey()).'#campaign-test-logs"', false)
            ->assertSee('window.AlphaLinkToast?.show(autoTestFeedback.textContent', false)
            ->assertSee('aria-label="Test Saved automatic-test fixture"', false);

        $this->actingAs($owner)->from($disabledIndexUrl)
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->post(route('console.campaigns.auto-test', [$workspace->getKey(), $campaign->getKey()]), ['send_type' => 'pickup', 'idempotency_key' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('data', 'Selected PMTA servers accepted the current-form Email Test messages. Mailbox delivery is unconfirmed.')
            ->assertJsonPath('outcome', 'accepted_by_transport')
            ->assertJsonStructure(['attempt_id', 'next_idempotency_key']);

        $this->assertDatabaseHas('campaign_manual_test_attempts', [
            'campaign_id' => $campaign->getKey(),
            'managed_server_id' => $server->getKey(),
            'status' => 'accepted_by_transport',
        ]);
        $automaticTestFingerprint = (string) DB::table('campaign_manual_test_attempts')
            ->where('campaign_id', $campaign->getKey())
            ->value('request_fingerprint');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $automaticTestFingerprint);
        $this->assertSame(1, DB::table('campaign_manual_test_attempts')->count());

        $campaignWithoutAddress = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'No saved Email Test fixture',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        CampaignRevision::query()->create([
            'campaign_id' => $campaignWithoutAddress->getKey(),
            'revision_number' => 1,
            'payload' => [
                'email_tests' => [],
                'header' => $header,
                'body' => $body,
                'send_setup' => ['server_ids' => [(string) $server->getKey()], 'ip_ids' => [(string) $ip->getKey()]],
            ],
            'content_sha256' => hash('sha256', 'no saved email test fixture'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'no saved email test request'),
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);

        $this->actingAs($owner)->from($disabledIndexUrl)
            ->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->post(route('console.campaigns.auto-test', [$workspace->getKey(), $campaignWithoutAddress->getKey()]), ['send_type' => 'delay', 'idempotency_key' => (string) Str::uuid()])
            ->assertBadRequest()
            ->assertJsonPath('data', 'No email test');
        $this->assertSame(1, DB::table('campaign_manual_test_attempts')->count());
    }

    public function test_edit_page_reloads_available_ips_from_old_routing_input_after_email_validation_failure(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.50');
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'campaign.fixture.test',
            'state' => 'verified',
            'verification_token' => 'synthetic-campaign-domain-token',
            'created_by' => $owner->getKey(),
            'status' => 'free',
            'is_our' => false,
            'is_active' => true,
        ]);
        ManagedServerIp::query()->create([
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'sending_domain_id' => $domain->getKey(),
            'ip' => '203.0.113.51',
            'type' => 'ipv4',
            'dns' => 'mta.campaign.fixture.test',
            'is_active' => true,
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Legacy DMS campaign fixture',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => [
                'subject' => 'Legacy campaign fixture',
                'from_name' => 'Fixture Sender',
                'from_email' => 'sender@campaign.fixture.test',
                'reply_to' => null,
                'text_body' => 'Fixture body',
                'html_body' => '<p>Fixture body</p>',
            ],
            'content_sha256' => hash('sha256', 'legacy campaign fixture'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'legacy campaign fixture request'),
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);
        $editUrl = route('clean.console.campaigns.edit', $campaign->getKey());

        $this->actingAs($owner)->from($editUrl)->post(route('console.campaigns.manual-test', $workspace->getKey()), [
            'idempotency_key' => (string) Str::uuid(),
            'test_campaign_id' => $campaign->getKey(),
            'expected_revision_id' => $revision->getKey(),
            'email_test_addresses' => 'not-an-email',
            'header' => "MIME-version: 1.0\nFrom: sender@campaign.fixture.test\nSubject: Fixture\n",
            'body' => '<p>Fixture body</p>',
            'send_setup' => [
                'server_ids' => [$server->getKey()],
                'route_option' => 'ip',
                'ip_type' => 'ipv4',
                'send_type' => 'delay',
            ],
        ])->assertRedirect($editUrl)->assertSessionHasErrors('email_test_addresses');

        $this->assertDatabaseCount('campaign_manual_test_attempts', 0);
        $this->get($editUrl)
            ->assertOk()
            ->assertSee('SYNTHETIC SEND SERVER | 203.0.113.50')
            ->assertSee('203.0.113.51,mta.campaign.fixture.test', false)
            ->assertSee('data-route="ip"', false)
            ->assertSee('data-server-id="'.$server->getKey().'"', false);
    }

    public function test_saved_test_addresses_are_private_to_the_campaign_owner_and_encrypted(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $other = User::factory()->create();
        $workspace->users()->attach($other->getKey(), ['role' => 'operator']);
        $response = $this->actingAs($owner)->postJson(route('console.campaign-test-addresses.store', $workspace->getKey()), [
            'email' => 'private-recipient@example.test',
        ])->assertCreated()->assertJsonPath('data.email', 'private-recipient@example.test');
        $id = $response->json('data.id');
        $stored = DB::table('workspace_campaign_test_addresses')->where('id', $id)->sole();
        $this->assertNotSame('private-recipient@example.test', $stored->email);
        $this->get(route('clean.console.campaigns.create'))->assertOk()->assertSee('private-recipient@example.test');
        $this->actingAs($other)->get(route('clean.console.campaigns.create'))
            ->assertOk()->assertDontSee('private-recipient@example.test');
        $this->deleteJson(route('console.campaign-test-addresses.destroy', [$workspace->getKey(), $id]))->assertNotFound();
        $this->actingAs($owner)->deleteJson(route('console.campaign-test-addresses.destroy', [$workspace->getKey(), $id]))->assertOk();
        $this->assertDatabaseCount('workspace_campaign_test_addresses', 0);
    }

    public function test_saved_pmta_http_port_is_used_for_panel_links_and_future_install_inputs(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.60');

        $this->actingAs($owner)->post(route('console.app-settings.update', $workspace->getKey()), [
            'settings' => ['allow_all_monitor' => true, 'pmta_admin_port' => '2588'],
        ])->assertRedirect();

        $this->getJson(route('console.campaigns.send-options', [
            $workspace->getKey(),
            'server_ids' => [$server->getKey()],
            'route' => 'ip',
            'ip_type' => 'ipv4',
        ]))->assertOk()
            ->assertJsonPath('data.settings.pmta_admin_port', 2588)
            ->assertJsonPath('data.servers.0.pmta_panel_url', '//203.0.113.60:2588/');

        $inputs = app(DmsBulkInstallRuntimeInputs::class)->resolve([
            'tracking' => false,
            'proxy' => false,
            'imap' => false,
            'spf' => false,
            'dkim' => false,
            'dmarc' => false,
            'https' => false,
        ], $workspace->fresh());
        $this->assertSame(2588, $inputs['settings']['pmta_admin_port']);
    }

    public function test_pmta_admin_page_renders_selected_server_and_runs_source_mapped_queue_command(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.40');
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);

        $adminPage = $this->actingAs($owner)->get(route('clean.console.pmta-admin.index', [
            'server_ids' => [$server->getKey()],
        ]));
        $adminPage
            ->assertOk()
            ->assertSee("frame-src 'self' ;", false)
            ->assertDontSee("frame-src 'self' http://203.0.113.40:2447;", false)
            ->assertSee('PMTA ADMIN ACTIONS')
            ->assertSee('203.0.113.40:2447')
            ->assertSee('PAUSE')
            ->assertSee('RESUME')
            ->assertSee('DELETE')
            ->assertSee('RESET')
            ->assertSee('SCHEDULE')
            ->assertSee('RESTART')
            ->assertSee('x2')
            ->assertSee('x3')
            ->assertSee('x4')
            ->assertSee('MY SERVERS')
            ->assertSee('PMTA')
            ->assertSee('PMTA ACTION HISTORY')
            ->assertSee('aria-label="PMTA Admin"', false)
            ->assertSee('id="pmta-admin-search-servers"', false)
            ->assertSee('id="pmta-admin-servers"', false)
            ->assertSee('data-live-search="true"', false)
            ->assertSee('dms-reference/plugins/bootstrap_select/bootstrap-select.js')
            ->assertSee("inputLabel: 'Search Servers'", false)
            ->assertSee('id="pmta-admin-reload-servers"', false)
            ->assertSee('data-panel-url="//203.0.113.40:2447/"', false)
            ->assertSee('data-monitor-url="/pmta-admin/servers/'.$server->getKey().'"', false)
            ->assertSee('link.href = server.dataset.panelUrl;', false)
            ->assertSee('frame.dataset.monitorBase = server.dataset.monitorUrl;', false)
            ->assertSee('frame.src = url.toString();', false)
            ->assertSee('id="pmta-admin-stop-schedule"', false)
            ->assertSee('id="pmta-admin-stop-restart"', false)
            ->assertSee('id="pmta-admin-schedule-status"', false)
            ->assertDontSee('Leave blank or enter 0 for one run. Enter N seconds to run once now and repeat every N seconds until STOP. PMTA can only schedule matching queued jobs; this does not launch a campaign at a future time.')
            ->assertDontSee('id="pmta-monitor-refresh-status"', false)
            ->assertSee('<th>Run</th><th>Server</th><th>Command</th><th>Result</th>', false)
            ->assertSee('const monitorRefreshMs = 5000;', false)
            ->assertSee('auto refresh 5s')
            ->assertSee('const rememberSelection = () => {', false)
            ->assertSee("url.searchParams.append('server_ids[]', server.value)", false)
            ->assertSee('panels.hidden = !hasSelectedServers;', false)
            ->assertDontSee('monitorRefreshStatus.hidden = !hasSelectedServers;', false)
            ->assertSee('monitorRefreshTimer = window.setInterval', false)
            ->assertSee('Submitted ${scheduleRuns} run(s)', false)
            ->assertSee('received ${scheduleResponseCount} server response(s)', false)
            ->assertSee('const scheduleSelectedServers = async (serverIds, serverNames, values, queue, runNumber, seconds) => {', false)
            ->assertSee("queueAction('schedule', [serverId], values, queue, runNumber, {render: false, commandText: command})", false)
            ->assertSee('schedule */* executes immediately and then every ${seconds} second(s)', false)
            ->assertSee('return {success: items.length > 0 && items.every((item) => item.success === true), received: true, items, message: null};', false)
            ->assertSee('PMTA found no matching queued jobs, so nothing was scheduled.', false)
            ->assertSee('Schedule is active; run ${runNumber} returned a PMTA error. It will check again in ${seconds} second(s) until STOP.', false)
            ->assertSee('Schedule stopped after a request failed before a PMTA response.', false)
            ->assertSee("} else if (seconds === 0) {\n                            scheduleButton.disabled = false;", false)
            ->assertSee('One schedule run returned a PMTA error. Inspect the latest server result, then retry.')
            ->assertSee('0 of 0 matching queues scheduled', false)
            ->assertSee('const commandForAction = (action, queue, values = []) => {', false)
            ->assertSee('if (action === \'schedule\') return `schedule ${tag}`;', false)
            ->assertSee('command.textContent = item.command || \'\';', false)
            ->assertSee("storedQueueTags === null ? ['*/*'] : storedQueueTags.split(',').filter(Boolean)", false)
            ->assertSee('Select the */* queue tag before executing a PMTA command.')
            ->assertSee("localStorage.setItem('queues', '')", false)
            ->assertSee('window.setInterval(() => { void scheduleOnce(); }, seconds * 1000)', false)
            ->assertSee('window.clearInterval(scheduleTimer)', false)
            ->assertSee('scheduleButton.disabled = false;', false);

        $emptyAdminPage = $this->actingAs($owner)->get(route('clean.console.pmta-admin.index'));
        $emptyAdminPage->assertOk();
        $emptyAdminPage->assertDontSee('id="pmta-monitor-refresh-status"', false);
        $this->assertMatchesRegularExpression('/id="pmta-admin-panels"\s+hidden\s+aria-live="polite"/', $emptyAdminPage->getContent());

        $intervalAssignmentPosition = strpos($adminPage->getContent(), 'scheduleTimer = window.setInterval');
        $initialScheduleRequestPosition = strrpos($adminPage->getContent(), 'void scheduleOnce();');
        $this->assertNotFalse($intervalAssignmentPosition);
        $this->assertNotFalse($initialScheduleRequestPosition);
        $this->assertLessThan($initialScheduleRequestPosition, $intervalAssignmentPosition);

        preg_match('/data-panel-url="(\/\/[^\"]+)"/', $adminPage->getContent(), $panelMatch);
        $panel = parse_url('http:'.($panelMatch[1] ?? ''));
        $this->assertIsArray($panel);
        $this->assertSame('203.0.113.40', $panel['host']);
        $this->assertSame(2447, $panel['port']);

        Http::fake(fn () => Http::response('<result><succ>paused</succ></result>'));

        $this->actingAs($owner)->postJson(route('console.pmta-admin.action', $workspace->getKey()), [
            'server_ids' => [$server->getKey()],
            'action' => 'pause',
            'queue' => 'domain',
            'tags' => ['*/*'],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $server->getKey())
            ->assertJsonPath('data.0.success', true)
            ->assertJsonPath('data.0.result', 'paused');

        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return parse_url($request->url(), PHP_URL_PATH) === '/command'
                && ($query['format'] ?? null) === 'xml'
                && ($query['command'] ?? null) === 'pmta pause queue */*';
        });

        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $owner->getKey(),
            'action' => 'pmta.action',
            'subject_type' => 'managed_server',
            'subject_id' => $server->getKey(),
        ]);

        $historyUrl = route('clean.console.pmta-admin.history');
        $this->actingAs($owner)->get($historyUrl)
            ->assertOk()
            ->assertSee('PMTA ACTION HISTORY')
            ->assertSee('id="pmta-history-filter"', false)
            ->assertSee('id="pmta-action-history"', false);
        $historyQuery = http_build_query([
            'server' => $server->getKey(),
            'draw' => 7,
            'start' => 0,
            'length' => 50,
            'search' => ['value' => 'paused'],
        ]);
        $this->actingAs($owner)->getJson($historyUrl.'?'.$historyQuery)
            ->assertOk()
            ->assertJsonPath('draw', 7)
            ->assertJsonPath('recordsTotal', 1)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.server.id', $server->getKey())
            ->assertJsonPath('data.0.action', 'pause')
            ->assertJsonPath('data.0.result', 'paused');
        $historyRecord = PmtaActionHistory::query()->sole();
        self::assertIsInt($historyRecord->getKey());
        self::assertDatabaseHas('pmta_action_histories', [
            'id' => $historyRecord->getKey(),
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'actor_user_id' => $owner->getKey(),
            'action' => 'pause',
            'result' => 'paused',
        ]);

        $restart = Mockery::mock(DmsPmtaAdminRestart::class);
        $restart->shouldReceive('run')
            ->once()
            ->withArgs(fn (ManagedServer $actual): bool => (string) $actual->getKey() === (string) $server->getKey())
            ->andReturn('PMTA restarted');
        $this->app->instance(DmsPmtaAdminRestart::class, $restart);

        $this->actingAs($owner)->postJson(route('console.pmta-admin.restart', $workspace->getKey()), [
            'server_ids' => [$server->getKey()],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $server->getKey())
            ->assertJsonPath('data.0.result', 'PMTA restarted')
            ->assertJsonPath('data.0.success', true);
        self::assertDatabaseHas('pmta_action_histories', [
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'actor_user_id' => $owner->getKey(),
            'action' => 'restart',
            'result' => 'PMTA restarted',
        ]);
        self::assertSame(2, PmtaActionHistory::query()->count());

        $failedRestart = Mockery::mock(DmsPmtaAdminRestart::class);
        $failedRestart->shouldReceive('run')
            ->once()
            ->andThrow(new \RuntimeException('PMTA restart failed'));
        $this->app->instance(DmsPmtaAdminRestart::class, $failedRestart);
        $this->actingAs($owner)->postJson(route('console.pmta-admin.restart', $workspace->getKey()), [
            'server_ids' => [$server->getKey()],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.result', 'PMTA restart failed')
            ->assertJsonPath('data.0.success', false);
        self::assertSame(2, PmtaActionHistory::query()->count());

        $retryRestart = Mockery::mock(DmsPmtaAdminRestart::class);
        $retryRestart->shouldReceive('run')
            ->once()
            ->andReturn('PMTA restarted again');
        $this->app->instance(DmsPmtaAdminRestart::class, $retryRestart);
        $this->actingAs($owner)->postJson(route('console.pmta-admin.restart', $workspace->getKey()), [
            'server_ids' => [$server->getKey()],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $server->getKey())
            ->assertJsonPath('data.0.result', 'PMTA restarted again')
            ->assertJsonPath('data.0.success', true);
        self::assertSame(3, PmtaActionHistory::query()->count());

        $historyQuery = http_build_query([
            'server' => $server->getKey(),
            'draw' => 8,
            'start' => 0,
            'length' => 50,
            'search' => ['value' => 'restarted again'],
        ]);
        $this->actingAs($owner)->getJson($historyUrl.'?'.$historyQuery)
            ->assertOk()
            ->assertJsonPath('draw', 8)
            ->assertJsonPath('recordsFiltered', 1)
            ->assertJsonPath('data.0.server.id', $server->getKey())
            ->assertJsonPath('data.0.action', 'restart')
            ->assertJsonPath('data.0.result', 'PMTA restarted again');
    }

    public function test_pmta_admin_and_history_are_available_to_every_workspace_member(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $member = User::factory()->create();
        $workspace->users()->attach($member->getKey(), ['role' => 'viewer']);
        $server = $this->server($workspace, $owner, '203.0.113.61');
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);

        $this->actingAs($member)
            ->get(route('clean.console.pmta-admin.index', ['server_ids' => [$server->getKey()]]))
            ->assertOk()
            ->assertSee('PMTA ADMIN ACTIONS')
            ->assertSee('MY SERVERS')
            ->assertSee('PMTA ACTION HISTORY');
        $this->actingAs($member)
            ->get(route('clean.console.pmta-admin.history'))
            ->assertOk();
        $this->actingAs($member)
            ->getJson(route('clean.console.pmta-admin.history'))
            ->assertOk();

        Http::fake([
            'http://203.0.113.61:2447/' => Http::response('<!doctype html><html><body>Member monitor</body></html>'),
        ]);
        $this->actingAs($member)
            ->get(route('clean.console.pmta-admin.monitor', $server->getKey()))
            ->assertOk()
            ->assertSee('Member monitor');

        Http::fake(fn () => Http::response('<result><succ>accepted</succ></result>'));
        $this->actingAs($member)->postJson(route('console.pmta-admin.action', $workspace->getKey()), [
            'server_ids' => [$server->getKey()],
            'action' => 'pause',
            'queue' => 'domain',
            'tags' => ['*/*'],
        ])->assertOk()->assertJsonPath('data.0.result', 'accepted');

        $restart = Mockery::mock(DmsPmtaAdminRestart::class);
        $restart->shouldReceive('run')
            ->once()
            ->withArgs(fn (ManagedServer $actual): bool => (string) $actual->getKey() === (string) $server->getKey())
            ->andReturn('PMTA restarted');
        $this->app->instance(DmsPmtaAdminRestart::class, $restart);
        $this->actingAs($member)
            ->postJson(route('console.pmta-admin.restart', $workspace->getKey()), [
                'server_ids' => [$server->getKey()],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.result', 'PMTA restarted')
            ->assertJsonPath('data.0.success', true);
        self::assertDatabaseHas('pmta_action_histories', [
            'workspace_id' => $workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'actor_user_id' => $member->getKey(),
            'action' => 'restart',
            'result' => 'PMTA restarted',
        ]);
    }

    public function test_pmta_monitor_proxy_renders_selected_servers_read_only_and_blocks_command_routes(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.62');
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);
        $statusUrl = route('clean.console.pmta-admin.monitor', [
            'server' => $server->getKey(),
            'monitorPath' => 'status',
        ], false);

        Http::fake([
            'http://203.0.113.62:2447/' => Http::response('<!doctype html><html><head><link rel="stylesheet" href="css.css"></head><body><a href="/status">Status</a><a href="/command">Run command</a><form action="/command"><input value="reset counters"></form><script>window.dangerous = true;</script></body></html>'),
        ]);

        $this->actingAs($owner)->get(route('clean.console.pmta-admin.monitor', $server->getKey()))
            ->assertOk()
            ->assertSee('Status')
            ->assertSee($statusUrl, false)
            ->assertSee('aria-disabled="true"', false)
            ->assertDontSee('href="/command"', false)
            ->assertDontSee('reset counters')
            ->assertDontSee('window.dangerous');

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && $request->url() === 'http://203.0.113.62:2447/');

        Http::fake();
        $this->actingAs($owner)->get(route('clean.console.pmta-admin.monitor', [
            'server' => $server->getKey(),
            'monitorPath' => 'command',
        ]))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_pmta_admin_action_accepts_dms_unbounded_server_and_tag_arrays(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.46');
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);

        $serverIds = array_merge(
            [(string) $server->getKey()],
            array_map(static fn (int $index): string => (string) Str::uuid(), range(1, 100)),
        );
        $tags = array_merge(['*/*'], array_map(static fn (int $index): string => 'queue-'.$index, range(1, 200)));

        Http::fake(fn () => Http::response('<result><succ>reset</succ></result>'));

        $this->actingAs($owner)->postJson(route('console.pmta-admin.action', $workspace->getKey()), [
            'server_ids' => $serverIds,
            'action' => 'reset',
            'queue' => 'domain',
            'tags' => $tags,
        ])->assertUnprocessable()
            ->assertJsonPath('errors.server_ids.0', 'Select active installed servers available to your account.');

        Http::assertNothingSent();

        $this->actingAs($owner)->postJson(route('console.pmta-admin.action', $workspace->getKey()), [
            'server_ids' => [(string) $server->getKey()],
            'action' => 'reset',
            'queue' => 'domain',
            'tags' => $tags,
        ])->assertOk()
            ->assertJsonPath('data.0.success', true)
            ->assertJsonPath('data.0.result', 'reset');

        Http::assertSent(function (ClientRequest $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['format'] ?? null) === 'xml' && ($query['command'] ?? null) === 'reset counters';
        });
    }

    public function test_pmta_admin_restart_accepts_more_than_one_hundred_selected_servers(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $servers = [];
        for ($index = 1; $index <= 101; $index++) {
            $servers[] = $this->server($workspace, $owner, '198.51.100.'.$index);
        }
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);

        $restart = Mockery::mock(DmsPmtaAdminRestart::class);
        $restart->shouldReceive('run')->times(101)->andReturn('PMTA restarted');
        $this->app->instance(DmsPmtaAdminRestart::class, $restart);

        $serverIds = array_map(static fn (ManagedServer $server): string => (string) $server->getKey(), $servers);
        $this->actingAs($owner)->postJson(route('console.pmta-admin.restart', $workspace->getKey()), [
            'server_ids' => $serverIds,
        ])->assertOk()
            ->assertJsonCount(101, 'data')
            ->assertJsonPath('data.0.success', true)
            ->assertJsonPath('data.100.success', true);

        self::assertSame(101, PmtaActionHistory::query()->where('action', 'restart')->count());
    }

    public function test_pmta_queue_actions_send_the_saved_wildcard_using_dms_commands_and_admin_port(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.42');
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);

        Http::fake(fn () => Http::response('<result><succ>ok</succ></result>'));

        $expectedCommands = [
            'pause' => 'pmta pause queue */*',
            'resume' => 'resume queue */*',
            'delete' => 'delete --queue=*/*',
            'reset' => 'reset counters',
            'schedule' => 'schedule */*',
        ];

        foreach ([40, 45, 50] as $version) {
            $server->forceFill(['install_parameters' => ['pmta_version' => $version]])->save();
            foreach ($expectedCommands as $action => $expectedCommand) {
                $this->actingAs($owner)->postJson(route('console.pmta-admin.action', $workspace->getKey()), [
                    'server_ids' => [$server->getKey()],
                    'action' => $action,
                    'queue' => 'domain',
                    'tags' => ['*/*'],
                ])
                    ->assertOk()
                    ->assertJsonPath('data.0.success', true)
                    ->assertJsonPath('data.0.result', 'ok');
            }
        }

        $requests = Http::recorded();
        $this->assertCount(count($expectedCommands) * 3, $requests);
        $actualCommands = $requests->map(static function (array $pair): array {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return [
                'port' => parse_url($pair[0]->url(), PHP_URL_PORT),
                'format' => $query['format'] ?? null,
                'command' => $query['command'] ?? null,
            ];
        })->all();

        $expectedRequests = [];
        foreach ([40, 45, 50] as $_version) {
            foreach (array_values($expectedCommands) as $command) {
                $expectedRequests[] = [
                    'port' => 2447,
                    'format' => 'xml',
                    'command' => $command,
                ];
            }
        }

        $this->assertSame($expectedRequests, $actualCommands);
    }

    public function test_empty_queue_schedule_response_is_a_successful_pmta_command_result(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.44');
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);

        Http::fake(fn () => Http::response('<result><succ>0 of 0 matching queues scheduled.</succ></result>'));

        $this->actingAs($owner)->postJson(route('console.pmta-admin.action', $workspace->getKey()), [
            'server_ids' => [$server->getKey()],
            'action' => 'schedule',
            'queue' => 'domain',
            'tags' => ['*/*'],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.success', true)
            ->assertJsonPath('data.0.result', '0 of 0 matching queues scheduled.');
    }

    public function test_pmta_reset_requires_wildcard_selection_even_though_the_dms_command_is_global(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.43');
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);

        Http::fake();

        $this->actingAs($owner)->postJson(route('console.pmta-admin.action', $workspace->getKey()), [
            'server_ids' => [$server->getKey()],
            'action' => 'reset',
            'queue' => 'domain',
            'tags' => ['example.test'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tags');

        Http::assertNothingSent();
    }

    public function test_failed_pmta_queue_command_is_not_added_to_action_history(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $server = $this->server($workspace, $owner, '203.0.113.41');
        $workspace->infrastructureSettings()->create(['pmta_admin_port' => 2447]);

        Http::fakeSequence()
            ->push('<result><fail>relay denied</fail></result>')
            ->push('<result><body>Access denied</body></result>');

        $this->actingAs($owner)->postJson(route('console.pmta-admin.action', $workspace->getKey()), [
            'server_ids' => [$server->getKey()],
            'action' => 'pause',
            'queue' => 'domain',
            'tags' => ['*/*'],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.success', false)
            ->assertJsonPath('data.0.result', 'relay denied');

        $this->actingAs($owner)->postJson(route('console.pmta-admin.action', $workspace->getKey()), [
            'server_ids' => [$server->getKey()],
            'action' => 'pause',
            'queue' => 'domain',
            'tags' => ['*/*'],
        ])
            ->assertOk()
            ->assertJsonPath('data.0.success', false)
            ->assertJsonPath('data.0.result', 'PMTA returned access denied. Verify the AlphaLink app host is allowed for admin access in PMTA; the */* tag only selects the queue.');

        self::assertSame(0, AuditEvent::query()->where('action', 'pmta.action')->count());
        self::assertSame(0, PmtaActionHistory::query()->count());
    }

    public function test_marketing_create_screen_uses_dms_sections_and_marks_unimplemented_send_steps(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');

        $response = $this->actingAs($owner)->get(route('clean.console.campaigns.create', $workspace->getKey()));

        $response->assertOk()
            ->assertSee('CREATE CAMPAIGN')
            ->assertSee('id="campaign-actions-trigger"', false)
            ->assertSee('<i class="fas fa-bars" aria-hidden="true"></i>', false)
            ->assertSee("classList.replace('fa-bars', 'fa-times')", false)
            ->assertSee('id="campaign-actions-panel"', false)
            ->assertSee('id="campaign-context-menu"', false)
            ->assertSee('window.AlphaLinkToast =', false)
            ->assertSee('alphalink-toast-stack', false)
            ->assertSee('id="campaign-data" tabindex="-1"', false)
            ->assertSee('data-campaign-menu-action="create"', false)
            ->assertSee('data-campaign-menu-action="create-send"', false)
            ->assertSee('data-campaign-menu-action="test"', false)
            ->assertSee('data-campaign-menu-action="pmta-admin"', false)
            ->assertSee('data-campaign-menu-action="pmta-panel"', false)
            ->assertSeeText('CREATE & SEND CAMPAIGN')
            ->assertSee('aria-disabled="true"', false)
            ->assertSee('contextArea.addEventListener(\'contextmenu\'', false)
            ->assertSee('contextTarget = contextFocusTarget(event.target)', false)
            ->assertSee('Select Offer')
            ->assertSee('Select List')
            ->assertSee('id="campaign-routing"', false)
            ->assertDontSee('Symfony one-recipient test message')
            ->assertDontSee('name="from_email"', false)
            ->assertDontSee('name="text_body"', false)
            ->assertDontSee('name="html_body"', false)
            ->assertSee('offer and workspace suppressions')
            ->assertSee('native campaign send')
            ->assertSee('send_setup[from_asset_ids][]', false)
            ->assertSee('send_setup[subject_asset_ids][]', false)
            ->assertSee('send_setup[creative_asset_id]', false)
            ->assertSee('send_setup[html_asset_id]', false)
            ->assertSee('send_setup[negative_id]', false)
            ->assertSee('send_setup[sponsor_id]', false)
            ->assertSee('send_setup[audience_segments][]', false)
            ->assertSee('send_setup[server_ids][]', false)
            ->assertSee('send_setup[ip_ids][]', false)
            ->assertSee("option.dataset.route = select('route').value || 'ip';", false)
            ->assertSee('Campaign Save requires at least one IP in Selected IPs. Test Campaign does not require a separate IP selection;', false)
            ->assertSee('name="return_path"', false)
            ->assertSee('name="email_test_addresses"', false)
            ->assertSee('name="domain"', false)
            ->assertSee('name="header"', false)
            ->assertSee('name="body"', false)
            ->assertSee('id="campaign-tags-dialog"', false)
            ->assertSee('id="campaign-tags-help" title="SHOW EXAMPLE"', false)
            ->assertSee('[rndn_N]')
            ->assertSee('[rnda_N]')
            ->assertSee('[rndl_N]')
            ->assertSee('[rndu_N]')
            ->assertSee('[rnds_N]')
            ->assertSee('[rndlu_N]')
            ->assertSee('[rndln_N]')
            ->assertSee('[rndun_N]')
            ->assertSeeText('[rnd] generates 18 random characters')
            ->assertSee('[bndn_N]')
            ->assertSee('[bnda_N]')
            ->assertSee('[bndl_N]')
            ->assertSee('[bndu_N]')
            ->assertSee('[bnds_N]')
            ->assertSee('[bndlu_N]')
            ->assertSee('[bndln_N]')
            ->assertSee('[bndun_N]')
            ->assertSee('[smtp_name]')
            ->assertSee('smtp name')
            ->assertSee('tag reply extractor')
            ->assertSee('30 static characters')
            ->assertSee('18-character cached value')
            ->assertSee('native sender behavior remains unknown')
            ->assertSee('#[token]')
            ->assertSee('id="campaign-tags-dialog" data-campaign-dialog-width="1140px"', false)
            ->assertSee("showDialog('campaign-tags-dialog')", false)
            ->assertSee('rows="30" class="form-control campaign-header-editor"', false)
            ->assertDontSee('maxlength="200000" class="form-control campaign-header-editor"', false)
            ->assertSee("campaignDomainField.value = '';", false)
            ->assertSee("applyCampaignDomainToBody('[domain]')", false)
            ->assertSee('The selected VMTA has no configured domain.', false)
            ->assertDontSee('Uses the current Header and Body with the Email Test addresses.')
            ->assertSee('PLACEHOLDERS')
            ->assertSee('PLACEHOLDER')
            ->assertSee('ROTATE EVERY')
            ->assertSee('ACTION')
            ->assertSee('data-placeholder-add', false)
            ->assertDontSee('name="send_setup[placeholder_inputs][0][data_text]"', false)
            ->assertDontSee('name="send_setup[placeholder_inputs][0][rotate_every]"', false)
            ->assertSee('COMBINATION')
            ->assertSee('ALL COMBINATION')
            ->assertSee('/composer/options', false)
            ->assertDontSee('campaign-send-options')
            ->assertSee('id="campaign-email-tests" name="email_test_addresses"', false)
            ->assertDontSee('id="campaign-email-tests" name="email_test_addresses" rows="2" maxlength="50000" class="form-control" autocomplete="off" required', false)
            ->assertSee('bootstrap_select/bootstrap-select.js', false);

        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/<tbody data-placeholder-rows>\s*<\/tbody>/s', $html);
        $this->assertMatchesRegularExpression('/<form\b[^>]*id="campaign-create-form"[^>]*\bnovalidate(?:\s|>)/s', $html);
        $menuButtons = [];
        preg_match_all('/data-campaign-menu-action="([^"]+)"[^>]*>(.*?)<\/button>/s', $html, $menuMatches, PREG_SET_ORDER);
        foreach ($menuMatches as $menuMatch) {
            $menuButtons[$menuMatch[1]][] = $menuMatch[2];
        }
        foreach ([
            'create' => 'fa-plus',
            'create-send' => 'fa-paper-plane',
            'test' => 'fa-vial',
            'pmta-admin' => 'fa-grip-horizontal',
            'pmta-panel' => 'fa-server',
        ] as $action => $icon) {
            $this->assertCount(2, $menuButtons[$action] ?? []);
            foreach ($menuButtons[$action] as $button) {
                $this->assertStringContainsString('fas '.$icon, $button);
            }
        }
        $this->assertSame(2, preg_match_all('/<button\\b(?=[^>]*type="button")(?=[^>]*role="menuitem")(?=[^>]*data-campaign-menu-action="test")(?=[^>]*data-test-url="[^"]*\\/campaigns\\/manual-test")[^>]*>/', $html));
        $this->assertSame(0, preg_match_all('/<button\\b(?=[^>]*type="submit")(?=[^>]*data-campaign-menu-action="test")[^>]*>/', $html));
        $this->assertStringContainsString("item.addEventListener('click', (event) => {", $html);
        $this->assertStringContainsString("if (item.dataset.campaignMenuAction === 'test')", $html);
        $this->assertStringContainsString('fetch(item.dataset.testUrl', $html);
        $this->assertStringContainsString('event.stopPropagation();', $html);
        $this->assertStringContainsString('Campaign Test is running. This page will stay open while PMTA responds.', $html);
        $this->assertStringContainsString('window.AlphaLinkToast?.show(message, accepted ?', $html);
        $this->assertStringNotContainsString('form.action = item.dataset.testUrl;', $html);
        $this->assertStringNotContainsString('HTMLFormElement.prototype.submit.call(form);', $html);
        $this->assertStringNotContainsString('onclick="event.preventDefault();', $html);
        $this->assertStringNotContainsString('campaign-manual-test-button', $html);
    }

    public function test_snapshot_migration_refuses_to_drop_existing_send_history(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace($owner, 'owner');
        $sponsor = $this->sponsor($workspace);
        $offer = $this->offer($workspace, $sponsor, ['suppression_path' => null]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Snapshot rollback guard',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => ['kind' => 'marketing'],
            'content_sha256' => hash('sha256', 'snapshot-rollback-guard'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'snapshot-rollback-guard-request'),
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);
        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_sha256' => str_repeat('a', 64),
            'selection' => [],
            'recipient_count' => 0,
            'recipients_sha256' => str_repeat('b', 64),
            'created_at' => now(),
        ]);
        $migration = require database_path('migrations/2026_10_24_031710_create_campaign_recipient_snapshots_tables.php');

        try {
            $migration->down();
            $this->fail('Expected the snapshot migration rollback to preserve campaign send history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Use a forward migration', $exception->getMessage());
        }

        $this->assertDatabaseHas('campaign_recipient_snapshots', ['id' => $snapshot->getKey()]);
        $this->assertTrue(Schema::hasTable('campaign_recipient_snapshot_items'));
    }

    private function workspace(User $owner, string $role): Workspace
    {
        $workspace = Workspace::query()->create([
            'name' => 'Send catalog workspace',
            'slug' => 'send-catalog-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => $role]);

        return $workspace;
    }

    private function offer(Workspace $workspace, WorkspaceSponsor $sponsor, array $overrides = []): WorkspaceOffer
    {
        $offer = WorkspaceOffer::query()->create(array_replace([
            'workspace_id' => $workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => (string) Str::uuid(),
            'name' => 'Synthetic send offer',
            'type' => 'cpa',
            'vertical' => 'insurance',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://example.test/offer',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => true,
            'suppression_path' => 'fresh-suppression.txt',
            'suppression_format' => 'email',
            'suppression_updated_at' => now()->toDateString(),
        ], $overrides));

        if (filled($offer->suppression_path)) {
            $content = "blocked@example.test\n";
            Storage::disk('local')->put($offer->suppression_path, $content);
            $offer->forceFill(['suppression_sha256' => hash('sha256', $content), 'suppression_format' => 'email'])->save();
        }

        return $offer;
    }

    /** @param list<int> $allowedUsers */
    private function sponsor(Workspace $workspace, array $allowedUsers = []): WorkspaceSponsor
    {
        return WorkspaceSponsor::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => 'Synthetic campaign sponsor',
            'affiliate_id' => (string) Str::uuid(),
            'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user',
            'password' => 'fixture-password',
            'api_key' => 'fixture-api-key',
            'type' => 'everflow',
            'allowed_users' => $allowedUsers,
            'is_active' => true,
        ]);
    }

    private function list(Workspace $workspace, User $owner, int $ispId, int $sponsorId, array $allowedUsers = []): AudienceList
    {
        $list = AudienceList::query()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $owner->getKey(),
            'name' => 'Synthetic campaign audience',
            'slug' => 'synthetic-campaign-audience',
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'synthetic-campaign-audience'),
            'isp_id' => $ispId,
            'country' => 'US',
            'allowed_users' => $allowedUsers,
            'allowed_sponsors' => [$sponsorId],
        ]);
        $email = 'synthetic-'.$list->getKey().'@example.test';
        $lookupKey = config('alphalink.contact_lookup_key');
        $contact = Contact::query()->create([
            'workspace_id' => $workspace->getKey(),
            'email' => $email,
            'email_lookup_hash' => is_string($lookupKey) && strlen($lookupKey) >= 32
                ? hash_hmac('sha256', $email, $lookupKey)
                : hash('sha256', $email),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        ContactListMembership::query()->create([
            'workspace_id' => $workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $list;
    }

    private function server(Workspace $workspace, User $owner, string $ipAddress): ManagedServer
    {
        return $workspace->managedServers()->create([
            'name' => 'SYNTHETIC SEND SERVER',
            'provider' => 'Fixture provider',
            'ip_address' => $ipAddress,
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $ipAddress),
            'created_by' => $owner->getKey(),
        ]);
    }
}
