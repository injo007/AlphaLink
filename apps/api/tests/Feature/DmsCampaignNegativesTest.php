<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCampaignNegative;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsCampaignNegativesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Negative file test',
            'slug' => 'negative-file-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_owner_can_upload_list_download_and_delete_a_negative_using_private_storage(): void
    {
        $content = "blocked@example.test\n";
        $this->post(route('console.campaign-negatives.store', $this->workspace->getKey()), [
            'name' => 'Suppression list',
            'file' => UploadedFile::fake()->createWithContent('blocked.txt', $content),
        ])->assertRedirect($this->cleanWorkspaceUrl(route('console.campaign-negatives.index', $this->workspace->getKey())))
            ->assertSessionHas('status');

        $negative = WorkspaceCampaignNegative::query()->sole();
        $this->assertSame(hash('sha256', $content), $negative->sha256);
        $this->assertSame(strlen($content), $negative->size_bytes);
        Storage::disk('local')->assertExists($negative->path);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $this->workspace->getKey(),
            'action' => 'campaign.negative.uploaded',
            'subject_id' => (string) $negative->getKey(),
        ]);

        $this->get(route('console.campaign-negatives.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('Suppression list')
            ->assertSee('blocked.txt')
            ->assertSee('NEGATIVES');
        $this->get(route('console.campaign-negatives.download', [$this->workspace->getKey(), $negative->getKey()]))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->delete(route('console.campaign-negatives.destroy', [$this->workspace->getKey(), $negative->getKey()]))
            ->assertRedirect($this->cleanWorkspaceUrl(route('console.campaign-negatives.index', $this->workspace->getKey())))
            ->assertSessionHas('status');
        $this->assertDatabaseMissing('workspace_campaign_negatives', ['id' => $negative->getKey()]);
        Storage::disk('local')->assertMissing($negative->path);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $this->workspace->getKey(),
            'action' => 'campaign.negative.deleted',
            'subject_id' => (string) $negative->getKey(),
        ]);
    }

    public function test_negative_upload_rejects_unsupported_file_types(): void
    {
        $this->from(route('console.campaign-negatives.create', $this->workspace->getKey()))
            ->post(route('console.campaign-negatives.store', $this->workspace->getKey()), [
                'name' => 'Unsupported fixture',
                'file' => UploadedFile::fake()->createWithContent('payload.exe', "not a negative file\n"),
            ])
            ->assertRedirect($this->cleanWorkspaceUrl(route('console.campaign-negatives.create', $this->workspace->getKey())))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('workspace_campaign_negatives', 0);
    }

    public function test_negative_files_are_owned_by_the_uploading_user_and_workspace(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::query()->create([
            'name' => 'Other negative workspace',
            'slug' => 'other-negative-'.Str::lower(Str::random(8)),
            'created_by' => $otherOwner->getKey(),
        ]);
        $otherWorkspace->users()->attach($otherOwner->getKey(), ['role' => 'owner']);
        $otherNegative = $otherWorkspace->campaignNegatives()->create([
            'created_by' => $otherOwner->getKey(),
            'name' => 'Private negative',
            'path' => 'campaign-negatives/private.txt',
            'original_filename' => 'private.txt',
            'sha256' => hash('sha256', 'private'),
            'size_bytes' => 7,
        ]);
        Storage::disk('local')->put($otherNegative->path, 'private');

        $this->get(route('console.campaign-negatives.download', [$this->workspace->getKey(), $otherNegative->getKey()]))
            ->assertNotFound();
        $this->delete(route('console.campaign-negatives.destroy', [$this->workspace->getKey(), $otherNegative->getKey()]))
            ->assertNotFound();
        Storage::disk('local')->assertExists($otherNegative->path);
    }

    public function test_uploaded_negative_is_offered_only_to_its_owner_and_is_saved_in_campaign_setup(): void
    {
        $negative = $this->workspace->campaignNegatives()->create([
            'created_by' => $this->owner->getKey(),
            'name' => 'My campaign negative',
            'path' => 'campaign-negatives/my-negative.txt',
            'original_filename' => 'my-negative.txt',
            'sha256' => hash('sha256', 'synthetic'),
            'size_bytes' => 9,
        ]);
        $anotherUser = User::factory()->create();
        $this->workspace->users()->attach($anotherUser->getKey(), ['role' => 'operator']);
        $this->workspace->campaignNegatives()->create([
            'created_by' => $anotherUser->getKey(),
            'name' => 'Another users negative',
            'path' => 'campaign-negatives/another-negative.txt',
            'original_filename' => 'another-negative.txt',
            'sha256' => hash('sha256', 'synthetic other'),
            'size_bytes' => 15,
        ]);

        $this->get(route('console.campaigns.create', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('campaign-negative')
            ->assertSee('My campaign negative')
            ->assertDontSee('Another users negative');

        $response = $this->getJson(route('console.campaigns.send-options', $this->workspace->getKey()));
        $response->assertOk()
            ->assertJsonPath('data.negatives.0.id', $negative->getKey())
            ->assertJsonPath('data.negatives.0.name', 'My campaign negative')
            ->assertJsonMissing(['name' => 'Another users negative']);
    }
}
