<?php

namespace Tests\Feature;

use App\Models\DomainProvider;
use App\Models\SendingDomain;
use App\Models\SendingDomainDkimIdentity;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\AlphaLinkSendingDomainDkimIdentityManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AlphaLinkSendingDomainDkimIdentityManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_hostname_gets_an_independent_encrypted_rsa_key_and_retries_reuse_it(): void
    {
        [$workspace, $domain] = $this->domain();
        $manager = app(AlphaLinkSendingDomainDkimIdentityManager::class);

        $first = $manager->ensure($workspace, $domain, 'mail.example.test');
        $retry = $manager->ensure($workspace, $domain, 'MAIL.EXAMPLE.TEST.');
        $second = $manager->ensure($workspace, $domain, 'news.example.test');

        $this->assertSame($first->getKey(), $retry->getKey());
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertNotSame($first->private_key, $second->private_key);
        $this->assertNotSame($first->public_record, $second->public_record);
        $this->assertNotSame($first->selector, $second->selector);
        $this->assertMatchesRegularExpression('/\Av=DKIM1; k=rsa; p=[A-Za-z0-9+\/=]+\z/', $first->public_record);
        $this->assertGreaterThanOrEqual(2048, openssl_pkey_get_details(openssl_pkey_get_private($first->private_key))['bits']);
        $storedKey = DB::table('sending_domain_dkim_identities')->where('id', $first->getKey())->value('private_key');
        $this->assertIsString($storedKey);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $storedKey);
        $this->assertArrayNotHasKey('private_key', $first->toArray());
        $this->assertSame(2, SendingDomainDkimIdentity::query()->count());
    }

    public function test_foreign_or_unrelated_domain_hostname_is_rejected_before_key_creation(): void
    {
        [$workspace, $domain] = $this->domain();
        [$otherWorkspace, $otherDomain] = $this->domain('other.example.test', 'other-workspace');
        $manager = app(AlphaLinkSendingDomainDkimIdentityManager::class);

        try {
            $manager->ensure($workspace, $otherDomain, 'other.example.test');
            $this->fail('Foreign sending domains must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('workspace', $exception->getMessage());
        }

        try {
            $manager->ensure($workspace, $domain, 'mail.unrelated.test');
            $this->fail('An unrelated hostname must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('hostname', $exception->getMessage());
        }

        $this->assertSame(0, SendingDomainDkimIdentity::query()->count());
        $this->assertNotSame($workspace->getKey(), $otherWorkspace->getKey());
    }

    public function test_disabled_identity_is_not_silently_reactivated(): void
    {
        [$workspace, $domain] = $this->domain();
        $manager = app(AlphaLinkSendingDomainDkimIdentityManager::class);
        $identity = $manager->ensure($workspace, $domain, 'mail.example.test');
        $identity->forceFill(['is_active' => false])->saveOrFail();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inactive signing identity');
        $manager->ensure($workspace, $domain, 'mail.example.test');
    }

    /** @return array{Workspace, SendingDomain} */
    private function domain(string $name = 'example.test', string $slug = 'dkim-workspace'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'created_by' => $user->getKey(),
        ]);
        $provider = DomainProvider::query()->create(['name' => 'Synthetic '.$slug, 'is_active' => true]);
        $domain = SendingDomain::query()->create([
            'workspace_id' => $workspace->getKey(),
            'name' => $name,
            'state' => 'verified',
            'verification_token' => 'synthetic-token',
            'created_by' => $user->getKey(),
            'domains_provider_id' => $provider->getKey(),
            'status' => 'free',
            'is_our' => true,
            'is_active' => true,
        ]);

        return [$workspace, $domain];
    }
}
