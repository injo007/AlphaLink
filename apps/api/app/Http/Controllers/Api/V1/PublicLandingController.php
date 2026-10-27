<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWorkspacePublicOptOutJob;
use App\Models\Workspace;
use App\Services\Campaigns\CampaignVisitorMetadataResolver;
use App\Services\Campaigns\TrackingIngestAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicLandingController extends Controller
{
    public function subscribe(
        Request $request,
        TrackingIngestAuthenticator $authenticator,
        CampaignVisitorMetadataResolver $metadataResolver,
    ): Response {
        $data = $request->validate([
            ...$this->workspaceRules(),
            'email' => ['required', 'email'],
            'ip' => ['required', 'string'],
            'user_agent' => ['required', 'string'],
            'referer' => ['nullable', 'string'],
        ]);
        $workspace = $this->authorizedWorkspace($data, $authenticator);
        $metadata = $this->visitorMetadata($workspace, $data, $metadataResolver);

        $workspace->subscribers()->create([
            'email' => $data['email'],
            'ip' => $this->validIp($data['ip']),
            ...$metadata,
        ]);

        return response()->noContent();
    }

    public function contact(
        Request $request,
        TrackingIngestAuthenticator $authenticator,
        CampaignVisitorMetadataResolver $metadataResolver,
    ): Response {
        $data = $request->validate([
            ...$this->workspaceRules(),
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'phone' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:255'],
            'ip' => ['required', 'string'],
            'user_agent' => ['required', 'string'],
            'referer' => ['nullable', 'string'],
        ]);
        $workspace = $this->authorizedWorkspace($data, $authenticator);
        $metadata = $this->visitorMetadata($workspace, $data, $metadataResolver);

        $workspace->contactSubmissions()->create([
            'full_name' => $data['full_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'message' => $data['message'],
            'ip' => $this->validIp($data['ip']),
            ...$metadata,
        ]);

        return response()->noContent();
    }

    public function optOut(Request $request, TrackingIngestAuthenticator $authenticator): Response
    {
        $data = $request->validate([
            ...$this->workspaceRules(),
            'email' => ['required', 'email'],
            'ip' => ['required', 'string'],
            'user_agent' => ['required', 'string'],
            'referer' => ['nullable', 'string'],
        ]);
        $workspace = $this->authorizedWorkspace($data, $authenticator);
        $lookupKey = config('alphalink.contact_lookup_key');
        if (! is_string($lookupKey) || strlen($lookupKey) < 32) {
            abort(503, 'Contact storage is not configured.');
        }

        $normalizedEmail = mb_strtolower(trim($data['email']));
        $emailHash = hash_hmac('sha256', $normalizedEmail, $lookupKey);
        $contact = $workspace->contacts()->where('email_lookup_hash', $emailHash)->first();

        if ($contact !== null) {
            ProcessWorkspacePublicOptOutJob::dispatch(
                (string) $workspace->getKey(),
                (string) $contact->getKey(),
                $this->validIp($data['ip']),
                $data['user_agent'],
            );
        }

        return response()->noContent();
    }

    /** @return array<string, array<int, string>> */
    private function workspaceRules(): array
    {
        return [
            'workspace_id' => ['required', 'uuid', 'exists:workspaces,id'],
            'key' => ['required', 'string', 'max:255'],
        ];
    }

    /** @param array<string, mixed> $data */
    private function authorizedWorkspace(array $data, TrackingIngestAuthenticator $authenticator): Workspace
    {
        $workspace = Workspace::query()->findOrFail($data['workspace_id']);
        $authenticator->assertValid($workspace, $data['key']);

        return $workspace;
    }

    /** @param array<string, mixed> $data
     * @return array{country: ?string, state: ?string, city: ?string, device: ?string, platform: ?string, browser: ?string, is_mobile: bool|null}
     */
    private function visitorMetadata(
        Workspace $workspace,
        array $data,
        CampaignVisitorMetadataResolver $metadataResolver,
    ): array {
        $ip = $this->validIp($data['ip']);
        if ($ip !== null) {
            $metadata = $metadataResolver->resolve($workspace, $ip, $data['user_agent'], 'landing');

            return [
                'country' => $metadata['country'],
                'state' => $metadata['state'],
                'city' => $metadata['city'],
                'device' => $metadata['device'],
                'platform' => $metadata['platform'],
                'browser' => $metadata['browser'],
                'is_mobile' => $metadata['is_mobile'],
            ];
        }

        return [
            'country' => null,
            'state' => null,
            'city' => null,
            ...$metadataResolver->parseUserAgent($data['user_agent']),
        ];
    }

    private function validIp(string $value): ?string
    {
        return filter_var($value, FILTER_VALIDATE_IP) === false ? null : $value;
    }
}
