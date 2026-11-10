<?php

namespace App\Services\Campaigns;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AdminOperatorCampaignFilter
{
    /**
     * @return array{
     *     is_admin: bool,
     *     enabled: bool,
     *     operators: Collection<int, User>,
     *     selected_operator_id: int|null,
     *     selected_operator: User|null,
     *     creator_ids: Collection<int, int|string>
     * }
     */
    public function resolve(Request $request, Workspace $workspace, User $user): array
    {
        $filters = $request->validate([
            'view_operator_campaigns' => ['sometimes', 'boolean'],
            'operator_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $isAdmin = $user->access_role === User::ACCESS_ROLE_ADMIN;
        $operators = $isAdmin
            ? $workspace->users()
                ->wherePivot('role', User::ACCESS_ROLE_OPERATOR)
                ->orderBy('users.name')
                ->orderBy('users.id')
                ->get(['users.id', 'users.name', 'users.username'])
            : collect();
        $enabled = $isAdmin && (bool) ($filters['view_operator_campaigns'] ?? false);
        $selectedOperatorId = $enabled && filled($filters['operator_id'] ?? null)
            ? (int) $filters['operator_id']
            : null;

        $selectedOperator = $selectedOperatorId === null
            ? null
            : $operators->first(fn (User $operator): bool => (int) $operator->getKey() === $selectedOperatorId);

        if ($selectedOperatorId !== null && ! $selectedOperator) {
            throw ValidationException::withMessages([
                'operator_id' => 'Choose an operator from this workspace.',
            ]);
        }

        $creatorIds = $enabled
            ? ($selectedOperatorId === null ? $operators->modelKeys() : [$selectedOperatorId])
            : [$user->getKey()];

        return [
            'is_admin' => $isAdmin,
            'enabled' => $enabled,
            'operators' => $operators,
            'selected_operator_id' => $selectedOperatorId,
            'selected_operator' => $selectedOperator,
            'creator_ids' => collect($creatorIds),
        ];
    }
}
