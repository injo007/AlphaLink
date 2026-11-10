<?php

namespace App\Services\Authorization;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccessRoleAssignment
{
    public function assign(User $actor, User $target, string $role): void
    {
        abort_unless(in_array($role, User::ACCESS_ROLES, true), 422, 'Choose a valid access role.');

        DB::transaction(function () use ($actor, $target, $role): void {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $lockedTarget = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
            $currentOwner = User::query()->where('access_role', User::ACCESS_ROLE_OWNER)->lockForUpdate()->first();

            if ($role === User::ACCESS_ROLE_OWNER) {
                abort_unless($lockedActor->isOwner(), 403, 'Only the current Owner can transfer ownership.');
                if ($currentOwner && ! $currentOwner->is($lockedTarget)) {
                    $this->saveRole($currentOwner, User::ACCESS_ROLE_ADMIN);
                }
            } elseif ($currentOwner?->is($lockedTarget)) {
                throw new HttpException(409, 'Transfer Owner to another account before changing this role.');
            }

            $this->saveRole($lockedTarget, $role);
        });
    }

    private function saveRole(User $user, string $role): void
    {
        $user->forceFill(['access_role' => $role])->save();
        DB::table('workspace_user')->where('user_id', $user->getKey())->update(['role' => $role]);
    }
}
