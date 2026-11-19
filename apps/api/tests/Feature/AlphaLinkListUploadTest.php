<?php

namespace Tests\Feature;

use App\Models\AudienceList;
use App\Models\AudienceListImportBatch;
use App\Models\Contact;
use App\Models\ContactListMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audiences\AudienceMembershipFilterIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlphaLinkListUploadTest extends TestCase
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
            'name' => 'AlphaLink upload workspace',
            'slug' => 'alphalink-upload-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        RateLimiter::clear(md5('audience-list-import-uploadaudience-list-import-upload:global:'.$this->owner->getKey()));
    }

    public function test_upload_page_matches_the_reference_fields_and_uses_a_synthetic_example_file(): void
    {
        $list = $this->makeList('Upload list');

        $this->get($this->uploadUrl($list))
            ->assertOk()
            ->assertSee('Upload Emails')
            ->assertSee('EXAMPLE CSV')
            ->assertSee('Segment type')
            ->assertSee('fresh')
            ->assertSee('delivery')
            ->assertSee('Email keyword filter')
            ->assertSee('Create a second list')
            ->assertSee('Niche / vertical');

        $example = $this->get(route('clean.console.infrastructure.lists.import-example'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('sample.one@example.test', $example->streamedContent());
    }

    public function test_upload_can_create_a_named_copy_with_a_niche_applied_only_to_copy_memberships(): void
    {
        $list = $this->makeList('D4GOBE');
        $csv = "email,first_name,last_name,vertical\nalice@example.test,Alice,One,original\nbob@example.test,Bob,Two,original";

        $this->postJson($this->uploadUrl($list), [
            'file' => UploadedFile::fake()->createWithContent('audience.csv', $csv),
            'type' => 'fresh',
            'create_duplicate' => true,
            'duplicate_name' => 'D4GOBE Retail',
            'duplicate_niche' => 'retail',
        ])->assertAccepted()
            ->assertJsonPath('data.duplicate_list_name', 'D4GOBE Retail');

        $batch = AudienceListImportBatch::query()->sole();
        $copy = AudienceList::query()->findOrFail($batch->duplicate_audience_list_id);
        $this->assertSame('retail', $copy->niche);
        $this->assertSame($list->getKey(), $batch->audience_list_id);
        $this->assertSame('retail', $batch->duplicate_niche);
        $this->assertSame(4, ContactListMembership::query()->count());

        $alice = Contact::query()->where('email_lookup_hash', hash_hmac('sha256', 'alice@example.test', str_repeat('k', 32)))->sole();
        $sourceProfile = ContactListMembership::query()
            ->where('audience_list_id', $list->getKey())
            ->where('contact_id', $alice->getKey())
            ->sole()->profile;
        $copyProfile = ContactListMembership::query()
            ->where('audience_list_id', $copy->getKey())
            ->where('contact_id', $alice->getKey())
            ->sole()->profile;
        $this->assertSame('original', $sourceProfile['vertical']);
        $this->assertSame('retail', $copyProfile['vertical']);
        $this->assertSame('Alice', $copyProfile['first_name']);
        $this->assertSame(0, AudienceList::query()->where('segment_source_list_id', $list->getKey())->count());
    }

    public function test_duplicate_name_is_required_only_when_the_copy_option_is_enabled(): void
    {
        $list = $this->makeList('No copy list');

        $this->postJson($this->uploadUrl($list), [
            'file' => UploadedFile::fake()->createWithContent('audience.csv', "email\none@example.test"),
            'type' => 'fresh',
            'create_duplicate' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('duplicate_name');

        $this->assertDatabaseCount('audience_list_import_batches', 0);
        $this->assertDatabaseCount('audience_lists', 1);
    }

    public function test_profile_csv_import_preserves_encrypted_list_profiles_and_allows_cross_list_memberships(): void
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
            ->assertSessionHas('info')
            ->assertSessionHas('import_batch_id');

        $this->assertDatabaseCount('contacts', 2);
        $this->assertDatabaseCount('contact_list_memberships', 2);
        $this->assertDatabaseHas('audience_list_import_batches', [
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $firstList->getKey(),
            'source_format' => AudienceListImportBatch::FORMAT_PROFILE_CSV,
        ]);
        $this->assertSame(2, ContactListMembership::query()->where('audience_list_id', $firstList->getKey())->where('email_type', 'delivery')->count());
        $this->assertSame(0, AudienceList::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->where('segment_source_list_id', $firstList->getKey())
            ->count());
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
            'sex' => 'F',
            'vertical' => 'retail',
            'age' => 30,
        ], $alice->profile);
        $this->assertStringNotContainsString('Alice', (string) $alice->getRawOriginal('profile'));
        $firstListProfile = ContactListMembership::query()
            ->where('audience_list_id', $firstList->getKey())
            ->where('contact_id', $alice->getKey())
            ->sole();
        $this->assertSame($alice->profile, $firstListProfile->profile);
        $this->assertStringNotContainsString('Alice', (string) $firstListProfile->getRawOriginal('profile'));
        $expectedIndexes = app(AudienceMembershipFilterIndexer::class)->values(
            $this->workspace->getKey(),
            $firstList->getKey(),
            $firstListProfile->profile,
            $alice->email,
        );
        $this->assertSame($expectedIndexes['profile_sex_index'], $firstListProfile->profile_sex_index);
        $this->assertSame($expectedIndexes['email_domain_index'], $firstListProfile->email_domain_index);
        $this->assertSame(1, $firstListProfile->attribute_index_version);
        $this->assertStringNotContainsString('alice@example.test', json_encode($firstListProfile->getAttributes(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('F', (string) $firstListProfile->profile_sex_index);

        $this->from($this->uploadUrl($secondList))
            ->post($this->uploadUrl($secondList), [
                'file' => UploadedFile::fake()->createWithContent('duplicate.csv', "ALICE@example.test,Alice\nbob@example.test,Bob"),
                'type' => 'fresh',
            ])
            ->assertRedirect($this->uploadUrl($secondList))
            ->assertSessionHas('info')
            ->assertSessionHas('import_batch_id');

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

    public function test_legacy_memberships_can_be_backfilled_without_exposing_contact_attributes(): void
    {
        $list = $this->makeList('Legacy index list');
        $memberships = [];
        foreach (['legacy@example.test', 'second@example.test'] as $email) {
            $contact = Contact::query()->create([
                'workspace_id' => $this->workspace->getKey(),
                'email' => $email,
                'email_lookup_hash' => hash_hmac('sha256', $email, str_repeat('k', 32)),
                'lookup_key_version' => 1,
                'profile' => ['gender' => 'F'],
                'status' => 'active',
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $memberships[] = ContactListMembership::query()->create([
                'workspace_id' => $this->workspace->getKey(),
                'audience_list_id' => $list->getKey(),
                'contact_id' => $contact->getKey(),
                'email_type' => 'fresh',
                'status' => 'active',
                'joined_at' => now(),
                'profile' => null,
            ]);
        }

        $this->artisan('app:backfill-audience-mix-filter-indexes', ['--chunk' => '2'])
            ->expectsOutput('Processed 2 membership(s); backfilled 2; skipped 0 because the row changed or its contact is missing.')
            ->assertExitCode(0);

        foreach ($memberships as $membership) {
            $membership->refresh();
            $contact = $membership->contact;
            $this->assertSame(1, $membership->attribute_index_version);
            $this->assertSame(
                app(AudienceMembershipFilterIndexer::class)->sexIndex($this->workspace->getKey(), $list->getKey(), ['gender' => 'F']),
                $membership->profile_sex_index,
            );
            $this->assertSame(
                app(AudienceMembershipFilterIndexer::class)->domainIndex($this->workspace->getKey(), $list->getKey(), (string) $contact->email),
                $membership->email_domain_index,
            );
            $this->assertStringNotContainsString((string) $contact->email, json_encode($membership->getAttributes(), JSON_THROW_ON_ERROR));
        }
    }

    public function test_invalid_optional_age_is_skipped_and_valid_profile_rows_are_imported(): void
    {
        $list = $this->makeList('Atomic validation list');
        $csv = "Email,First Name,Last Name,Country,Age,Gender,Vertical,Company\n"
            ."good@example.test,Good,Age,US,32,F,retail,Acme\n"
            .'bad-age@example.test,Bad,Age,GB,not-a-number,M,travel,Globex';

        $this->from($this->uploadUrl($list))
            ->post($this->uploadUrl($list), [
                'file' => UploadedFile::fake()->createWithContent('profile.csv', $csv),
                'type' => 'fresh',
            ])
            ->assertRedirect($this->uploadUrl($list))
            ->assertSessionHas('import_batch_id');

        $batch = AudienceListImportBatch::query()->sole();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->imported_count);
        $this->assertSame(1, $batch->profile_warning_count);

        $goodContact = Contact::query()->where('email_lookup_hash', hash_hmac('sha256', 'good@example.test', str_repeat('k', 32)))->sole();
        $this->assertSame(32, $goodContact->profile['age']);
        $this->assertSame('Acme', $goodContact->profile['attributes']['Company']);
        $badAgeContact = Contact::query()->where('email_lookup_hash', hash_hmac('sha256', 'bad-age@example.test', str_repeat('k', 32)))->sole();
        $this->assertArrayNotHasKey('age', $badAgeContact->profile);
        $this->assertSame('Globex', $badAgeContact->profile['attributes']['Company']);
    }

    public function test_json_upload_returns_an_import_status_url_for_progress_polling(): void
    {
        $list = $this->makeList('Progress upload list');

        $response = $this->postJson($this->uploadUrl($list), [
            'file' => UploadedFile::fake()->createWithContent('progress.csv', "Email,First Name\nprogress@example.test,Progress"),
            'type' => 'fresh',
        ]);

        $response->assertAccepted()
            ->assertJsonStructure(['data' => ['id', 'status', 'progress_percent'], 'status_url']);
        $this->getJson($response->json('status_url'))
            ->assertOk()
            ->assertJsonPath('data.profile_warning_count', 0);
    }

    public function test_upload_allows_retries_beyond_the_previous_three_request_limit(): void
    {
        $list = $this->makeList('Upload retry list');

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->postJson($this->uploadUrl($list), [
                'file' => UploadedFile::fake()->createWithContent("retry-{$attempt}.csv", "retry{$attempt}@example.test,Retry,{$attempt}"),
                'type' => 'fresh',
            ])->assertAccepted();
        }

        $this->assertDatabaseCount('audience_list_import_batches', 4);
    }

    public function test_import_keeps_recipients_on_the_source_list_without_creating_projection_lists(): void
    {
        $list = $this->makeList('Projected source');
        $isp = $this->workspace->isps()->create([
            'name' => 'Tracked country ISP',
            'tracked_countries' => ['BE'],
            'allowed_users' => [],
            'allowed_sponsors' => [],
        ]);
        $list->forceFill(['isp_id' => $isp->getKey(), 'country' => 'BE'])->save();
        $this->from($this->uploadUrl($list))->post($this->uploadUrl($list), [
            'file' => UploadedFile::fake()->createWithContent('source.csv', 'source@example.test,Source,User,BE,28,F,retail'),
            'type' => 'fresh',
        ])->assertRedirect($this->uploadUrl($list));

        $this->assertDatabaseCount('audience_lists', 1);
        $this->assertDatabaseCount('contact_list_memberships', 1);
        $this->assertDatabaseHas('contact_list_memberships', [
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'email_type' => 'fresh',
            'status' => 'active',
        ]);
    }

    public function test_owner_can_preview_recipient_addresses_and_all_imported_tags(): void
    {
        $list = $this->makeList('Visible tagged list');
        $contact = Contact::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'email' => 'visible@example.test',
            'email_lookup_hash' => hash_hmac('sha256', 'visible@example.test', str_repeat('k', 32)),
            'lookup_key_version' => 1,
            'profile' => ['first_name' => 'Vis', 'last_name' => 'Able', 'attributes' => ['Company' => 'Example Co']],
            'status' => 'active',
            'idempotency_key' => (string) Str::uuid(),
        ]);
        ContactListMembership::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'contact_id' => $contact->getKey(),
            'email_type' => 'fresh',
            'status' => 'active',
            'joined_at' => now(),
            'profile' => ['country' => 'US'],
        ]);

        $this->get(route('console.audiences.show', [$this->workspace->getKey(), $list->getKey()]).'?q=Example&per_page=25')
            ->assertOk()
            ->assertSee('visible@example.test')
            ->assertSee('Vis')
            ->assertSee('Able')
            ->assertSee('Example Co')
            ->assertSee('country')
            ->assertSee('US')
            ->assertSee('Recipients')
            ->assertSee('Search this page')
            ->assertSee('Search, type filters, and sorting apply to recipients on this page.')
            ->assertSee('id="audience-recipient-type"', false)
            ->assertSee('data-sort-key="recipient"', false)
            ->assertSee('data-sort-key="type"', false)
            ->assertSee('data-sort-key="details"', false)
            ->assertSee('data-current="25"', false)
            ->assertSee('value="Example"', false)
            ->assertSee('data-search="visible@example.test fresh first_name Vis last_name Able country US Company Example Co"', false);
    }

    public function test_recipient_snapshot_page_size_keeps_the_active_list_paginated(): void
    {
        $list = $this->makeList('Paged recipient list');

        for ($index = 1; $index <= 26; $index++) {
            $email = sprintf('recipient-%02d@example.test', $index);
            $contact = Contact::query()->create([
                'workspace_id' => $this->workspace->getKey(),
                'email' => $email,
                'email_lookup_hash' => hash_hmac('sha256', $email, str_repeat('k', 32)),
                'lookup_key_version' => 1,
                'profile' => ['first_name' => 'Recipient '.$index],
                'status' => 'active',
                'idempotency_key' => (string) Str::uuid(),
            ]);

            ContactListMembership::query()->create([
                'workspace_id' => $this->workspace->getKey(),
                'audience_list_id' => $list->getKey(),
                'contact_id' => $contact->getKey(),
                'email_type' => 'fresh',
                'status' => 'active',
                'joined_at' => now(),
            ]);
        }

        $firstPage = $this->get(route('console.audiences.show', [$this->workspace->getKey(), $list->getKey()]).'?per_page=25')
            ->assertOk()
            ->assertSee('data-current="25"', false)
            ->assertSee('Page 1 of 2');
        $this->assertSame(25, substr_count($firstPage->getContent(), 'class="audience-recipient-row"'));

        $secondPage = $this->get(route('console.audiences.show', [$this->workspace->getKey(), $list->getKey()]).'?per_page=25&page=2')
            ->assertOk()
            ->assertSee('Page 2 of 2');
        $this->assertSame(1, substr_count($secondPage->getContent(), 'class="audience-recipient-row"'));
    }

    public function test_filter_value_zero_keeps_reference_php_truthiness_and_disables_filtering(): void
    {
        $list = $this->makeList('Zero filter list');

        $this->from($this->uploadUrl($list))
            ->post($this->uploadUrl($list), [
                'file' => UploadedFile::fake()->createWithContent('zero-filter.csv', "first@example.test,First\nsecond@example.test,Second"),
                'type' => 'fresh',
                'filter' => '0',
            ])
            ->assertRedirect($this->uploadUrl($list))
            ->assertSessionHas('import_batch_id');

        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_csv_email_field_is_trimmed_before_validation(): void
    {
        $list = $this->makeList('Whitespace list');

        $this->from($this->uploadUrl($list))
            ->post($this->uploadUrl($list), [
                'file' => UploadedFile::fake()->createWithContent('whitespace.csv', "invalid@example.test ,Invalid\nvalid@example.test,Valid"),
                'type' => 'fresh',
            ])
            ->assertRedirect($this->uploadUrl($list))
            ->assertSessionHas('import_batch_id');

        $this->assertDatabaseCount('contacts', 2);
        $batch = AudienceListImportBatch::query()->sole();
        $this->assertSame(2, $batch->valid_count);
        $this->assertSame(0, $batch->invalid_count);
        $this->assertDatabaseHas('contacts', [
            'email_lookup_hash' => hash_hmac('sha256', 'invalid@example.test', str_repeat('k', 32)),
        ]);
        $this->assertDatabaseHas('contacts', [
            'email_lookup_hash' => hash_hmac('sha256', 'valid@example.test', str_repeat('k', 32)),
        ]);
    }

    public function test_upload_above_two_megabytes_has_no_application_size_ceiling(): void
    {
        $list = $this->makeList('Large file list');
        $largeFile = UploadedFile::fake()->createWithContent('large.csv', str_repeat('x', 2_100_000));

        $this->from($this->uploadUrl($list))
            ->post($this->uploadUrl($list), [
                'file' => $largeFile,
                'type' => 'fresh',
            ])
            ->assertRedirect($this->uploadUrl($list))
            ->assertSessionHas('import_batch_id');

        $batch = AudienceListImportBatch::query()->sole();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(1, $batch->invalid_count);
        $this->assertSame(0, $batch->imported_count);
    }

    public function test_upload_is_scoped_to_workspace_managers_and_the_selected_list(): void
    {
        $list = $this->makeList('Scoped list');
        $operator = User::factory()->create(['access_role' => User::ACCESS_ROLE_OPERATOR]);
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

    public function test_legacy_profile_csv_batch_format_migrates_and_rolls_back_without_losing_progress(): void
    {
        $list = $this->makeList('Format migration list');
        $batchId = (string) Str::uuid();
        $timestamp = now();
        DB::table('audience_list_import_batches')->insert([
            'id' => $batchId,
            'workspace_id' => $this->workspace->getKey(),
            'audience_list_id' => $list->getKey(),
            'created_by' => $this->owner->getKey(),
            'status' => 'queued',
            'phase' => 'validation',
            'source_format' => 'dms_csv',
            'membership_type' => 'fresh',
            'storage_path' => 'private/audience-imports/profile.csv',
            'file_size_bytes' => 123,
            'validation_offset' => 61,
            'processed_count' => 7,
            'valid_count' => 5,
            'invalid_count' => 1,
            'filtered_count' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $migration = require database_path('migrations/2026_11_03_055018_normalize_audience_list_import_format.php');
        $migration->up();

        $this->assertDatabaseHas('audience_list_import_batches', [
            'id' => $batchId,
            'source_format' => AudienceListImportBatch::FORMAT_PROFILE_CSV,
            'phase' => 'validation',
            'validation_offset' => 61,
            'processed_count' => 7,
            'valid_count' => 5,
            'invalid_count' => 1,
            'filtered_count' => 1,
        ]);

        $migration->down();

        $this->assertDatabaseHas('audience_list_import_batches', [
            'id' => $batchId,
            'source_format' => 'dms_csv',
            'phase' => 'validation',
            'validation_offset' => 61,
            'processed_count' => 7,
            'valid_count' => 5,
            'invalid_count' => 1,
            'filtered_count' => 1,
        ]);
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
