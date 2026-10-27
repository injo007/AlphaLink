<?php

namespace App\Console\Commands;

use App\Http\Controllers\Web\WorkspaceAnalyticsConsoleController;
use App\Models\User;
use App\Services\Analytics\WorkspaceDailyAnalyticsRebuilder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('workspace:analytics-warm-dashboard')]
#[Description('Warm the current main dashboard metrics for active workspace members')]
class WarmWorkspaceAnalyticsDashboard extends Command
{
    public function handle(
        WorkspaceAnalyticsConsoleController $analytics,
        WorkspaceDailyAnalyticsRebuilder $dailyAnalytics,
    ): int {
        $dailyAnalytics->rebuild();
        $warmed = 0;

        DB::table('workspace_user')
            ->join('workspaces', 'workspaces.id', '=', 'workspace_user.workspace_id')
            ->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->where('workspaces.status', 'active')
            ->where('users.is_active', true)
            ->whereIn('workspace_user.role', WorkspaceAnalyticsConsoleController::ACCESS_ROLES)
            ->select(['workspace_user.workspace_id', 'workspace_user.user_id'])
            ->orderBy('workspace_user.workspace_id')
            ->orderBy('workspace_user.user_id')
            ->chunk(100, function ($members) use ($analytics, &$warmed): void {
                $users = User::query()
                    ->whereIn('id', $members->pluck('user_id')->unique())
                    ->get()
                    ->keyBy('id');

                foreach ($members as $member) {
                    $user = $users->get($member->user_id);
                    if ($user && $analytics->warmMainDashboardCaches($user, (string) $member->workspace_id)) {
                        $warmed++;
                    }
                }
            });

        $this->info("Warmed dashboard metrics for {$warmed} active workspace members.");

        return self::SUCCESS;
    }
}
