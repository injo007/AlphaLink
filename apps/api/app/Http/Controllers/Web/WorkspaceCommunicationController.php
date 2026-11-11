<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCommunication;
use App\Models\WorkspaceCommunicationRecipient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WorkspaceCommunicationController extends Controller
{
    public function index(Request $request, string $workspace): View
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($request->user());

        $selectedOperatorId = $request->integer('operator_id') ?: null;
        if ($selectedOperatorId !== null) {
            $this->operatorFor($workspace, $selectedOperatorId);
        }

        $communications = WorkspaceCommunication::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereNull('archived_at')
            ->with(['author:id,name,username', 'targetOperator:id,name,username'])
            ->withCount([
                'recipients as recipient_count',
                'recipients as unread_count' => fn ($query) => $query->whereNull('read_at'),
                'recipients as open_task_count' => fn ($query) => $query->whereNull('completed_at'),
            ])
            ->latest()
            ->paginate(12)
            ->withQueryString();

        $operators = $workspace->users()
            ->where('users.access_role', User::ACCESS_ROLE_OPERATOR)
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.username']);

        return view('console.workspace-communications.index', [
            'workspace' => $workspace,
            'communications' => $communications,
            'operators' => $operators,
            'selectedOperatorId' => $selectedOperatorId,
        ]);
    }

    public function store(Request $request, string $workspace): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($request->user());

        $data = $request->validate([
            'kind' => ['required', Rule::in(['task', 'message', 'notification'])],
            'title' => ['required', 'string', 'min:3', 'max:140'],
            'body' => ['required', 'string', 'min:3', 'max:5000'],
            'audience' => ['required', Rule::in(['team', 'operator'])],
            'operator_id' => ['required_if:audience,operator', 'nullable', 'integer', Rule::exists('users', 'id')->where('access_role', User::ACCESS_ROLE_OPERATOR)->where('is_active', true)],
            'due_at' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $targetOperator = $data['audience'] === 'operator'
            ? $this->operatorFor($workspace, (int) $data['operator_id'])
            : null;
        $recipients = $targetOperator
            ? collect([$targetOperator])
            : $workspace->users()
                ->where('users.is_active', true)
                ->whereIn('users.access_role', [User::ACCESS_ROLE_OWNER, User::ACCESS_ROLE_ADMIN, User::ACCESS_ROLE_OPERATOR])
                ->get(['users.id']);

        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages(['audience' => 'There are no active team members eligible to receive this update.']);
        }

        DB::transaction(function () use ($request, $workspace, $data, $targetOperator, $recipients): void {
            $communication = WorkspaceCommunication::query()->create([
                'workspace_id' => $workspace->getKey(),
                'created_by_id' => $request->user()->getKey(),
                'target_operator_id' => $targetOperator?->getKey(),
                'kind' => $data['kind'],
                'audience' => $data['audience'],
                'title' => $data['title'],
                'body' => $data['body'],
                'due_at' => $data['kind'] === 'task' ? ($data['due_at'] ?? null) : null,
            ]);

            $communication->recipients()->createMany($recipients->map(fn (User $user): array => [
                'user_id' => $user->getKey(),
            ])->all());
        });

        return redirect()->route('clean.console.workspace-communications.index')
            ->with('communication_status', 'Your update is now available to the selected team members.');
    }

    public function feed(Request $request, string $workspace): JsonResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $user = $request->user();
        $baseQuery = WorkspaceCommunicationRecipient::query()
            ->where('user_id', $user->getKey())
            ->whereHas('communication', fn ($query) => $query
                ->where('workspace_id', $workspace->getKey())
                ->whereNull('archived_at'));

        $unreadNotificationCount = (clone $baseQuery)
            ->whereNull('read_at')
            ->whereHas('communication', fn ($query) => $query->where('kind', 'notification'))
            ->count();
        $unreadInboxCount = (clone $baseQuery)
            ->whereNull('read_at')
            ->whereHas('communication', fn ($query) => $query->whereIn('kind', ['task', 'message']))
            ->count();
        $openTaskCount = (clone $baseQuery)
            ->whereNull('completed_at')
            ->whereHas('communication', fn ($query) => $query->where('kind', 'task'))
            ->count();
        $items = $baseQuery
            ->with(['communication.author:id,name,username'])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        $serialize = static fn (WorkspaceCommunicationRecipient $recipient): array => [
            'id' => $recipient->communication->getKey(),
            'title' => $recipient->communication->title,
            'body' => $recipient->communication->body,
            'kind' => $recipient->communication->kind,
            'author' => $recipient->communication->author?->name ?? 'Workspace admin',
            'created_at' => $recipient->communication->created_at?->toIso8601String(),
            'due_at' => $recipient->communication->due_at?->toIso8601String(),
            'read' => $recipient->read_at !== null,
            'completed' => $recipient->completed_at !== null,
        ];

        return response()->json([
            'unread_notification_count' => $unreadNotificationCount,
            'unread_inbox_count' => $unreadInboxCount,
            'open_task_count' => $openTaskCount,
            'inbox' => $items->filter(fn (WorkspaceCommunicationRecipient $item): bool => in_array($item->communication->kind, ['task', 'message'], true))
                ->take(15)->map($serialize)->values(),
            'notifications' => $items->filter(fn (WorkspaceCommunicationRecipient $item): bool => $item->communication->kind === 'notification')
                ->take(15)->map($serialize)->values(),
        ]);
    }

    public function markRead(Request $request, string $workspace, int $communication): JsonResponse
    {
        $recipient = $this->recipientFor($request, $workspace, $communication);
        $recipient->forceFill(['read_at' => $recipient->read_at ?? now()])->save();

        return response()->json(['data' => 'Marked as read']);
    }

    public function complete(Request $request, string $workspace, int $communication): JsonResponse
    {
        $recipient = $this->recipientFor($request, $workspace, $communication);
        abort_unless($recipient->communication->kind === 'task', 404);
        $recipient->forceFill(['read_at' => $recipient->read_at ?? now(), 'completed_at' => $recipient->completed_at ?? now()])->save();

        return response()->json(['data' => 'Task completed']);
    }

    public function archive(Request $request, string $workspace, int $communication): RedirectResponse
    {
        $workspace = $this->workspaceFor($request->user(), $workspace);
        $this->authorizeManager($request->user());
        WorkspaceCommunication::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($communication)
            ->whereNull('archived_at')
            ->firstOrFail()
            ->forceFill(['archived_at' => now()])
            ->save();

        return redirect()->route('clean.console.workspace-communications.index')
            ->with('communication_status', 'The update has been archived.');
    }

    private function workspaceFor(User $user, string $workspaceId): Workspace
    {
        return $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();
    }

    private function operatorFor(Workspace $workspace, int $operatorId): User
    {
        return $workspace->users()
            ->whereKey($operatorId)
            ->where('users.access_role', User::ACCESS_ROLE_OPERATOR)
            ->where('users.is_active', true)
            ->firstOrFail();
    }

    private function recipientFor(Request $request, string $workspaceId, int $communicationId): WorkspaceCommunicationRecipient
    {
        $workspace = $this->workspaceFor($request->user(), $workspaceId);

        return WorkspaceCommunicationRecipient::query()
            ->where('user_id', $request->user()->getKey())
            ->whereHas('communication', fn ($query) => $query
                ->whereKey($communicationId)
                ->where('workspace_id', $workspace->getKey())
                ->whereNull('archived_at'))
            ->with('communication')
            ->firstOrFail();
    }

    private function authorizeManager(User $user): void
    {
        abort_unless($user->hasFullAccess(), 403);
    }
}
