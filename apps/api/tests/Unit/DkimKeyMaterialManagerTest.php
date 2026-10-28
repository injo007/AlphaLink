<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Infrastructure\DkimKeyMaterialManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DkimKeyMaterialManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_and_encrypts_a_persistent_workspace_keypair(): void
    {
        $workspace = $this->workspace();
        $manager = app(DkimKeyMaterialManager::class);

        $first = $manager->ensureForWorkspace($workspace);
        $second = $manager->ensureForWorkspace($workspace);
        $storedSettings = $workspace->infrastructureSettings()->firstOrFail();
        $rawPrivateKey = $storedSettings->getRawOriginal('dkim_private_key');

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/\Aal[a-f0-9]{16}\z/', $first['selector']);
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $first['private_key']);
        $this->assertStringStartsWith('v=DKIM1;t=s;p=', $first['public_record']);
        $this->assertIsString($rawPrivateKey);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $rawPrivateKey);
        $this->assertNotSame($first['private_key'], $rawPrivateKey);
        $this->assertSame($first['private_key'], $storedSettings->dkim_private_key);
        $this->assertSame(
            $first['public_record'],
            $manager->publicRecord($storedSettings->dkim_private_key),
        );
    }

    public function test_it_imports_a_valid_legacy_key_once_and_preserves_the_existing_selector(): void
    {
        $workspace = $this->workspace();
        $manager = app(DkimKeyMaterialManager::class);
        $legacyPrivateKey = $manager->generatePrivateKey();
        $workspace->infrastructureSettings()->create(['dkim_key' => 'existing-selector']);

        $material = $manager->ensureForWorkspace($workspace, null, $legacyPrivateKey);
        $storedSettings = $workspace->infrastructureSettings()->firstOrFail();

        $this->assertSame('existing-selector', $material['selector']);
        $this->assertSame($legacyPrivateKey, $material['private_key']);
        $this->assertSame($legacyPrivateKey, $storedSettings->dkim_private_key);
    }

    public function test_it_rejects_an_invalid_stored_private_key_instead_of_rotating_it(): void
    {
        $workspace = $this->workspace();
        $workspace->infrastructureSettings()->create([
            'dkim_key' => 'existing-selector',
            'dkim_private_key' => 'invalid private key',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The workspace DKIM private key is invalid.');

        app(DkimKeyMaterialManager::class)->ensureForWorkspace($workspace);
    }

    private function workspace(): Workspace
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'DKIM fixture',
            'slug' => 'dkim-'.bin2hex(random_bytes(5)),
            'created_by' => $owner->getKey(),
        ]);
        $workspace->users()->attach($owner->getKey(), ['role' => 'owner']);

        return $workspace;
    }
}
