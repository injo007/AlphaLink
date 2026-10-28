<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceOfferAsset;
use App\Models\WorkspaceSponsor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsOfferAssetUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private WorkspaceSponsor $sponsor;

    private WorkspaceOffer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Offer assets test',
            'slug' => 'offer-assets-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->sponsor = $this->workspace->sponsors()->create([
            'name' => 'Synthetic network',
            'url' => 'https://network.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-api-key',
            'type' => 'everflow',
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $this->offer = $this->workspace->offers()->create([
            'sponsor_id' => $this->sponsor->getKey(),
            'offer_id' => 'synthetic-offer-1',
            'name' => 'Synthetic offer',
            'type' => 'cpa',
            'vertical' => 'test',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://offer.example.test/track',
            'unsubscribe' => 'https://offer.example.test/unsubscribe',
            'allowed_days' => ['Mon'],
            'allowed_users' => [],
            'status' => 'live',
            'is_active' => true,
        ]);
    }

    public function test_assets_page_matches_dms_tabs_and_exposes_creative_and_unsubscribe_upload_forms(): void
    {
        $this->get(route('console.offers.assets', [$this->workspace->getKey(), $this->offer->getKey()]))
            ->assertOk()
            ->assertSee('FROM')
            ->assertSee('SUBJECTS')
            ->assertSee('CREATIVES')
            ->assertSee('UNSUBSCRIBE')
            ->assertSee('HTML')
            ->assertSee($this->cleanWorkspaceUrl(route('console.offers.assets.upload-form', [$this->workspace->getKey(), $this->offer->getKey(), 'creative'])))
            ->assertSee($this->cleanWorkspaceUrl(route('console.offers.assets.upload-form', [$this->workspace->getKey(), $this->offer->getKey(), 'unsubscribe'])));

        $this->get(route('console.offers.assets.upload-form', [$this->workspace->getKey(), $this->offer->getKey(), 'creative']))
            ->assertOk()->assertSee('Maximum 20 images')->assertSee('image/jpeg,image/png,image/gif');
        $this->get(route('console.offers.assets.upload-form', [$this->workspace->getKey(), $this->offer->getKey(), 'unsubscribe']))
            ->assertOk()->assertSee('Uploading replaces the previous unsubscribe image');
    }

    public function test_owner_can_upload_and_preview_private_creative_images(): void
    {
        $response = $this->post(route('console.offers.assets.upload', [$this->workspace->getKey(), $this->offer->getKey(), 'creative']), [
            'files' => [
                $this->fakeImage('creative-one.png'),
                $this->fakeImage('creative-two.png'),
            ],
        ]);

        $response->assertOk()->assertJsonPath('data', 'Added successfully')->assertJsonPath('count', 2);
        $assets = $this->offer->assets()->where('type', 'creative')->orderBy('id')->get();
        $this->assertCount(2, $assets);
        foreach ($assets as $asset) {
            $this->assertStringStartsWith(sprintf('offers/%s/%d/assets/creative/', $this->workspace->getKey(), $this->offer->getKey()), $asset->value);
            Storage::disk('local')->assertExists($asset->value);
        }

        $listing = $this->getJson(route('console.offers.assets', [$this->workspace->getKey(), $this->offer->getKey()]).'?type=creatives');
        $listing->assertOk()->assertJsonPath('count', 2)->assertJsonPath('data.0.type', 'creative');
        $imageUrl = $listing->json('data.0.file_url');
        auth()->logout();
        $image = $this->get($imageUrl)->assertOk()->assertHeader('content-type', 'image/png');
        $this->assertStringContainsString('public', (string) $image->headers->get('cache-control'));
    }

    public function test_unsubscribe_upload_replaces_the_previous_image_and_file(): void
    {
        $url = route('console.offers.assets.upload', [$this->workspace->getKey(), $this->offer->getKey(), 'unsubscribe']);
        $this->post($url, ['files' => [$this->fakeImage('old.png')]])->assertOk();
        $oldAsset = $this->offer->assets()->where('type', 'unsubscribe')->sole();
        Storage::disk('local')->assertExists($oldAsset->value);

        $this->post($url, ['files' => [$this->fakeImage('new.gif')]])
            ->assertOk()->assertJsonPath('count', 1);

        $newAsset = $this->offer->assets()->where('type', 'unsubscribe')->sole();
        $this->assertNotSame($oldAsset->value, $newAsset->value);
        Storage::disk('local')->assertMissing($oldAsset->value);
        Storage::disk('local')->assertExists($newAsset->value);
        $this->get(route('console.offers.assets.file', [$this->workspace->getKey(), $this->offer->getKey(), $newAsset->getKey()]))
            ->assertOk()->assertHeader('content-type', 'image/gif');
    }

    public function test_html_assets_are_listed_as_source_text_and_preview_in_a_sandboxed_frame(): void
    {
        $html = '<table><tr><td>Offer preview</td></tr></table>';
        $asset = $this->offer->assets()->create([
            'workspace_id' => $this->workspace->getKey(),
            'type' => 'html',
            'value' => $html,
            'created_by' => $this->owner->getKey(),
        ]);

        $this->getJson(route('console.offers.assets', [$this->workspace->getKey(), $this->offer->getKey()]).'?type=html')
            ->assertOk()->assertJsonPath('count', 1)->assertJsonPath('data.0.value', $html);
        $this->get(route('console.offers.assets', [$this->workspace->getKey(), $this->offer->getKey()]))
            ->assertOk()->assertSee('html-preview', false)->assertSee('allow-forms allow-popups', false);

        $this->patchJson(route('console.offers.assets.status', [$this->workspace->getKey(), $asset->getKey()]), ['status' => false])
            ->assertOk()->assertExactJson(['data' => 'Changed successfully']);
        $this->assertFalse($asset->fresh()->is_active);
    }

    public function test_bulk_asset_status_matches_dms_and_never_changes_unsubscribe_assets(): void
    {
        $from = $this->offer->assets()->create([
            'workspace_id' => $this->workspace->getKey(),
            'type' => 'from',
            'value' => 'Synthetic sender',
            'created_by' => $this->owner->getKey(),
        ]);
        $creative = $this->offer->assets()->create([
            'workspace_id' => $this->workspace->getKey(),
            'type' => 'creative',
            'value' => 'offers/'.$this->workspace->getKey().'/'.$this->offer->getKey().'/assets/creative/asset.png',
            'created_by' => $this->owner->getKey(),
        ]);
        $unsubscribe = $this->offer->assets()->create([
            'workspace_id' => $this->workspace->getKey(),
            'type' => 'unsubscribe',
            'value' => 'offers/'.$this->workspace->getKey().'/'.$this->offer->getKey().'/assets/unsubscribe/unsubscribe.png',
            'created_by' => $this->owner->getKey(),
        ]);

        $this->patchJson(route('console.offers.assets.bulk-status', [$this->workspace->getKey(), $this->offer->getKey()]), [
            'ids' => [$from->getKey(), $creative->getKey(), $unsubscribe->getKey()],
            'status' => false,
        ])->assertOk()->assertExactJson(['data' => 'Changed successfully', 'updated' => 2]);

        $this->assertFalse($from->fresh()->is_active);
        $this->assertFalse($creative->fresh()->is_active);
        $this->assertTrue($unsubscribe->fresh()->is_active);
    }

    public function test_bulk_asset_status_is_limited_to_assets_of_the_selected_workspace_offer(): void
    {
        $otherOffer = $this->workspace->offers()->create([
            'sponsor_id' => $this->sponsor->getKey(),
            'offer_id' => 'synthetic-offer-2',
            'name' => 'Other synthetic offer',
            'type' => 'cpa',
            'vertical' => 'test',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://offer.example.test/other',
            'unsubscribe' => 'https://offer.example.test/other-unsubscribe',
            'allowed_days' => ['Mon'],
            'allowed_users' => [],
            'status' => 'live',
            'is_active' => true,
        ]);
        $foreignOfferAsset = $otherOffer->assets()->create([
            'workspace_id' => $this->workspace->getKey(),
            'type' => 'from',
            'value' => 'Other offer sender',
            'created_by' => $this->owner->getKey(),
        ]);

        $this->patchJson(route('console.offers.assets.bulk-status', [$this->workspace->getKey(), $this->offer->getKey()]), [
            'ids' => [$foreignOfferAsset->getKey()],
            'status' => false,
        ])->assertNotFound();

        $this->assertTrue($foreignOfferAsset->fresh()->is_active);
    }

    public function test_offer_asset_table_has_dms_bulk_action_toolbar_and_only_shows_selection_on_supported_tabs(): void
    {
        $this->get(route('console.offers.assets', [$this->workspace->getKey(), $this->offer->getKey()]))
            ->assertOk()
            ->assertSee('DISABLE SELECTED ASSETS')
            ->assertSee('ENABLE SELECTED ASSETS')
            ->assertSee('select-checkbox')
            ->assertSee($this->cleanWorkspaceUrl(route('console.offers.assets.bulk-status', [$this->workspace->getKey(), $this->offer->getKey()])));
    }

    public function test_invalid_uploads_and_multiple_unsubscribe_images_are_rejected_without_creating_assets(): void
    {
        $url = route('console.offers.assets.upload', [$this->workspace->getKey(), $this->offer->getKey(), 'creative']);
        $this->postJson($url, ['files' => [UploadedFile::fake()->create('not-an-image.txt', 1, 'text/plain')]])
            ->assertUnprocessable()->assertJsonValidationErrors('files.0');

        $unsubscribeUrl = route('console.offers.assets.upload', [$this->workspace->getKey(), $this->offer->getKey(), 'unsubscribe']);
        $this->postJson($unsubscribeUrl, ['files' => [
            $this->fakeImage('one.png'),
            $this->fakeImage('two.png'),
        ]])->assertUnprocessable()->assertJsonValidationErrors('files');
        $this->assertSame(0, $this->offer->assets()->count());
    }

    public function test_operators_can_preview_only_active_assets_on_assigned_active_offers(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->sponsor->forceFill(['allowed_users' => [(int) $operator->getKey()]])->save();
        $this->offer->forceFill(['allowed_users' => [(int) $operator->getKey()]])->save();
        $path = 'offers/'.$this->workspace->getKey().'/'.$this->offer->getKey().'/assets/creative/creative-test.png';
        Storage::disk('local')->put($path, $this->fakeImage('visible.png')->get());
        $asset = $this->offer->assets()->create([
            'workspace_id' => $this->workspace->getKey(),
            'type' => 'creative',
            'value' => $path,
            'created_by' => $this->owner->getKey(),
        ]);
        $route = route('console.offers.assets.file', [$this->workspace->getKey(), $this->offer->getKey(), $asset->getKey()]);

        $this->actingAs($operator)->get($route)->assertOk();
        $asset->forceFill(['is_active' => false])->save();
        $this->actingAs($operator)->get($route)->assertNotFound();
        $this->actingAs($operator)->post(route('console.offers.assets.upload', [$this->workspace->getKey(), $this->offer->getKey(), 'creative']), [
            'files' => [$this->fakeImage('blocked.png')],
        ])->assertForbidden();
    }

    public function test_another_workspace_cannot_preview_the_file(): void
    {
        $path = 'offers/'.$this->workspace->getKey().'/'.$this->offer->getKey().'/assets/creative/creative-test.png';
        Storage::disk('local')->put($path, $this->fakeImage('private.png')->get());
        $asset = WorkspaceOfferAsset::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'offer_id' => $this->offer->getKey(),
            'type' => 'creative',
            'value' => $path,
            'created_by' => $this->owner->getKey(),
        ]);
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other assets workspace',
            'slug' => 'other-assets-'.Str::lower(Str::random(8)),
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherWorkspace->users()->attach($otherOwner->getKey(), ['role' => 'owner']);

        $this->actingAs($otherOwner)->get(route('console.offers.assets.file', [
            $otherWorkspace->getKey(), $this->offer->getKey(), $asset->getKey(),
        ]))->assertNotFound();
    }

    private function fakeImage(string $filename): UploadedFile
    {
        $content = match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'gif' => base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true),
            default => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/Z9sAAAAASUVORK5CYII=', true),
        };

        if (! is_string($content)) {
            throw new \RuntimeException('The static image fixture is invalid.');
        }

        return UploadedFile::fake()->createWithContent($filename, $content);
    }
}
