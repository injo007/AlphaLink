<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsListUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'alphalink.contact_lookup_key' => str_repeat('k', 32),
            'alphalink.contact_lookup_key_version' => 1,
        ]);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'DMS upload workspace',
            'slug' => 'dms-upload-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
    }

    public function test_upload_page_matches_the_dms_fields_and_uses_a_synthetic_example_file(): void
    {
        $list = $this->makeList('Upload list');

        $this->get($this->uploadUrl($list))
            ->assertOk()
            ->assertSee('Upload Emails To List')
            ->assertSee('DOWNLOAD EXAMPLE')
            ->assertSee('Save To Type:')
            ->assertSee('fresh')
            ->assertSee('delivery')
            ->assertSee('Save Only Emails That Contain:');

        $example = $this->get(route('clean.console.infrastructure.lists.import-example'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('sample.one@example.test', $example->streamedContent());
    }

    public function test_dms_csv_import_preserves_encrypted_list_profiles_and_allows_cross_list_memberships(): void
    {
        $firstList = $this->makeList('First import list');
        $secondList = $this->makeList('Second import list');
        $csv = "ALICE@example.test,Alice,One,US,30,F,retail\nbad\nalice@example.test,Again,Duplicate,US,28,F,retail\nbob@example.test,Bob,Two,GB,,M,retail";

        $this->from($this->uploadUrl($firstList))
            ->post($this->uploadUrl($firstList), [
                'file' => UploadedFile::fake()->createWithContent('audience.csv', $csv),
                'type' => 'delivery',
                'filter' => '',
            ])
            ->assertRedirect($this->uploadUrl($firstList))
            ->assertSessionHas('info', 'processed: 4 - valid : 3 - filtered : 0');

        $this->assertDatabaseCount('contacts', 2);
        $this->assertDatabaseCount('contact_list_memberships', 2);
        $this->assertSame(2, ContactListMembership::query()->where('audience_list_id', $firstList->getKey())->where('email_type', 'delivery')->count());
        $this->assertDatabaseHas('audience_list_histories', [
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $firstList->getKey(),
            'fresh' => 0,
            'delivery' => 2,
        ]);
        $alice = Contact::query()->where('email_lookup_hash', hash_hmac('sha256', 'alice@example.test', str_repeat('k', 32)))->sole();
        $this->assertSame([
            'first_name' => 'Alice',
            'last_name' => 'One',
            'country' => 'US',
            'age' => 30,
            'sex' => 'F',
            'vertical' => 'retail',
        ], $alice->profile);
        $this->assertStringNotContainsString('Alice', (string) $alice->getRawOriginal('profile'));
        $firstListProfile = ContactListMembership::query()
            ->where('audience_list_id', $firstList->getKey())
            ->where('contact_id', $alice->getKey())
            ->sole();
        $this->assertSame($alice->profile, $firstListProfile->profile);
        $this->assertStringNotContainsString('Alice', (string) $firstListProfile->getRawOriginal('profile'));

        $this->from($this->uploadUrl($secondList))
            ->post($this->uploadUrl($secondList), [
                'file' => UploadedFile::fake()->createWithContent('duplicate.csv', "ALICE@example.test,Alice\nbob@example.test,Bob"),
                'type' => 'fresh',
            ])
            ->assertRedirect($this->uploadUrl($secondList))
            ->assertSessionHas('info', 'processed: 2 - valid : 2 - filtered : 0');

        $this->assertDatabaseCount('contact_list_memberships', 4);
        $this->assertDatabaseHas('contact_list_memberships', [
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $secondList->getKey(),
            'contact_id' => $alice->getKey(),
        ]);
        $secondListProfile = ContactListMembership::query()
            ->where('audience_list_id', $secondList->getKey())
            ->where('contact_id', $alice->getKey())
            ->sole();
        $this->assertSame(['first_name' => 'Alice'], $secondListProfile->profile);
        $this->assertSame('retail', $firstListProfile->profile['vertical']);
        $this->assertStringNotContainsString('retail', json_encode($secondListProfile->profile, JSON_THROW_ON_ERROR));
    }

    public function test_invalid_typed_profile_data_aborts_the_file_before_any_rows_are_written(): void
    {
        $list = $this->makeList('Atomic validation list');

        $this->from($this->uploadUrl($list))
            ->post($this->uploadUrl($list), [
                'file' => UploadedFile::fake()->createWithContent('bad-age.csv', "good@example.test,Good,Age,US,32\nbad@example.test,Bad,Age,US,not-a-number"),
                'type' => 'fresh',
            ])
            ->assertRedirect($this->uploadUrl($list))
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_list_memberships', 0);
    }

    public function test_filter_value_zero_keeps_dms_php_truthiness_and_disables_filtering(): void
    {
        $list = $this->makeList('Zero filter list');

        $this->from($this->uploadUrl($list))
            ->post($this->uploadUrl($list), [
                'file' => UploadedFile::fake()->createWithContent('zero-filter.csv', "first@example.test,First\nsecond@example.test,Second"),
                'type' => 'fresh',
                'filter' => '0',
            ])
            ->assertRedirect($this->uploadUrl($list))
            ->assertSessionHas('info', 'processed: 2 - valid : 2 - filtered : 0');

        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_csv_email_field_is_not_trimmed_before_validation_like_dms(): void
    {
        $list = $this->makeList('Whitespace list');

        $this->from($this->uploadUrl($list))
            ->post($this->uploadUrl($list), [
                'file' => UploadedFile::fake()->createWithContent('whitespace.csv', "invalid@example.test ,Invalid\nvalid@example.test,Valid"),
                'type' => 'fresh',
            ])
            ->assertRedirect($this->uploadUrl($list))
            ->assertSessionHas('info', 'processed: 2 - valid : 1 - filtered : 0');

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseHas('contacts', [
            'email_lookup_hash' => hash_hmac('sha256', 'valid@example.test', str_repeat('k', 32)),
        ]);
    }

    public function test_upload_is_scoped_to_workspace_managers_and_the_selected_list(): void
    {
        $list = $this->makeList('Scoped list');
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);

        $this->actingAs($operator)->get($this->uploadUrl($list))->assertForbidden();

        $otherWorkspace = Workspace::query()->create([
            'name' => 'Another upload workspace',
            'slug' => 'another-upload-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $otherWorkspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $foreignList = $otherWorkspace->audienceLists()->create([
            'created_by' => $this->owner->getKey(),
            'name' => 'Foreign list',
            'slug' => 'foreign-list',
            'description' => null,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'foreign-list'),
        ]);

        $this->actingAs($this->owner)
            ->withSession(['active_workspace_id' => $this->workspace->getKey()])
            ->get(route('clean.console.infrastructure.lists.upload', ['listId' => $foreignList->getKey()]))
            ->assertNotFound();
    }

    private function uploadUrl(AudienceList $list): string
    {
        return route('clean.console.infrastructure.lists.upload', ['listId' => $list->getKey()]);
    }

    private function makeList(string $name): AudienceList
    {
        return $this->workspace->audienceLists()->create([
            'created_by' => $this->owner->getKey(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', $name),
        ]);
    }
}
