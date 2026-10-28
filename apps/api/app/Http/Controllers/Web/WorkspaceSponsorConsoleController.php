<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSponsor;
use App\Services\AffiliateNetworks\DmsAffiliateProviderException;
use App\Services\AffiliateNetworks\DmsSponsorConnectionTester;
use App\Services\AffiliateNetworks\DmsSponsorExtensionPackage;
use App\Services\AffiliateNetworks\DmsSponsorLoginSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class WorkspaceSponsorConsoleController extends Controller
{
    private const NETWORK_ROLES = ['owner', 'admin', 'operator'];

    private const MANAGE_ROLES = ['owner', 'admin'];

    public function index(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $sponsors = $workspace->sponsors()->withCount('offers')->orderByDesc('created_at')->get();

        if ($request->wantsJson()) {
            return response()->json(['data' => $sponsors->map(fn (WorkspaceSponsor $sponsor): array => [
                'id' => $sponsor->getKey(),
                'affiliate_id' => $sponsor->affiliate_id,
                'name' => $sponsor->name,
                'username' => $sponsor->username,
                'type' => $sponsor->type,
                'created_at' => $sponsor->created_at?->toDateTimeString(),
                'offers_count' => $sponsor->offers_count,
                'allowed_users' => $sponsor->allowed_users ?? [],
                'url' => $sponsor->url,
                'is_active' => $sponsor->is_active,
            ])->values()]);
        }

        return view('console.sponsors.index', [
            'workspace' => $workspace,
            ...$this->sponsorHelperSettings($workspace, app(DmsSponsorExtensionPackage::class)),
        ]);
    }

    public function networkSponsors(Request $request, string $workspaceId): View|JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::NETWORK_ROLES);
        $sponsors = $workspace->sponsors()
            ->where('is_active', true)
            ->when($workspace->pivot->role === 'operator', fn ($query) => $query->whereJsonContains(
                'allowed_users',
                (int) $request->user()->getKey(),
            ))
            ->withCount(['offers' => fn ($query) => $query->where('is_active', true)->when(
                $workspace->pivot->role === 'operator',
                fn ($offers) => $offers->whereJsonContains('allowed_users', (int) $request->user()->getKey()),
            )])
            ->orderByDesc('created_at')
            ->get();

        if ($request->wantsJson()) {
            return response()->json(['data' => $sponsors->map(fn (WorkspaceSponsor $sponsor): array => [
                'id' => $sponsor->getKey(),
                'affiliate_id' => $sponsor->affiliate_id,
                'name' => $sponsor->name,
                'offers_count' => $sponsor->offers_count,
                'created_at' => $sponsor->created_at?->toDateTimeString(),
                'url' => $sponsor->url,
            ])->values()]);
        }

        return view('console.sponsors.network-index', [
            'workspace' => $workspace,
            'sponsors' => $sponsors,
            'isOperator' => $workspace->pivot->role === 'operator',
            ...$this->sponsorHelperSettings($workspace, app(DmsSponsorExtensionPackage::class)),
        ]);
    }

    public function login(
        Request $request,
        string $workspaceId,
        int $sponsorId,
        DmsSponsorLoginSession $loginSession,
    ): JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::NETWORK_ROLES);
        $sponsor = $workspace->sponsors()->whereKey($sponsorId)->firstOrFail();
        if ($workspace->pivot->role === 'operator') {
            abort_unless(
                $sponsor->is_active && in_array((int) $request->user()->getKey(), $sponsor->allowed_users ?? [], true),
                404,
            );
        }

        $result = $loginSession->createSession($sponsor);
        $this->auditSponsor(
            $workspace,
            $request,
            ($result['status'] ?? false) === true ? 'sponsor.login_succeeded' : 'sponsor.login_failed',
            $sponsor,
            ['type' => $sponsor->type],
        );

        return response()->json($result);
    }

    public function downloadExtension(
        Request $request,
        string $workspaceId,
        DmsSponsorExtensionPackage $package,
    ): BinaryFileResponse {
        $this->workspaceFor($request->user(), $workspaceId, self::NETWORK_ROLES);
        $path = $package->createForCurrentOrigin();

        return response()->download($path, 'alphalink-sponsor-helper.zip', [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend();
    }

    public function create(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);

        return view('console.sponsors.form', [
            'workspace' => $workspace,
            'sponsor' => null,
            'types' => WorkspaceSponsor::TYPES,
        ]);
    }

    public function edit(Request $request, string $workspaceId, int $sponsorId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $sponsor = $workspace->sponsors()->whereKey($sponsorId)->firstOrFail();

        return view('console.sponsors.form', [
            'workspace' => $workspace,
            'sponsor' => $sponsor,
            'types' => WorkspaceSponsor::TYPES,
        ]);
    }

    public function users(Request $request, string $workspaceId, int $sponsorId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $sponsor = $workspace->sponsors()->whereKey($sponsorId)->firstOrFail();
        $users = $workspace->users()
            ->wherePivot('role', 'operator')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.username']);

        return view('console.sponsors.users', [
            'workspace' => $workspace,
            'sponsor' => $sponsor,
            'sponsors' => collect([$sponsor]),
            'users' => $users,
            'bulk' => false,
        ]);
    }

    public function bulkUsers(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = array_map('intval', $data['ids']);
        $sponsors = $workspace->sponsors()->whereKey($ids)->orderBy('name')->get();
        abort_unless($sponsors->count() === count($ids), 404);
        $users = $workspace->users()
            ->wherePivot('role', 'operator')
            ->orderBy('users.id')
            ->get(['users.id', 'users.name', 'users.username']);

        return view('console.sponsors.users', [
            'workspace' => $workspace,
            'sponsor' => null,
            'sponsors' => $sponsors,
            'users' => $users,
            'bulk' => true,
        ]);
    }

    public function store(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $data = $this->validateSponsor($request, false);
        $sponsor = $workspace->sponsors()->create($data + [
            'allowed_users' => [],
            'is_active' => true,
        ]);
        $this->auditSponsor($workspace, $request, 'sponsor.created', $sponsor);

        return response()->json(['data' => 'Sponsor created']);
    }

    public function testNewConnection(
        Request $request,
        string $workspaceId,
        DmsSponsorConnectionTester $tester,
    ): JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);

        return $this->testSponsorCredentials($request, $workspace, null, $tester);
    }

    public function testExistingConnection(
        Request $request,
        string $workspaceId,
        int $sponsorId,
        DmsSponsorConnectionTester $tester,
    ): JsonResponse {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $sponsor = $workspace->sponsors()->whereKey($sponsorId)->firstOrFail();

        return $this->testSponsorCredentials($request, $workspace, $sponsor, $tester);
    }

    private function testSponsorCredentials(
        Request $request,
        Workspace $workspace,
        ?WorkspaceSponsor $savedSponsor,
        DmsSponsorConnectionTester $tester,
    ): JsonResponse {
        $data = $request->validate([
            'affiliate_id' => ['nullable', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:9999'],
            'type' => ['required', 'string', Rule::in(WorkspaceSponsor::TYPES)],
        ]);

        if (blank($data['api_key'] ?? null) && $savedSponsor?->type === $data['type']) {
            $data['api_key'] = $savedSponsor->api_key;
        }

        $sponsor = new WorkspaceSponsor([
            'workspace_id' => $workspace->getKey(),
            'affiliate_id' => $data['affiliate_id'] ?? null,
            'url' => $data['url'],
            'api_key' => $data['api_key'] ?? null,
            'type' => $data['type'],
        ]);

        try {
            $message = $tester->test($sponsor);

            return response()->json(['success' => true, 'message' => $message]);
        } catch (DmsAffiliateProviderException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    public function update(Request $request, string $workspaceId, int $sponsorId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $sponsor = $workspace->sponsors()->whereKey($sponsorId)->firstOrFail();
        $data = $this->validateSponsor($request, true);

        foreach (['password', 'api_key'] as $secret) {
            if (trim((string) ($data[$secret] ?? '')) === '') {
                unset($data[$secret]);
            }
        }

        $sponsor->fill($data)->save();
        $this->auditSponsor($workspace, $request, 'sponsor.updated', $sponsor);

        return response()->json(['data' => 'Sponsor updated']);
    }

    public function setStatus(Request $request, string $workspaceId, int $sponsorId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $sponsor = $workspace->sponsors()->whereKey($sponsorId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $sponsor->forceFill(['is_active' => (bool) $data['status']])->save();
        $this->auditSponsor($workspace, $request, 'sponsor.status_changed', $sponsor, [
            'is_active' => $sponsor->is_active,
        ]);

        return response()->json(['data' => 'Changed successfully']);
    }

    public function updateUsers(Request $request, string $workspaceId, ?int $sponsorId = null): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId, self::MANAGE_ROLES);
        $bulk = $sponsorId === null;
        $rules = [
            'users' => ['nullable', 'array'],
            'users.*' => [
                'required', 'integer', 'distinct',
                Rule::exists('workspace_user', 'user_id')
                    ->where('workspace_id', $workspace->getKey())
                    ->where('role', 'operator'),
            ],
        ];
        if ($bulk) {
            $rules['sponsors'] = ['required', 'array', 'min:1'];
            $rules['sponsors.*'] = ['required', 'integer', 'distinct'];
        }
        $data = $request->validate($rules);

        $ids = $bulk ? array_map('intval', $data['sponsors']) : [$sponsorId];
        $sponsors = $workspace->sponsors()->whereKey($ids)->orderBy('id')->get();
        abort_unless($sponsors->count() === count($ids), 404);
        $userIds = array_values(array_unique(array_map('intval', $data['users'] ?? [])));

        DB::transaction(function () use ($sponsors, $userIds, $workspace, $request, $bulk): void {
            foreach ($sponsors as $sponsor) {
                $sponsor->forceFill(['allowed_users' => $userIds])->save();
                // DMS cascades a single sponsor assignment to its offers. Its bulk
                // sponsor assignment changes sponsors only, so retain that distinction.
                if (! $bulk) {
                    $sponsor->offers()->update(['allowed_users' => json_encode($userIds)]);
                }
                $this->auditSponsor($workspace, $request, 'sponsor.allowed_users_updated', $sponsor, [
                    'allowed_user_ids' => $userIds,
                ]);
            }
        });

        return response()->json(['data' => 'Updated successfully']);
    }

    /** @return array<string, mixed> */
    private function validateSponsor(Request $request, bool $editing): array
    {
        return $request->validate([
            'affiliate_id' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'url' => ['required', 'url', 'min:4', 'max:255'],
            'username' => ['required', 'string', 'min:4', 'max:255'],
            'password' => [$editing ? 'nullable' : 'required', 'string', 'min:4', 'max:255'],
            'api_key' => [$editing ? 'nullable' : 'required', 'string', 'min:4', 'max:9999'],
            'type' => ['required', 'string', Rule::in(WorkspaceSponsor::TYPES)],
        ]);
    }

    /** @param array<string, mixed> $properties */
    private function auditSponsor(
        Workspace $workspace,
        Request $request,
        string $action,
        WorkspaceSponsor $sponsor,
        array $properties = [],
    ): void {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => 'sponsor',
            'subject_id' => (string) $sponsor->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }

    /** @param array<int, string> $roles */
    private function workspaceFor(User $user, string $workspaceId, array $roles): Workspace
    {
        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();

        abort_unless(in_array($workspace->pivot->role, $roles, true), 403);

        return $workspace;
    }

    /** @return array{extensionId: string, extensionUrl: string|null} */
    private function sponsorHelperSettings(Workspace $workspace, DmsSponsorExtensionPackage $package): array
    {
        $configuredId = trim((string) ($workspace->appSettings?->settings['extension_id'] ?? ''));

        return [
            'extensionId' => $configuredId !== '' ? $configuredId : ($package->extensionIdIfAvailable() ?? ''),
            'extensionUrl' => $package->downloadAvailable() ? route('clean.console.sponsors.extension') : null,
        ];
    }
}
