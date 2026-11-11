<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignRevision;
use App\Models\Contact;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAppSettings;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\AlphaLinkAffiliateProviderOfferReader;
use App\Services\AffiliateNetworks\AlphaLinkAffiliateProviderSalesReader;
use App\Services\AffiliateNetworks\AlphaLinkEverFlowCreativeBundleImporter;
use App\Services\AffiliateNetworks\AlphaLinkSponsorLeadImporter;
use App\Services\AffiliateNetworks\AlphaLinkSponsorOfferRefresher;
use App\Services\Campaigns\AlphaLinkCampaignAffiliateAttribution;
use App\Services\Campaigns\AlphaLinkDropAffiliateAttribution;
use App\Services\Campaigns\CampaignIpGeoResolver;
use App\Services\Campaigns\TrackedCountryListManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class TelegramEventNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'access_role' => User::ACCESS_ROLE_OWNER,
            'email' => 'owner@example.test',
            'password' => 'SecretPass123!',
            'is_active' => true,
        ]);

        $this->workspace = Workspace::query()->create([
            'name' => 'Telegram Prod Workspace',
            'slug' => 'tg-prod-'.Str::lower(Str::random(6)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);

        WorkspaceAppSettings::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'settings' => [
                'app_name' => 'AlphaLink',
                'telegram_bot_name' => 'alphalink_bot',
                'telegram_bot_token' => '123456:TEST_BOT_TOKEN',
                'telegram_channel' => '-100111222333',
                'telegram_channel_it' => '',
                'allow_backup' => true,
            ],
        ]);
    }

    public function test_sign_in_sends_telegram_login_notification_with_custom_template(): void
    {
        Http::fake([
            'https://api.telegram.org/bot123456:TEST_BOT_TOKEN/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs($this->owner)
            ->postJson(route('console.app-settings.telegram.save', $this->workspace->getKey()), [
                'events' => [
                    'login' => [
                        'enabled' => true,
                        'target_channel' => 'main',
                        'custom_template' => 'LOGIN ALERT: {user_email} from {ip}',
                    ],
                ],
            ])
            ->assertOk();

        auth()->logout();

        $this->post('/sign-in', [
            'email' => 'owner@example.test',
            'password' => 'SecretPass123!',
        ])->assertRedirect();

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), 'api.telegram.org/bot123456:TEST_BOT_TOKEN/sendMessage')
                && $request['chat_id'] === '-100111222333'
                && str_contains($request['text'], 'LOGIN ALERT: owner@example.test from');
        });
    }

    public function test_login_notification_falls_back_to_it_channel_if_main_channel_is_blank(): void
    {
        $settings = $this->workspace->appSettings;
        $data = $settings->settings;
        $data['telegram_channel'] = '';
        $data['telegram_channel_it'] = '-100999888777';
        $settings->update(['settings' => $data]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->post('/sign-in', [
            'email' => 'owner@example.test',
            'password' => 'SecretPass123!',
        ])->assertRedirect();

        Http::assertSent(fn ($request): bool => $request['chat_id'] === '-100999888777');
    }

    public function test_backup_run_creates_archive_and_sends_telegram_notification(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs($this->owner)
            ->postJson(route('console.app-settings.backup.run', $this->workspace->getKey()))
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(function ($request): bool {
            return str_contains($request['text'], 'Backup Completed')
                && str_contains($request['text'], 'alphalink-backup-');
        });
    }

    public function test_offer_status_change_and_provider_pause_send_telegram_notifications(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $sponsor = $this->makeSponsor('Alpha Sponsor', 'manual');
        $offer = $this->makeOffer($sponsor, '4401', 'Finance Lead Offer');

        $this->actingAs($this->owner)
            ->patchJson(route('console.offers.status', [$this->workspace->getKey(), $offer->getKey()]), [
                'status' => 'paused',
            ])
            ->assertOk();

        Http::assertSent(function ($request): bool {
            return str_contains($request['text'], 'Offer Status Changed')
                && str_contains($request['text'], 'Finance Lead Offer')
                && str_contains($request['text'], 'live')
                && str_contains($request['text'], 'paused');
        });
    }

    public function test_provider_refresher_pause_triggers_offer_status_telegram_notification(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $sponsor = $this->makeSponsor('Cake Sponsor', 'cake');
        $offer = $this->makeOffer($sponsor, '7712', 'Auto Pause Offer');

        $offerReader = Mockery::mock(AlphaLinkAffiliateProviderOfferReader::class);
        $offerReader->shouldReceive('read')->once()->andReturn([]);
        $creativeImporter = Mockery::mock(AlphaLinkEverFlowCreativeBundleImporter::class);

        $refresher = new AlphaLinkSponsorOfferRefresher($offerReader, $creativeImporter);
        $result = $refresher->refresh($this->workspace, $offer, null);

        $this->assertSame('paused', $result['outcome']);
        Http::assertSent(fn ($request): bool => str_contains($request['text'], 'Auto Pause Offer') && str_contains($request['text'], 'paused'));
    }

    public function test_lead_import_sends_telegram_lead_notification(): void
    {
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $sponsor = $this->makeSponsor('EverFlow Partner', 'everflow');
        $offer = $this->makeOffer($sponsor, '9901', 'Credit Card Offer');
        $isp = Isp::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => 'Synthetic ISP',
            'is_active' => true,
            'allowed_users' => [],
            'allowed_sponsors' => [],
            'tracked_countries' => [],
            'quality_emails' => [],
        ]);
        $list = AudienceList::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->owner->getKey(),
            'name' => 'Lead Audience',
            'slug' => 'lead-audience',
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'lead-list'),
            'isp_id' => $isp->getKey(),
        ]);
        $contact = Contact::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'email' => 'lead-user@example.test',
            'email_lookup_hash' => hash('sha256', 'lead-user@example.test'),
            'lookup_key_version' => 1,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $campaign = Campaign::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'created_by' => $this->owner->getKey(),
            'name' => 'High Intent Campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revision = CampaignRevision::query()->create([
            'campaign_id' => $campaign->getKey(),
            'revision_number' => 1,
            'payload' => ['send_setup' => []],
            'content_sha256' => hash('sha256', 'rev'),
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'req'),
            'created_by' => $this->owner->getKey(),
            'created_at' => now(),
        ]);
        $snapshot = CampaignRecipientSnapshot::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(),
            'offer_id' => $offer->getKey(),
            'suppression_sha256' => hash('sha256', 'sup'),
            'selection' => ['audience_segments' => []],
            'recipient_count' => 1,
            'recipients_sha256' => hash('sha256', (string) $contact->getKey()),
            'created_at' => now(),
        ]);
        $item = CampaignRecipientSnapshotItem::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'snapshot_id' => $snapshot->getKey(),
            'contact_id' => $contact->getKey(),
            'position' => 1,
            'email_lookup_hash' => $contact->email_lookup_hash,
            'source_list_id' => $list->getKey(),
            'source_email_type' => 'fresh',
            'created_at' => now(),
        ]);

        $salesReader = Mockery::mock(AlphaLinkAffiliateProviderSalesReader::class);
        $salesReader->shouldReceive('read')->once()->andReturn([[
            'conversion_id' => 'CONV-8812',
            'id' => 'C_0001',
            'earning' => 52.50,
            'currency_code' => 'USD',
            'created_at' => now(),
        ]]);

        $attribution = Mockery::mock(AlphaLinkCampaignAffiliateAttribution::class);
        $attribution->shouldReceive('resolve')->once()->andReturn([
            'workspace_id' => $this->workspace->getKey(),
            'campaign_id' => $campaign->getKey(),
            'revision_id' => $revision->getKey(),
            'recipient_snapshot_id' => $snapshot->getKey(),
            'snapshot_item_id' => $item->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offer->getKey(),
            'isp_id' => $isp->getKey(),
            'provider_type' => $sponsor->type,
            'provider_conversion_id' => 'CONV-8812',
            'conversion_ip' => '198.51.100.77',
            'revenue' => '52.5000',
            'converted_at' => now(),
        ]);

        $geoResolver = Mockery::mock(CampaignIpGeoResolver::class);
        $geoResolver->shouldReceive('resolve')->once()->andReturn(['country' => 'United States', 'code' => 'US', 'state' => null, 'city' => null]);

        $membershipManager = Mockery::mock(TrackedCountryListManager::class);
        $membershipManager->shouldReceive('currentMembership')->once()->andReturn(null);

        $importer = new AlphaLinkSponsorLeadImporter(
            $salesReader,
            $attribution,
            Mockery::mock(AlphaLinkDropAffiliateAttribution::class),
            $geoResolver,
            $membershipManager,
        );

        $result = $importer->import($sponsor, now()->subDay(), now());

        $this->assertSame(1, $result['created']);
        Http::assertSent(function ($request): bool {
            return str_contains($request['text'], 'New Lead Recorded')
                && str_contains($request['text'], 'EverFlow Partner')
                && str_contains($request['text'], '52.50')
                && str_contains($request['text'], 'CONV-8812');
        });
    }

    public function test_settings_and_redesigned_app_health_pages_render_cleanly(): void
    {
        $this->actingAs($this->owner)
            ->get(route('console.app-settings.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('TELEGRAM EVENT NOTIFICATIONS')
            ->assertSee('User Login Alerts')
            ->assertSee('New Lead Conversions')
            ->assertSee('Workspace Backup Events')
            ->assertSee('Offer Status Changes')
            ->assertSee('RUN BACKUP NOW');

        $this->actingAs($this->owner)
            ->get(route('console.miscellaneous.app-health', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('APP HEALTH MONITOR')
            ->assertSee('DIAGNOSTIC PROBE LEDGER')
            ->assertSee('CHECK')
            ->assertSee('Summary')
            ->assertSee('Message')
            ->assertSee('RUN PROBES');
    }

    private function makeSponsor(string $name, string $type): WorkspaceSponsor
    {
        return WorkspaceSponsor::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'name' => $name,
            'affiliate_id' => '99',
            'url' => 'https://affiliate.example.test',
            'username' => 'fixture-user',
            'password' => 'fixture-password',
            'api_key' => 'fixture-api-key',
            'type' => $type,
            'allowed_users' => [],
            'is_active' => true,
        ]);
    }

    private function makeOffer(WorkspaceSponsor $sponsor, string $offerId, string $name): WorkspaceOffer
    {
        return WorkspaceOffer::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => $offerId,
            'name' => $name,
            'type' => 'CPA',
            'vertical' => 'finance',
            'geo_target' => ['US'],
            'payout' => '35.00',
            'redirect' => 'https://example.test/offer',
            'allowed_days' => WorkspaceOffer::ALLOWED_DAYS,
            'status' => 'live',
            'allowed_users' => [],
            'is_active' => false,
        ]);
    }
}
