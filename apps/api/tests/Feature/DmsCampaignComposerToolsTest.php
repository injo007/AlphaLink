<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsCampaignComposerToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_encoding_tool_supports_dms_quoted_printable_hex_and_base64_schemes(): void
    {
        [$owner, $workspace] = $this->workspace();

        $base64 = $this->actingAs($owner)->postJson(route('console.campaign-tools.encode', $workspace->getKey()), [
            'string' => 'AlphaLink test', 'schema' => 'B', 'charset' => 'UTF-8',
        ])->assertOk()->json();
        $this->assertStringContainsString('?B?', $base64);

        $hex = $this->postJson(route('console.campaign-tools.encode', $workspace->getKey()), [
            'string' => 'AB', 'schema' => 'HQ', 'charset' => 'UTF-8',
        ])->assertOk()->json();
        $this->assertSame('=?UTF-8?Q?=41=42?=', $hex);

        $this->postJson(route('console.campaign-tools.encode', $workspace->getKey()), [
            'string' => 'plain', 'schema' => 'invalid', 'charset' => 'UTF-8',
        ])->assertUnprocessable()->assertJsonValidationErrors(['schema']);
    }

    public function test_shortlink_tools_match_the_dms_provider_requests_and_validate_web_urls(): void
    {
        [$owner, $workspace] = $this->workspace();
        Http::fake(['tinyurl.com/*' => Http::response('https://tiny.test/abc', 200)]);

        $this->actingAs($owner)->postJson(route('console.campaign-tools.shortlinks', [$workspace->getKey(), 'tinyurl']), [
            'links' => ['https://example.test/campaign?id=4'],
        ])->assertOk()->assertExactJson([[
            'link' => 'https://example.test/campaign?id=4',
            'shortlink' => 'https://tiny.test/abc',
        ]]);

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://tinyurl.com/api-create.php')
            && $request['url'] === 'https://example.test/campaign?id=4');

        $this->postJson(route('console.campaign-tools.shortlinks', [$workspace->getKey(), 'tinyurl']), [
            'links' => ['file:///etc/passwd'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['links.0']);
    }

    public function test_tag_helper_token_and_random_tag_generation_follow_dms_format(): void
    {
        [$owner, $workspace] = $this->workspace();
        $campaign = $workspace->campaigns()->create([
            'created_by' => $owner->getKey(),
            'name' => 'Synthetic campaign',
            'kind' => 'marketing',
            'status' => 'draft',
            'is_active' => true,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $revisionKey = (string) Str::uuid();
        $campaign->revisions()->create([
            'revision_number' => 1,
            'payload' => ['send_setup' => ['isp_id' => 42]],
            'content_sha256' => hash('sha256', 'synthetic campaign content'),
            'idempotency_key' => $revisionKey,
            'request_fingerprint' => hash('sha256', $revisionKey),
            'created_by' => $owner->getKey(),
            'created_at' => now(),
        ]);

        $response = $this->actingAs($owner)->postJson(route('console.campaigns.generate-body', [$workspace->getKey(), $campaign->getKey()]), [
            'body' => '<a href="[domain]/[token]">[rndn_8]</a>',
        ])->assertOk();
        $generated = $response->json('data');
        $this->assertStringNotContainsString('[token]', $generated);
        $this->assertMatchesRegularExpression('/<a href="\[domain\]\/[^\]]+">\d{8}<\/a>/', $generated);
        preg_match('/<a href="\[domain\]\/([^"\s]+)"/', $generated, $tokenMatch);
        $tokenParts = explode('-', (string) base64_decode($tokenMatch[1], true));
        $this->assertCount(14, $tokenParts);
        $this->assertSame(str_replace('-', '', (string) $campaign->getKey()), $tokenParts[0]);

        $this->actingAs(User::factory()->create())->postJson(route('console.campaigns.generate-body', [$workspace->getKey(), $campaign->getKey()]), [
            'body' => '[token]',
        ])->assertNotFound();
    }

    /** @return array{User, Workspace} */
    private function workspace(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Campaign composer test',
            'slug' => 'campaign-tools-'.Str::lower(Str::random(8)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return [$owner, $workspace];
    }
}
