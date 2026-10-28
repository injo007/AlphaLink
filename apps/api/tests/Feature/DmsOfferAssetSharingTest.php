<?php

namespace Tests\Feature;

use App\Models\ManagedServer;
use App\Models\ServerCredential;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceOfferAsset;
use App\Models\WorkspaceSponsor;
use App\Services\Infrastructure\DmsInstallerSshConnection;
use App\Services\Infrastructure\DmsInstallerSshConnector;
use App\Services\Infrastructure\DmsInstallerSshSessionFactory;
use App\Services\Infrastructure\SshHostKeyFingerprint;
use App\Services\Infrastructure\SshHostProbe;
use Hashids\Hashids;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsOfferAssetSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private WorkspaceSponsor $sponsor;

    private WorkspaceOffer $offer;

    private string $hostKey;

    private int $serverCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->workspace = Workspace::query()->create([
            'name' => 'Offer sharing workspace',
            'slug' => 'offer-share-'.Str::lower(Str::random(8)),
            'created_by' => $this->owner->getKey(),
        ]);
        $this->workspace->users()->attach($this->owner->getKey(), ['role' => 'owner']);
        $this->actingAs($this->owner);
        $this->sponsor = $this->createSponsor('Synthetic creative network');
        $this->offer = $this->createOffer($this->sponsor);
        $this->workspace->appSettings()->create(['settings' => ['hash_token' => 'synthetic-folders-hash-salt']]);
        $this->hostKey = 'ssh-ed25519 '.base64_encode('synthetic-share-host-key');
    }

    public function test_share_action_matches_dms_selection_and_shows_only_active_installed_verified_workspace_servers(): void
    {
        $available = $this->createServer('SHARE_READY_NODE');
        $this->createServer('NOT_INSTALLED_NODE', ['is_installed' => false]);
        $this->createServer('PENDING_HOST_NODE', ['state' => 'pending_preflight', 'expected_host_fingerprint' => null]);

        $this->followingRedirects()->get(route('console.offers.index', $this->workspace->getKey()))
            ->assertOk()
            ->assertSee('/dms-reference/plugins/bootstrap_select/bootstrap-select.js', false)
            ->assertSee('/dms-reference/plugins/datatables/datatables.min.js', false)
            ->assertSee('SHARE CREATIVES')
            ->assertSee('data-share-url=', false)
            ->assertSee('/offers/share', false);

        $this->followingRedirects()->get(route('console.offers.share', $this->workspace->getKey()).'?'.http_build_query(['ids' => [$this->offer->getKey()]]))
            ->assertOk()
            ->assertSee('Selected Offers:')
            ->assertSee($this->offer->name)
            ->assertSee($available->name)
            ->assertSee('/var/mini/public/{offer-hash}', false)
            ->assertDontSee('NOT_INSTALLED_NODE')
            ->assertDontSee('PENDING_HOST_NODE');
    }

    public function test_owner_shares_creative_and_unsubscribe_image_files_without_overwriting_existing_files(): void
    {
        $server = $this->createServer('SHARE_TARGET_NODE');
        $creativePath = sprintf('offers/%s/%d/assets/creative/creative-a.png', $this->workspace->getKey(), $this->offer->getKey());
        $unsubscribePath = sprintf('offers/%s/%d/assets/unsubscribe/unsubscribe-b.gif', $this->workspace->getKey(), $this->offer->getKey());
        Storage::disk('local')->put($creativePath, 'creative bytes');
        Storage::disk('local')->put($unsubscribePath, 'unsubscribe bytes');
        $creative = $this->createAsset('creative', $creativePath, false);
        $unsubscribe = $this->createAsset('unsubscribe', $unsubscribePath, false);
        $connection = $this->bindSsh($server);

        $url = route('console.offers.share.send', $this->workspace->getKey());
        $payload = ['offers' => [$this->offer->getKey()], 'servers' => [$server->getKey()]];
        $this->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('0.status', true)
            ->assertJsonPath('0.uploaded_count', 2)
            ->assertJsonPath('0.skipped_count', 0)
            ->assertJsonPath('0.data', 'Shared Successfully (2 uploaded, 0 already present)');

        $this->assertCount(2, $connection->uploads);
        $directoryKey = (new Hashids('synthetic-folders-hash-salt'))->encode((int) $this->offer->getKey());
        $this->assertStringContainsString('/var/mini/public/'.$directoryKey, $connection->commands[0]);
        $creativeUpload = collect($connection->uploads)->first(fn (array $upload): bool => str_ends_with($upload['destination'], 'creative-a.png'));
        $this->assertSame('creative bytes', $creativeUpload['contents'] ?? null);
        $this->assertDatabaseHas('audit_events', [
            'workspace_id' => $this->workspace->getKey(),
            'action' => 'offer.assets_shared',
            'subject_type' => 'managed_server',
            'subject_id' => $server->getKey(),
        ]);
        $this->assertSame('creative', $creative->fresh()->type);
        $this->assertSame('unsubscribe', $unsubscribe->fresh()->type);

        $connection->existingFiles = true;
        $this->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('0.status', true)
            ->assertJsonPath('0.uploaded_count', 0)
            ->assertJsonPath('0.skipped_count', 2);
        $this->assertCount(2, $connection->uploads);
    }

    public function test_hashids_uses_dms_default_alphabet_and_minimum_length(): void
    {
        $this->assertSame('r0', (new Hashids('synthetic-folders-hash-salt'))->encode(1));
    }

    public function test_workspace_and_operator_permissions_are_checked_before_ssh(): void
    {
        $server = $this->createServer('ASSIGNED_NODE');
        $connection = $this->bindSsh($server);
        $unassignedServer = $this->createServer('UNASSIGNED_NODE');
        $operator = User::factory()->create();
        $this->workspace->users()->attach($operator->getKey(), ['role' => 'operator']);
        $this->sponsor->forceFill(['allowed_users' => [(int) $operator->getKey()]])->save();
        $this->offer->forceFill(['allowed_users' => [(int) $operator->getKey()]])->save();
        $server->allowedUsers()->attach($operator->getKey(), ['workspace_id' => $this->workspace->getKey()]);

        $this->followingRedirects()->actingAs($operator)
            ->get(route('console.offers.share', $this->workspace->getKey()).'?'.http_build_query(['ids' => [$this->offer->getKey()]]))
            ->assertOk()
            ->assertSee('ASSIGNED_NODE');

        $this->actingAs($operator)
            ->postJson(route('console.offers.share.send', $this->workspace->getKey()), [
                'offers' => [$this->offer->getKey()],
                'servers' => [$unassignedServer->getKey()],
            ])
            ->assertNotFound();
        $this->assertSame(0, $connection->connectionCalls);

        $this->actingAs($operator)
            ->postJson(route('console.offers.share.send', $this->workspace->getKey()), [
                'offers' => [$this->offer->getKey()],
                'servers' => [$server->getKey()],
            ])
            ->assertOk()
            ->assertJsonPath('0.status', true);
        $this->assertSame(1, $connection->connectionCalls);

        $anotherWorkspace = Workspace::query()->create([
            'name' => 'Isolated workspace',
            'slug' => 'isolated-'.Str::lower(Str::random(8)),
            'created_by' => $operator->getKey(),
        ]);
        $anotherWorkspace->users()->attach($operator->getKey(), ['role' => 'owner']);
        $foreignSponsor = $anotherWorkspace->sponsors()->create([
            'name' => 'Foreign sponsor',
            'url' => 'https://foreign.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-key',
            'type' => 'manual',
            'is_active' => true,
            'allowed_users' => [],
        ]);
        $foreignOffer = $anotherWorkspace->offers()->create([
            'sponsor_id' => $foreignSponsor->getKey(),
            'offer_id' => 'foreign-offer',
            'name' => 'Foreign offer',
            'type' => 'cpa',
            'vertical' => 'test',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://offer.example.test/track',
            'allowed_days' => ['Mon'],
            'allowed_users' => [],
            'status' => 'live',
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->postJson(route('console.offers.share.send', $this->workspace->getKey()), [
                'offers' => [$foreignOffer->getKey()],
                'servers' => [$server->getKey()],
            ])
            ->assertNotFound();

        $this->assertSame(1, $connection->connectionCalls);
    }

    public function test_a_changed_ssh_host_key_fails_that_server_without_uploading_assets(): void
    {
        $server = $this->createServer('CHANGED_HOST_NODE', ['expected_host_fingerprint' => 'SHA256:wrong-pin']);
        $connection = $this->bindSsh($server);
        $path = sprintf('offers/%s/%d/assets/creative/asset.png', $this->workspace->getKey(), $this->offer->getKey());
        Storage::disk('local')->put($path, 'synthetic image');
        $this->createAsset('creative', $path);

        $this->postJson(route('console.offers.share.send', $this->workspace->getKey()), [
            'offers' => [$this->offer->getKey()],
            'servers' => [$server->getKey()],
        ])
            ->assertOk()
            ->assertJsonPath('0.status', false)
            ->assertJsonPath('0.data', 'The SSH host key changed since the previous preflight.')
            ->assertJsonPath('0.uploaded_count', 0);

        $this->assertSame([], $connection->uploads);
        $this->assertSame([], $connection->commands);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'offer.assets_share_failed',
            'subject_id' => $server->getKey(),
        ]);
    }

    public function test_missing_dms_hash_token_blocks_sharing_before_opening_ssh(): void
    {
        $server = $this->createServer('MISSING_HASH_TOKEN_NODE');
        $connection = $this->bindSsh($server);
        $this->workspace->appSettings()->delete();

        $this->followingRedirects()->get(route('console.offers.share', $this->workspace->getKey()).'?'.http_build_query(['ids' => [$this->offer->getKey()]]))
            ->assertOk()
            ->assertSee('Configure <strong>FOLDERS HASH TOKEN</strong>', false)
            ->assertSee('disabled', false);

        $this->postJson(route('console.offers.share.send', $this->workspace->getKey()), [
            'offers' => [$this->offer->getKey()],
            'servers' => [$server->getKey()],
        ])
            ->assertOk()
            ->assertJsonPath('0.status', false)
            ->assertJsonPath('0.data', 'Set FOLDERS HASH TOKEN in App Settings before sharing offer assets. No server was changed.')
            ->assertJsonPath('0.uploaded_count', 0)
            ->assertJsonPath('0.skipped_count', 0);

        $this->assertSame(0, $connection->connectionCalls);
        $this->assertSame([], $connection->commands);
        $this->assertSame([], $connection->uploads);
    }

    private function createSponsor(string $name): WorkspaceSponsor
    {
        return $this->workspace->sponsors()->create([
            'name' => $name,
            'type' => 'everflow',
            'url' => 'https://network.example.test',
            'username' => 'synthetic-user',
            'password' => 'synthetic-password',
            'api_key' => 'synthetic-key',
            'allowed_users' => [],
            'is_active' => true,
        ]);
    }

    private function createOffer(WorkspaceSponsor $sponsor): WorkspaceOffer
    {
        return $this->workspace->offers()->create([
            'sponsor_id' => $sponsor->getKey(),
            'offer_id' => 'synthetic-offer-'.Str::lower(Str::random(5)),
            'name' => 'Synthetic offer',
            'type' => 'cpa',
            'vertical' => 'test',
            'geo_target' => ['US'],
            'payout' => 1,
            'redirect' => 'https://offer.example.test/track',
            'allowed_days' => ['Mon'],
            'allowed_users' => [],
            'status' => 'live',
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createServer(string $name, array $overrides = []): ManagedServer
    {
        $fingerprint = (new SshHostKeyFingerprint)->fromPublicKey($this->hostKey);
        $server = ManagedServer::query()->create(array_merge([
            'workspace_id' => $this->workspace->getKey(),
            'name' => $name,
            'provider' => 'Synthetic provider',
            'ip_address' => '198.51.100.'.(10 + $this->serverCounter++),
            'ssh_port' => 22,
            'ssh_username' => 'root',
            'expected_host_fingerprint' => $fingerprint,
            'state' => 'verified',
            'is_active' => true,
            'is_installed' => true,
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('b', 64),
            'created_by' => $this->owner->getKey(),
        ], $overrides));
        ServerCredential::query()->create([
            'workspace_id' => $this->workspace->getKey(),
            'managed_server_id' => $server->getKey(),
            'credential_type' => 'password',
            'encrypted_secret' => Crypt::encryptString('synthetic-share-password'),
            'created_by' => $this->owner->getKey(),
        ]);

        return $server;
    }

    private function createAsset(string $type, string $value, bool $active = true): WorkspaceOfferAsset
    {
        return $this->offer->assets()->create([
            'workspace_id' => $this->workspace->getKey(),
            'type' => $type,
            'value' => $value,
            'is_active' => $active,
            'created_by' => $this->owner->getKey(),
        ]);
    }

    private function bindSsh(ManagedServer $server): object
    {
        $connection = new class($this->hostKey) implements DmsInstallerSshConnection
        {
            public array $commands = [];

            public array $uploads = [];

            public int $connectionCalls = 0;

            public bool $existingFiles = false;

            public bool $authenticated = false;

            public function __construct(private readonly string $publicHostKey) {}

            public function getServerPublicHostKey(): string|false
            {
                return $this->publicHostKey;
            }

            public function login(string $username, mixed $credential): bool
            {
                $this->authenticated = $username === 'root' && $credential === 'synthetic-share-password';

                return $this->authenticated;
            }

            public function isConnected(): bool
            {
                return true;
            }

            public function isAuthenticated(): bool
            {
                return $this->authenticated;
            }

            public function exec(string $command): string|false
            {
                $this->commands[] = $command;
                if (str_contains($command, 'mkdir -p')) {
                    return 'ALPHALINK_OFFER_DIR_READY';
                }

                if (str_contains($command, 'if [ -f')) {
                    return $this->existingFiles ? 'PRESENT' : 'MISSING';
                }

                return false;
            }

            public function uploadString(string $contents, string $destination): bool
            {
                $this->uploads[] = ['destination' => $destination, 'contents' => $contents];

                return true;
            }

            public function uploadFile(string $localPath, string $destination): bool
            {
                $contents = file_get_contents($localPath);
                $this->uploads[] = ['destination' => $destination, 'contents' => $contents];

                return is_string($contents);
            }

            public function downloadString(string $path): string|false
            {
                return false;
            }

            public function listDirectory(string $path): array|false
            {
                return false;
            }

            public function isDirectory(string $path): bool
            {
                return false;
            }

            public function createDirectory(string $path): bool
            {
                return false;
            }

            public function fileSize(string $path): int|false
            {
                return false;
            }

            public function fileModifiedAt(string $path): int|false
            {
                return false;
            }

            public function downloadFile(string $remotePath, string $localPath): bool
            {
                return false;
            }

            public function moveFile(string $remotePath, string $destination): bool
            {
                return false;
            }
        };
        $connector = new class($connection) implements DmsInstallerSshConnector
        {
            public int $connectionCalls = 0;

            public function __construct(private readonly DmsInstallerSshConnection $connection) {}

            public function connect(string $host, int $port, int $timeoutSeconds): DmsInstallerSshConnection
            {
                $this->connection->connectionCalls++;

                return $this->connection;
            }
        };
        $this->app->instance(DmsInstallerSshSessionFactory::class, new DmsInstallerSshSessionFactory(
            $connector,
            new SshHostProbe,
            new SshHostKeyFingerprint,
        ));

        return $connection;
    }
}
