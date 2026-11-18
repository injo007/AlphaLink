<?php

namespace App\Services\Authorization;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

class WorkspaceAccess
{
    /** @return Collection<int, Workspace> */
    public function all(User $user): Collection
    {
        $workspaces = $user->hasFullAccess()
            ? Workspace::query()->where('status', 'active')->orderBy('name')->get()
            : $user->workspaces()->where('workspaces.status', 'active')->orderBy('workspaces.name')->get();

        if ($user->hasFullAccess()) {
            $workspaces->each(fn (Workspace $workspace) => $workspace->setRelation(
                'pivot',
                new Pivot(['role' => $user->access_role]),
            ));
        }

        return $workspaces;
    }

    /** @param array<int, string>|null $roles */
    public function find(User $user, string $workspaceId, ?array $roles = null): Workspace
    {
        if ($user->hasFullAccess()) {
            $workspace = Workspace::query()
                ->where('status', 'active')
                ->whereKey($workspaceId)
                ->firstOrFail();

            $workspace->setRelation('pivot', new Pivot(['role' => $user->access_role]));

            return $workspace;
        }

        $workspace = $user->workspaces()
            ->where('workspaces.status', 'active')
            ->whereKey($workspaceId)
            ->firstOrFail();

        if ($roles !== null) {
            abort_unless(in_array($workspace->pivot->role, $roles, true), 403);
        }

        return $workspace;
    }
}
