<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AudienceList;
use App\Models\AuditEvent;
use App\Models\ContactListMembership;
use App\Models\Isp;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audiences\ImportAudienceListContacts;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AudienceListManagementController extends Controller
{
    private const MANAGE_ROLES = ['owner', 'admin'];

    private const LOCK_TYPES = ['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'];

    public function index(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);

        return view('console.infrastructure.lists.index', [
            'workspace' => $workspace,
            'isps' => $workspace->isps()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function rows(Request $request, string $workspaceId, int $ispId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $isp = $workspace->isps()->whereKey($ispId)->firstOrFail();
        $lists = $isp->lists()
            ->where('workspace_id', $workspace->getKey())
            ->with('history')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $lists->map(fn (AudienceList $list): array => [
            'id' => (string) $list->getKey(),
            'name' => $list->name,
            'country' => $list->country,
            'is_active' => $list->status === 'active',
            'is_tracked' => (bool) $list->is_tracked,
            'is_fresh_locked' => (bool) $list->is_fresh_locked,
            'is_delivery_locked' => (bool) $list->is_delivery_locked,
            'is_open_locked' => (bool) $list->is_open_locked,
            'is_click_locked' => (bool) $list->is_click_locked,
            'is_lead_locked' => (bool) $list->is_lead_locked,
            'is_unsubscribe_locked' => (bool) $list->is_unsubscribe_locked,
            'allowed_users' => $list->allowed_users ?? [],
            'allowed_sponsors' => $list->allowed_sponsors ?? [],
            'history' => [
                'fresh' => $list->history?->fresh ?? 'NA',
                'delivery' => $list->history?->delivery ?? 'NA',
                'open' => $list->history?->open ?? 'NA',
                'click' => $list->history?->click ?? 'NA',
                'lead' => $list->history?->lead ?? 'NA',
                'unsubscribe' => $list->history?->unsubscribe ?? 'NA',
                'opt_out' => $list->history?->opt_out ?? 'NA',
                'bot' => $list->history?->bot ?? 'NA',
                'proxy' => $list->history?->proxy ?? 'NA',
                'blacklist' => $list->history?->blacklist ?? 'NA',
                'hard_bounce' => $list->history?->hard_bounce ?? 'NA',
            ],
        ])->values()]);
    }

    public function uploadForm(Request $request, string $workspaceId, string $listId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $list = $workspace->audienceLists()->whereKey($listId)->firstOrFail();

        return view('console.infrastructure.lists.upload', [
            'workspace' => $workspace,
            'list' => $list,
            'types' => ContactListMembership::CAMPAIGN_TYPES,
        ]);
    }

    public function upload(Request $request, string $workspaceId, string $listId, ImportAudienceListContacts $importer): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $list = $workspace->audienceLists()->whereKey($listId)->firstOrFail();
        $data = $request->validate([
            // Match this AlphaLink runtime's PHP upload_max_filesize (2 MiB).
            'file' => ['required', 'file', 'mimes:txt,csv', 'max:2048'],
            'type' => ['required', 'string', Rule::in(ContactListMembership::CAMPAIGN_TYPES)],
            'filter' => ['nullable', 'string', 'max:255'],
        ]);

        $lookupKey = config('alphalink.contact_lookup_key');
        if (! is_string($lookupKey) || strlen($lookupKey) < 32) {
            return back()->withErrors([
                'file' => 'Contact storage is not configured. Set the dedicated lookup key before importing personal data.',
            ])->withInput();
        }

        set_time_limit(1800);
        $result = $importer->handleDmsFile(
            $request,
            $workspace,
            $list,
            $data['file'],
            $data['type'],
            $data['filter'] ?? null,
            $lookupKey,
        );

        return back()->with('info', sprintf(
            'processed: %s - valid : %s - filtered : %s',
            number_format($result['processed']),
            number_format($result['valid']),
            number_format($result['filtered']),
        ));
    }

    public function downloadImportExample(Request $request, string $workspaceId): StreamedResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);

        return response()->streamDownload(static function (): void {
            echo "sample.one@example.test,Sample,One,US,30,unspecified,example\n";
            echo "sample.two@example.test,Sample,Two,GB,,unspecified,example\n";
        }, 'alphalink-list-import-example.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create(Request $request, string $workspaceId, int $ispId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $isp = $workspace->isps()->whereKey($ispId)->firstOrFail();

        return view('console.infrastructure.lists.form', [
            'workspace' => $workspace,
            'isp' => $isp,
            'list' => null,
        ]);
    }

    public function edit(Request $request, string $workspaceId, string $listId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $list = $workspace->audienceLists()->whereKey($listId)->firstOrFail();

        return view('console.infrastructure.lists.form', [
            'workspace' => $workspace,
            'isp' => $workspace->isps()->whereKey($list->isp_id)->firstOrFail(),
            'list' => $list,
        ]);
    }

    public function store(Request $request, string $workspaceId, int $ispId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $list = DB::transaction(function () use ($request, $workspace, $ispId): AudienceList {
            $isp = $workspace->isps()->whereKey($ispId)->lockForUpdate()->firstOrFail();
            $data = $this->validatedList($request, $isp);
            $name = $data['name'];
            $slug = Str::slug($name) ?: 'list';
            while ($workspace->audienceLists()->where('slug', $slug)->exists()) {
                $slug = Str::limit(Str::slug($name), 145, '').'-'.Str::lower(Str::random(8));
            }

            return $workspace->audienceLists()->create([
                'created_by' => $request->user()->getKey(),
                'name' => $name,
                'slug' => $slug,
                'description' => null,
                'status' => 'active',
                'idempotency_key' => (string) Str::uuid(),
                'request_fingerprint' => hash('sha256', json_encode([$isp->getKey(), $name, $data['country'] ?? null], JSON_THROW_ON_ERROR)),
                'isp_id' => $isp->getKey(),
                'country' => $data['country'] ?? null,
                'allowed_users' => $isp->allowed_users ?? [],
                'allowed_sponsors' => $isp->allowed_sponsors ?? [],
                'is_tracked' => true,
                'is_lead_locked' => true,
            ]);
        });
        $this->audit($workspace, $request, 'audience_list.created', $list);

        return response()->json(['data' => 'List created']);
    }

    public function update(Request $request, string $workspaceId, string $listId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $list = $workspace->audienceLists()->whereKey($listId)->firstOrFail();
        $isp = $workspace->isps()->whereKey($list->isp_id)->firstOrFail();
        $data = $this->validatedList($request, $isp);
        $list->forceFill(['name' => $data['name'], 'country' => $data['country'] ?? null])->save();
        $this->audit($workspace, $request, 'audience_list.updated', $list);

        return response()->json(['data' => 'List updated']);
    }

    public function setStatus(Request $request, string $workspaceId, string $listId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $list = $workspace->audienceLists()->whereKey($listId)->firstOrFail();
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $list->forceFill(['status' => $data['status'] ? 'active' : 'inactive'])->save();
        $this->audit($workspace, $request, 'audience_list.status_changed', $list, ['is_active' => (bool) $data['status']]);

        return response()->json(['data' => 'Changed successfully']);
    }

    public function setTracking(Request $request, string $workspaceId, string $listId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $list = $workspace->audienceLists()->whereKey($listId)->firstOrFail();
        $data = $request->validate(['track' => ['required', 'boolean']]);
        $list->forceFill(['is_tracked' => (bool) $data['track']])->save();
        $this->audit($workspace, $request, 'audience_list.tracking_changed', $list, ['is_tracked' => (bool) $data['track']]);

        return response()->json(['data' => 'Changed successfully']);
    }

    public function setLock(Request $request, string $workspaceId, string $listId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $list = $workspace->audienceLists()->whereKey($listId)->firstOrFail();
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(self::LOCK_TYPES)],
            'status' => ['required', 'boolean'],
        ]);
        $column = 'is_'.$data['type'].'_locked';
        $list->forceFill([$column => (bool) $data['status']])->save();
        $this->audit($workspace, $request, 'audience_list.lock_changed', $list, [
            'type' => $data['type'],
            'is_locked' => (bool) $data['status'],
        ]);

        return response()->json(['data' => $data['status'] ? 'Locked successfully' : 'Unlocked successfully']);
    }

    public function assignment(Request $request, string $workspaceId, string $listId, string $type): View
    {
        abort_unless(in_array($type, ['users', 'sponsors'], true), 404);
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $list = $workspace->audienceLists()->whereKey($listId)->firstOrFail();
        $items = $type === 'users'
            ? $workspace->users()->wherePivot('role', 'operator')->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])
            : $workspace->sponsors()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'affiliate_id']);

        return view('console.infrastructure.lists.assignment', [
            'workspace' => $workspace,
            'list' => $list,
            'items' => $items,
            'type' => $type,
            'selectedIds' => $type === 'users' ? ($list->allowed_users ?? []) : ($list->allowed_sponsors ?? []),
        ]);
    }

    public function bulkAssignment(Request $request, string $workspaceId, string $type): View
    {
        abort_unless(in_array($type, ['users', 'sponsors'], true), 404);
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $lists = $workspace->audienceLists()->whereKey($data['ids'])->orderBy('name')->get();
        abort_unless($lists->count() === count($data['ids']), 404);
        $items = $type === 'users'
            ? $workspace->users()->wherePivot('role', 'operator')->orderBy('users.name')->get(['users.id', 'users.name', 'users.email'])
            : $workspace->sponsors()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'affiliate_id']);
        $column = $type === 'users' ? 'allowed_users' : 'allowed_sponsors';
        $firstSelection = $lists->first()?->{$column} ?? [];
        $allSelectionsMatch = $lists->every(fn (AudienceList $list): bool => ($list->{$column} ?? []) === $firstSelection);

        return view('console.infrastructure.lists.assignment', [
            'workspace' => $workspace,
            'list' => null,
            'lists' => $lists,
            'items' => $items,
            'type' => $type,
            'selectedIds' => $allSelectionsMatch ? $firstSelection : [],
            'mixedSelection' => ! $allSelectionsMatch,
        ]);
    }

    public function updateAssignment(Request $request, string $workspaceId, string $listId, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['users', 'sponsors'], true), 404);
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $list = $workspace->audienceLists()->whereKey($listId)->firstOrFail();
        $field = $type === 'users' ? 'users' : 'sponsors';
        $exists = $type === 'users'
            ? Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->getKey())->where('role', 'operator')
            : Rule::exists('workspace_sponsors', 'id')->where('workspace_id', $workspace->getKey())->where('is_active', true)->whereNull('deleted_at');
        $data = $request->validate([
            $field => ['nullable', 'array'],
            $field.'.*' => ['required', 'integer', 'distinct', $exists],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data[$field] ?? [])));
        $column = $type === 'users' ? 'allowed_users' : 'allowed_sponsors';
        $list->forceFill([$column => $ids])->save();
        $this->audit($workspace, $request, 'audience_list.allowed_'.$type.'_updated', $list, [
            'ids' => $ids,
        ]);

        return response()->json(['data' => 'Updated successfully']);
    }

    public function updateBulkAssignment(Request $request, string $workspaceId, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['users', 'sponsors'], true), 404);
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $field = $type === 'users' ? 'users' : 'sponsors';
        $exists = $type === 'users'
            ? Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->getKey())->where('role', 'operator')
            : Rule::exists('workspace_sponsors', 'id')->where('workspace_id', $workspace->getKey())->where('is_active', true)->whereNull('deleted_at');
        $data = $request->validate([
            'lists' => ['required', 'array', 'min:1'],
            'lists.*' => ['required', 'uuid', 'distinct'],
            $field => ['nullable', 'array'],
            $field.'.*' => ['required', 'integer', 'distinct', $exists],
        ]);
        $lists = $workspace->audienceLists()->whereKey($data['lists'])->orderBy('id')->get();
        abort_unless($lists->count() === count($data['lists']), 404);
        $ids = array_values(array_unique(array_map('intval', $data[$field] ?? [])));
        $column = $type === 'users' ? 'allowed_users' : 'allowed_sponsors';
        DB::transaction(function () use ($lists, $column, $ids, $workspace, $request, $type): void {
            foreach ($lists as $list) {
                $list->forceFill([$column => $ids])->save();
                $this->audit($workspace, $request, 'audience_list.allowed_'.$type.'_updated', $list, [
                    'ids' => $ids,
                    'bulk' => true,
                ]);
            }
        });

        return response()->json(['data' => 'Updated successfully']);
    }

    /** @return array{name: string, country?: string|null} */
    private function validatedList(Request $request, Isp $isp): array
    {
        return $request->validate([
            'name' => ['required', 'alpha_dash', 'min:2', 'max:160'],
            'country' => ['nullable', 'string', Rule::in($isp->tracked_countries ?? [])],
        ]);
    }

    /** @param array<string, mixed> $properties */
    private function audit(Workspace $workspace, Request $request, string $action, AudienceList $list, array $properties = []): void
    {
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => $action,
            'subject_type' => 'audience_list',
            'subject_id' => (string) $list->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => $properties,
            'occurred_at' => now(),
        ]);
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        return $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
    }

    private function authorizeManager(Workspace $workspace): void
    {
        abort_unless(in_array($workspace->pivot->role, self::MANAGE_ROLES, true), 403);
    }
}
