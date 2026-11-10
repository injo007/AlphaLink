<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('access_role', 32)->default('viewer')->index();
        });

        $users = DB::table('users')->orderBy('id')->get(['id', 'type']);
        $memberships = DB::table('workspace_user')
            ->orderBy('joined_at')
            ->get(['user_id', 'role'])
            ->groupBy('user_id');

        $ownerId = DB::table('workspace_user')
            ->where('role', 'owner')
            ->orderBy('joined_at')
            ->orderBy('user_id')
            ->value('user_id');

        if ($ownerId === null) {
            $ownerId = $users->firstWhere('type', 'admin')?->id ?? $users->first()?->id;
        }

        foreach ($users as $user) {
            $memberRoles = $memberships->get($user->id, collect())->pluck('role')->unique()->values();
            if ((string) $user->id === (string) $ownerId) {
                $role = 'owner';
            } elseif ($user->type === 'admin') {
                $role = 'admin';
            } elseif ($memberRoles->count() === 1) {
                $role = $memberRoles->first() === 'owner' ? 'admin' : $memberRoles->first();
            } elseif ($memberRoles->isEmpty()) {
                $role = 'viewer';
            } else {
                // A single global role cannot faithfully represent conflicting
                // workspace roles. Start from the safe read-only role.
                $role = 'viewer';
            }

            if (! in_array($role, ['owner', 'admin', 'operator', 'viewer', 'privacy_officer'], true)) {
                $role = 'viewer';
            }

            DB::table('users')->where('id', $user->id)->update(['access_role' => $role]);
            DB::table('workspace_user')->where('user_id', $user->id)->update(['role' => $role]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['access_role']);
            $table->dropColumn('access_role');
        });
    }
};
