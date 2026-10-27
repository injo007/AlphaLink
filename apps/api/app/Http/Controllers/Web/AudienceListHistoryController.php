<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AudienceListHistory;
use App\Models\ContactListMembership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AudienceListHistoryController extends Controller
{
    private const MANAGE_ROLES = ['owner', 'admin'];

    public function index(Request $request, string $workspaceId): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);

        return view('console.infrastructure.lists.history', [
            'workspace' => $workspace,
            'isps' => $workspace->isps()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => now()->subDays(6)->toDateString(),
            'to' => now()->toDateString(),
            'minDate' => now()->subYear()->toDateString(),
            'maxDate' => now()->toDateString(),
        ]);
    }

    public function lists(Request $request, string $workspaceId, int $ispId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);
        $isp = $workspace->isps()->where('is_active', true)->whereKey($ispId)->firstOrFail();
        $lists = $isp->lists()
            ->where('workspace_id', $workspace->getKey())
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['data' => $lists]);
    }

    public function history(Request $request, string $workspaceId): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);
        $this->authorizeManager($workspace);

        $data = $request->validate([
            'isp' => ['required', 'integer', 'min:1'],
            'list' => ['required', 'string', 'max:36'],
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->subYear()->toDateString(), 'before_or_equal:today'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
        ]);

        $isp = $workspace->isps()->where('is_active', true)->whereKey($data['isp'])->firstOrFail();
        $listId = $data['list'];

        if ($listId === '0') {
            $list = ['id' => (int) $isp->getKey(), 'name' => $isp->name];
            $histories = AudienceListHistory::query()
                ->where('workspace_id', $workspace->getKey())
                ->whereBetween('at', [$data['from'], $data['to']])
                ->whereIn('audience_list_id', $workspace->audienceLists()->where('isp_id', $isp->getKey())->select('id'))
                ->select('at')
                ->selectRaw('SUM(fresh) as fresh, SUM(delivery) as delivery, SUM(open) as open, SUM(click) as click, SUM(lead) as lead, SUM(unsubscribe) as unsubscribe, SUM(opt_out) as opt_out, SUM(proxy) as proxy, SUM(bot) as bot, SUM(blacklist) as blacklist, SUM(hard_bounce) as hard_bounce')
                ->groupBy('at')
                ->orderBy('at')
                ->get();
        } else {
            if (! Str::isUuid($listId)) {
                throw ValidationException::withMessages(['list' => ['Select a list belonging to this ISP.']]);
            }

            $audienceList = $workspace->audienceLists()
                ->where('isp_id', $isp->getKey())
                ->whereKey($listId)
                ->firstOrFail();
            $list = ['id' => $audienceList->getKey(), 'name' => $audienceList->name];
            $histories = AudienceListHistory::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('audience_list_id', $audienceList->getKey())
                ->whereBetween('at', [$data['from'], $data['to']])
                ->orderBy('at')
                ->get();
        }

        return response()->json([
            'list' => $list,
            'scope' => $listId === '0' ? 'all_lists' : 'single_list',
            'histories' => $histories->map(fn (AudienceListHistory $history): array => [
                'at' => $history->at instanceof Carbon ? $history->at->toDateString() : (string) $history->at,
                ...collect([
                    ...ContactListMembership::CAMPAIGN_TYPES,
                    ...ContactListMembership::HISTORY_TRACKED_TYPES,
                ])
                    ->mapWithKeys(fn (string $type): array => [$type => $history->{$type} === null ? 'NA' : (int) $history->{$type}])
                    ->all(),
            ])->values(),
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
