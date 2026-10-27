<?php

namespace App\Services\Infrastructure;

use App\Models\ManagedServer;
use App\Models\WorkspaceCloudProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DmsCloudProviderApi
{
    private const BASE_URLS = [
        'linode' => 'https://api.linode.com/v4/',
        'digitalocean' => 'https://api.digitalocean.com/',
    ];

    public function __construct(private readonly WorkspaceCloudProvider $provider)
    {
        if (! in_array($provider->type, WorkspaceCloudProvider::WORKFLOW_TYPES, true)) {
            throw new RuntimeException('Only Linode and DigitalOcean cloud workflows are available in this phase.');
        }
    }

    public function needsSshKeyPair(): bool
    {
        return $this->provider->type === 'digitalocean';
    }

    public function testConnection(): string
    {
        $response = match ($this->provider->type) {
            // Linode requires page_size >= 25; use its documented pagination
            // parameter and keep the connection check to the first small page.
            'linode' => $this->get('linode/instances', ['page' => 1, 'page_size' => 25]),
            'digitalocean' => $this->get('v2/droplets', ['per_page' => 1]),
        };
        $items = $this->provider->type === 'linode'
            ? $response->json('data')
            : $response->json('droplets');

        if (! is_array($items)) {
            throw new RuntimeException('The cloud API accepted the request but returned an unexpected instance-list response.');
        }

        $providerName = $this->provider->type === 'linode' ? 'Linode' : 'DigitalOcean';

        return 'Connected to '.$providerName.'. Instance-list access is available; cloud creation permissions were not tested.';
    }

    /** @return array{types:array,images:array,regions:array} */
    public function catalog(): array
    {
        return match ($this->provider->type) {
            'linode' => [
                'types' => $this->get('linode/types')->json('data', []),
                'images' => $this->get('images')->json('data', []),
                'regions' => $this->get('regions')->json('data', []),
            ],
            'digitalocean' => [
                'types' => $this->get('v2/sizes', ['per_page' => 200])->json('sizes', []),
                'images' => $this->get('v2/images', ['type' => 'distribution'])->json('images', []),
                'regions' => $this->get('v2/regions', ['per_page' => 200])->json('regions', []),
            ],
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function instances(): array
    {
        $items = match ($this->provider->type) {
            'linode' => $this->pagedLinodeInstances(),
            'digitalocean' => $this->get('v2/droplets', ['per_page' => 200])->json('droplets', []),
        };

        return collect($items)->map(fn (array $instance): array => $this->normalizeInstance($instance))->values()->all();
    }

    public function create(array $data, ?string $username, ?string $password, ?array $sshKeyPair = null): int
    {
        $sshKeyId = $this->needsSshKeyPair()
            ? $this->ensureProviderSshKey($sshKeyPair ?? throw new RuntimeException('The workspace cloud SSH key is unavailable.'))
            : null;
        $created = 0;

        for ($index = 0; $index < (int) $data['count']; $index++) {
            $name = $data['name'] ?? null;
            $response = match ($this->provider->type) {
                'linode' => $this->post('linode/instances', [
                    'label' => uniqid(($name ?? $username).'_'),
                    'image' => $data['image'], 'region' => $data['region'], 'type' => $data['type'],
                    'root_pass' => $password, 'private_ip' => false,
                ]),
                'digitalocean' => $this->post('v2/droplets', [
                    'name' => $name,
                    'image' => $data['image'], 'region' => $data['region'], 'size' => $data['type'],
                    'ssh_keys' => [$sshKeyId],
                ]),
                default => throw new RuntimeException('This cloud provider is not part of the current Linode and DigitalOcean implementation phase.'),
            };

            if ($response->failed()) {
                break;
            }

            $created++;
        }

        return $created;
    }

    /** @param array<int, array{id:string,ip:string}> $targets
     * @return array<int, string>
     */
    public function delete(array $targets): array
    {
        $results = [];
        foreach ($targets as $target) {
            $id = (string) $target['id'];
            $path = match ($this->provider->type) {
                'linode' => 'linode/instances/'.$id,
                'digitalocean' => 'v2/droplets/'.$id,
            };
            $response = $this->send(fn (PendingRequest $request): Response => $request->delete($path));
            if ($response->successful()) {
                ManagedServer::query()
                    ->where('workspace_id', $this->provider->workspace_id)
                    ->where('ip_address', $target['ip'])
                    ->where('is_locked', false)
                    ->delete();
                $results[] = $id.' Deleted Successfully';
            } else {
                $results[] = $id.' Failed';
            }
        }

        return $results;
    }

    /** @return array{success:bool,message:string} */
    public function setRdns(string $id, string $ip, string $rdns): array
    {
        $response = match ($this->provider->type) {
            'linode' => $this->send(fn (PendingRequest $request): Response => $request->put('networking/ips/'.$ip, ['rdns' => $rdns])),
            'digitalocean' => throw new RuntimeException('DigitalOcean does not expose the DMS rDNS operation.'),
        };

        return [
            'success' => $response->successful(),
            'message' => $response->successful() ? 'rDNS updated.' : 'The provider rejected the rDNS update (HTTP '.$response->status().').',
        ];
    }

    private function get(string $path, array $query = []): Response
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get($path, $query));
        if ($response->failed()) {
            $this->throwProviderFailure($response);
        }

        return $response;
    }

    private function post(string $path, array $body): Response
    {
        return $this->send(fn (PendingRequest $request): Response => $request->post($path, $body));
    }

    private function request(): PendingRequest
    {
        $baseUrl = self::BASE_URLS[$this->provider->type] ?? null;
        if ($baseUrl === null) {
            throw new RuntimeException('Only Linode and DigitalOcean cloud workflows are available in this phase.');
        }

        $token = (string) ($this->provider->tokens['api_token'] ?? '');
        $request = Http::baseUrl($baseUrl)->timeout(20)->connectTimeout(5)->acceptJson();

        return $request->withToken($token);
    }

    /** @param array{private_key:string,public_key:string} $keyPair */
    private function ensureProviderSshKey(array $keyPair): string
    {
        $keyName = 'alphalink-'.substr(str_replace('-', '', (string) $this->provider->workspace_id), 0, 12);
        $response = $this->get('v2/account/keys', ['per_page' => 200]);
        $keys = $response->json('ssh_keys', []);
        $existing = collect($keys)->firstWhere('name', $keyName);
        if (is_array($existing)) {
            $id = (string) ($existing['id'] ?? '');
            if ($id === '') {
                throw new RuntimeException('The cloud provider SSH key is missing its identifier.');
            }

            return $id;
        }

        $created = $this->post('v2/account/keys', ['name' => $keyName, 'public_key' => $keyPair['public_key']]);

        if ($created->failed()) {
            $this->throwProviderFailure($created);
        }

        $id = (string) $created->json('ssh_key.id', '');
        if ($id === '') {
            throw new RuntimeException('The cloud provider did not return an SSH key identifier.');
        }

        return $id;
    }

    /** @return array<int, array<string, mixed>> */
    private function pagedLinodeInstances(): array
    {
        $all = [];
        $page = 1;
        do {
            $response = $this->send(fn (PendingRequest $request): Response => $request->get('linode/instances', ['page' => $page, 'per_page' => 100]));
            if ($response->failed()) {
                break;
            }
            $items = $response->json('data', []);
            $all = [...$all, ...$items];
            $page++;
        } while ($items !== []);

        return $all;
    }

    /** @param array<string, mixed> $instance
     * @return array{id:string,name:string,status:string,region:string,type:string,ip:string,created_at:string}
     */
    private function normalizeInstance(array $instance): array
    {
        $publicNetwork = collect(data_get($instance, 'networks.v4', []))->firstWhere('type', 'public')
            ?? data_get($instance, 'networks.v4.0');
        $ip = $instance['ipv4'][0]
            ?? $instance['main_ip']
            ?? data_get($publicNetwork, 'ip_address')
            ?? data_get($instance, 'public_net.ipv4.ip')
            ?? data_get($instance, 'public_net.ipv4')
            ?? data_get($instance, 'network.ips.0.ip_address')
            ?? data_get($instance, 'nics.0.ip_address')
            ?? data_get($instance, 'nics.0.ips.0.ip_address')
            ?? data_get($instance, 'ip')
            ?? '';
        $id = $instance['id'] ?? $instance['identifier'] ?? $instance['reglet_id'] ?? $instance['name'] ?? '';

        return [
            'id' => (string) $id,
            'name' => (string) ($instance['label'] ?? $instance['name'] ?? $instance['hostname'] ?? '-'),
            'status' => (string) ($instance['status'] ?? data_get($instance, 'state.name') ?? '-'),
            'region' => $this->stringValue(
                data_get($instance, 'region.name')
                    ?? data_get($instance, 'region.slug')
                    ?? data_get($instance, 'region.id')
                    ?? data_get($instance, 'region')
                    ?? data_get($instance, 'location.name')
                    ?? data_get($instance, 'location')
                    ?? data_get($instance, 'region_name'),
            ),
            'type' => $this->stringValue(
                $instance['type']
                    ?? $instance['size_slug']
                    ?? data_get($instance, 'size.slug')
                    ?? $instance['size']
                    ?? $instance['plan']
                    ?? data_get($instance, 'server_type.name'),
            ),
            'ip' => is_scalar($ip) ? (string) $ip : '',
            'created_at' => (string) ($instance['created'] ?? $instance['created_at'] ?? '-'),
        ];
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : '-';
    }

    private function throwProviderFailure(Response $response): never
    {
        if ($this->provider->type === 'digitalocean' && $response->status() === 401) {
            throw new RuntimeException('DigitalOcean rejected the API access token (HTTP 401). It may be invalid or expired. Use an active DigitalOcean API access token, not a Spaces key or OAuth refresh token, then enter it again. Testing does not save token changes.');
        }

        if ($this->provider->type === 'digitalocean' && $response->status() === 403) {
            throw new RuntimeException('DigitalOcean denied droplet-list access (HTTP 403). Confirm the token has droplet:read and any dependent read scopes DigitalOcean requires for custom tokens. This check only reads droplets.');
        }

        throw new RuntimeException('Cloud provider request failed (HTTP '.$response->status().'). Check the saved provider credentials and account permissions.');
    }

    private function send(callable $operation): Response
    {
        try {
            return $operation($this->request());
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Cloud provider could not be reached. Check the network connection and retry.', previous: $exception);
        }
    }
}
