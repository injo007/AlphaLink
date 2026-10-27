<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateProviderHttp;
use App\Services\AffiliateNetworks\DmsSponsorExtensionPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class DmsSponsorLoginTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Http::preventStrayRequests();
        $this->bindPublicProviderResolver();

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Synthetic Sponsor Login',
            'slug' => 'synthetic-sponsor-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
    }

    public function test_cake_login_matches_dms_form_cookie_handoff_and_three_hour_session_cache(): void
    {
        $sponsor = $this->createSponsor('cake');
        $cacheKey = 'sponsor_session:'.$this->workspace->getKey().':'.$sponsor->getKey();
        Cache::forget($cacheKey);
        $this->fakeSuccessfulLogin('Log In', '/affiliates/', '/session', 'cake_session=synthetic-cake-cookie; Path=/affiliates; HttpOnly; Secure');

        $url = route('console.sponsors.login', [$this->workspace->getKey(), $sponsor->getKey()]);
        $first = $this->postJson($url)->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('message', 'Session Created')
            ->assertJsonPath('url', 'https://network.example.test/affiliates/')
            ->assertJsonPath('redirect_to', '')
            ->assertJsonPath('session.0.name', 'dms_prelogin')
            ->assertJsonPath('session.1.name', 'cake_session');

        $this->assertSame('synthetic-cake-cookie', $first->json('session.1.value'));
        $requests = Http::recorded()->map(fn (array $record): HttpRequest => $record[0])->all();
        $this->assertCount(3, $requests);
        $this->assertSame('GET', $requests[0]->method());
        $this->assertSame('https://network.example.test/', $requests[0]->url());
        $this->assertSame('POST', $requests[1]->method());
        $this->assertSame('https://network.example.test/session', $requests[1]->url());
        parse_str($requests[1]->body(), $formData);
        $this->assertSame('synthetic-user', $formData['u']);
        $this->assertSame('synthetic-password', $formData['p']);
        $this->assertSame('synthetic-csrf', $formData['_token']);
        $this->assertArrayNotHasKey('api_key', $formData);
        $this->assertStringContainsString('dms_prelogin=seed', implode('; ', (array) $requests[1]->header('Cookie')));
        $this->assertSame('GET', $requests[2]->method());
        $this->assertSame('https://network.example.test/affiliates/', $requests[2]->url());
        $this->assertStringContainsString('cake_session=synthetic-cake-cookie', implode('; ', (array) $requests[2]->header('Cookie')));

        $encryptedCacheValue = Cache::get($cacheKey);
        $this->assertIsString($encryptedCacheValue);
        $this->assertStringNotContainsString('synthetic-cake-cookie', $encryptedCacheValue);

        $this->postJson($url)->assertOk()->assertExactJson($first->json());
        $this->assertCount(3, Http::recorded());
        $audits = AuditEvent::query()->where('action', 'sponsor.login_succeeded')->get();
        $this->assertCount(2, $audits);
        foreach ($audits as $audit) {
            $this->assertSame(['type' => 'cake'], $audit->properties);
            $this->assertStringNotContainsString('synthetic-cake-cookie', $audit->toJson());
        }
    }

    public function test_hasoffers_login_uses_its_dms_field_names_and_publisher_success_rule(): void
    {
        $sponsor = $this->createSponsor('hasoffers');
        $this->fakeSuccessfulLogin('Login', '/publisher/home', '/authenticate', 'hasoffers_session=synthetic-hasoffers-cookie; Path=/; HttpOnly');

        $this->postJson(route('console.sponsors.login', [$this->workspace->getKey(), $sponsor->getKey()]))
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('url', 'https://network.example.test/');

        $requests = Http::recorded()->map(fn (array $record): HttpRequest => $record[0])->all();
        parse_str($requests[1]->body(), $formData);
        $this->assertSame('synthetic-user', $formData['data']['User']['email']);
        $this->assertSame('synthetic-password', $formData['data']['User']['password']);
        $this->assertSame('https://network.example.test/publisher/home', $requests[2]->url());
    }

    public function test_newhitpath_login_uses_login_form_and_returns_cookie_expiration_as_date(): void
    {
        $sponsor = $this->createSponsor('newhitpath');
        $expiresAt = now()->addHour()->timestamp;
        $expiresHeader = gmdate('D, d M Y H:i:s', $expiresAt).' GMT';
        $this->fakeSuccessfulLogin('Login', '/dashboard', '/login', 'newhitpath_session=synthetic-newhitpath-cookie; Path=/; HttpOnly; Expires='.$expiresHeader);

        $this->postJson(route('console.sponsors.login', [$this->workspace->getKey(), $sponsor->getKey()]))
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('url', 'https://network.example.test/')
            ->assertJsonPath('session.1.name', 'newhitpath_session')
            ->assertJsonPath('session.1.date', $expiresAt);

        $requests = Http::recorded()->map(fn (array $record): HttpRequest => $record[0])->all();
        $this->assertSame('https://network.example.test/login', $requests[0]->url());
        parse_str($requests[1]->body(), $formData);
        $this->assertSame('synthetic-user', $formData['login']);
        $this->assertSame('synthetic-password', $formData['password']);
        $this->assertSame('https://network.example.test/dashboard', $requests[2]->url());
    }

    public function test_provider_failures_do_not_return_cookies_or_log_secrets(): void
    {
        $sponsor = $this->createSponsor('cake');
        Http::fakeSequence()
            ->push($this->loginForm('Log In', '/session'), 200)
            ->push('', 302, ['Location' => '/login', 'Set-Cookie' => ['dms_session=must-not-escape; Path=/; HttpOnly']])
            ->push($this->loginForm('Log In', '/session'), 200);

        $response = $this->postJson(route('console.sponsors.login', [$this->workspace->getKey(), $sponsor->getKey()]))
            ->assertOk()
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Invalid username or password')
            ->assertJsonMissingPath('session');

        $this->assertStringNotContainsString('synthetic-password', $response->getContent());
        $this->assertStringNotContainsString('synthetic-api-key', $response->getContent());
        $audit = AuditEvent::query()->sole();
        $this->assertSame('sponsor.login_failed', $audit->action);
        $this->assertSame(['type' => 'cake'], $audit->properties);
        $this->assertStringNotContainsString('must-not-escape', $audit->toJson());
    }

    public function test_provider_redirects_must_stay_on_the_configured_https_origin(): void
    {
        $sponsor = $this->createSponsor('cake');
        Http::fakeSequence()
            ->push($this->loginForm('Log In', '/session'), 200)
            ->push('', 302, ['Location' => 'https://attacker.example.test/collect']);

        $this->postJson(route('console.sponsors.login', [$this->workspace->getKey(), $sponsor->getKey()]))
            ->assertOk()
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Affiliate login redirects must stay on the configured HTTPS provider host.');

        $this->assertCount(2, Http::recorded());
        Http::assertSent(fn (HttpRequest $request): bool => ! str_contains($request->url(), 'attacker.example.test'));
    }

    public function test_private_provider_dns_is_rejected_before_an_http_request(): void
    {
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['127.0.0.1']),
        );
        $sponsor = $this->createSponsor('cake');

        $this->postJson(route('console.sponsors.login', [$this->workspace->getKey(), $sponsor->getKey()]))
            ->assertOk()
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'The affiliate provider host resolved to a private or reserved address.');

        $this->assertCount(0, Http::recorded());
    }

    public function test_operator_can_log_in_only_to_an_active_assigned_sponsor(): void
    {
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $assigned = $this->createSponsor('cake', ['allowed_users' => [(int) $operator->getKey()]]);
        $unassigned = $this->createSponsor('cake');

        $this->actingAs($operator)
            ->postJson(route('console.sponsors.login', [$this->workspace->getKey(), $unassigned->getKey()]))
            ->assertNotFound();
        $this->assertCount(0, Http::recorded());

        $assigned->forceFill(['is_active' => false])->save();
        $this->postJson(route('console.sponsors.login', [$this->workspace->getKey(), $assigned->getKey()]))
            ->assertNotFound();
        $this->assertCount(0, Http::recorded());

        $assigned->forceFill(['is_active' => true])->save();
        $this->fakeSuccessfulLogin('Log In', '/affiliates/', '/session', 'cake_session=operator-cookie; Path=/; HttpOnly');
        $this->postJson(route('console.sponsors.login', [$this->workspace->getKey(), $assigned->getKey()]))
            ->assertOk()
            ->assertJsonPath('status', true);
        $this->assertCount(3, Http::recorded());
    }

    public function test_dms_deprecated_and_browser_automated_providers_fail_explicitly_without_network_access(): void
    {
        $hitpath = $this->createSponsor('hitpath');
        $everflow = $this->createSponsor('everflow');

        $this->postJson(route('console.sponsors.login', [$this->workspace->getKey(), $hitpath->getKey()]))
            ->assertOk()
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'HITPATH DEPRECATED');
        $this->postJson(route('console.sponsors.login', [$this->workspace->getKey(), $everflow->getKey()]))
            ->assertOk()
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'EverFlow login requires the browser automation used by DMS, which is not installed in AlphaLink.');

        $this->assertCount(0, Http::recorded());
    }

    public function test_sponsor_helper_archive_scopes_its_origin_and_preserves_the_reviewed_permissions(): void
    {
        $sourcePath = storage_path('app/dms-reference/sponsor-extension/extension_tools.zip');
        $manifestPath = storage_path('app/dms-reference/sponsor-extension/extension_manifest.txt');
        $sourceDigest = hash_file('sha256', $sourcePath);
        $template = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $archivePath = app(DmsSponsorExtensionPackage::class)->createForCurrentOrigin();
        $archive = new ZipArchive;

        try {
            $this->assertSame(true, $archive->open($archivePath));
            $manifest = json_decode((string) $archive->getFromName('extension/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $appUrl = parse_url((string) config('app.url'));
            $expectedOrigin = strtolower((string) $appUrl['scheme']).'://'.strtolower((string) $appUrl['host']).'/*';

            $this->assertSame([$expectedOrigin], $manifest['externally_connectable']['matches']);
            $this->assertSame($template['permissions'], $manifest['permissions']);
            $this->assertSame($template['host_permissions'], $manifest['host_permissions']);
            $this->assertContains('cookies', $manifest['permissions']);
            $this->assertSame(['http://*/*', 'https://*/*'], $manifest['host_permissions']);
            $this->assertMatchesRegularExpression('/^[a-p]{32}$/', app(DmsSponsorExtensionPackage::class)->extensionId());
            $archive->close();
        } finally {
            if (is_file($archivePath)) {
                unlink($archivePath);
            }
        }

        $this->assertSame($sourceDigest, hash_file('sha256', $sourcePath));
    }

    private function fakeSuccessfulLogin(string $button, string $redirect, string $action, string $sessionCookie): void
    {
        Http::fakeSequence()
            ->push($this->loginForm($button, $action), 200, ['Set-Cookie' => ['dms_prelogin=seed; Path=/; HttpOnly; Secure']])
            ->push('', 302, ['Location' => $redirect, 'Set-Cookie' => [$sessionCookie]])
            ->push('<html><body>Authenticated provider area</body></html>', 200);
    }

    private function loginForm(string $button, string $action): string
    {
        return '<!doctype html><html><body><form method="post" action="'.$action.'">'
            .'<input type="hidden" name="_token" value="synthetic-csrf">'
            .'<button type="submit" name="submit" value="'.$button.'">'.$button.'</button>'
            .'</form></body></html>';
    }

    private function createSponsor(string $type, array $overrides = []): WorkspaceSponsor
    {
        return $this->workspace->sponsors()->create(array_merge([
            'name' => ucfirst($type).' Synthetic Network',
            'affiliate_id' => 'affiliate-42',
            'url' => 'https://network.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-api-key',
            'type' => $type,
            'allowed_users' => [],
            'is_active' => true,
        ], $overrides));
    }

    private function bindPublicProviderResolver(): void
    {
        $this->app->instance(
            DmsAffiliateProviderHttp::class,
            new DmsAffiliateProviderHttp(static fn (string $host): array => ['93.184.216.34']),
        );
    }
}
