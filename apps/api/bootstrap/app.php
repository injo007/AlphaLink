<?php

use App\Console\Commands\RunAppHealthChecks;
use App\Console\Commands\SyncWorkspaceSponsorLeads;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureWorkspaceMember;
use App\Http\Middleware\EnsureWorkspaceRole;
use App\Http\Middleware\RedirectLegacyWorkspaceUrl;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Middleware\RewriteLegacyWorkspaceUrls;
use App\Models\ManagedServerHistory;
use App\Models\ManagedServerIpHistory;
use App\Models\PmtaActionHistory;
use App\Models\PmtaScheduledAction;
use App\Models\SendingDomainHistory;
use App\Services\PmtaScheduleFrequency;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
        $schedule->command(RunAppHealthChecks::class)
            ->everyMinute()
            ->withoutOverlapping();
        $schedule->command('servers:collect-health')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('model:prune', ['--model' => ManagedServerHistory::class])
            ->daily()
            ->runInBackground();
        $schedule->command('model:prune', ['--model' => ManagedServerIpHistory::class])
            ->daily()
            ->runInBackground();
        $schedule->command('model:prune', ['--model' => SendingDomainHistory::class])
            ->daily()
            ->runInBackground();
        $schedule->command('model:prune', ['--model' => PmtaActionHistory::class])
            ->daily()
            ->runInBackground();
        $schedule->command('workspace:offers-refresh')
            ->dailyAt('04:00')
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('audience-lists:calculate-history')
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('workspace:analytics-warm-dashboard')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();
        if (Schema::hasTable('campaign_lead_conversions') && Schema::hasTable('workspace_sponsors')) {
            $schedule->command(SyncWorkspaceSponsorLeads::class)
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->runInBackground();
            $schedule->command(SyncWorkspaceSponsorLeads::class, ['--days' => 10])
                ->dailyAt('06:04')
                ->withoutOverlapping()
                ->runInBackground();
        }

        if (config('infrastructure.ip_history_enabled')) {
            $schedule->command('servers:collect-ip-history')
                ->hourly()
                ->withoutOverlapping()
                ->runInBackground();
        }

        $schedule->command('domain:check_history')
            ->dailyAt('08:15')
            ->timezone('Africa/Casablanca')
            ->when(fn (): bool => (bool) config('infrastructure.domain_history_enabled'))
            ->runInBackground();

        if (Schema::hasTable('pmta_scheduled_actions')) {
            $frequencies = app(PmtaScheduleFrequency::class);
            PmtaScheduledAction::query()->where('is_active', true)->orderBy('id')->each(function (PmtaScheduledAction $action) use ($schedule, $frequencies): void {
                try {
                    $event = $schedule->command('pmta:run-scheduled-action', [$action->getKey()])
                        ->cron($frequencies->expression($action->frequency))
                        ->name('pmta-scheduled-action-'.$action->getKey())
                        ->runInBackground();
                    if ($frequencies->isLastDay($action->frequency)) {
                        $event->when(fn (): bool => now()->isLastOfMonth());
                    }
                } catch (Throwable) {
                    // Invalid stored frequency values are ignored until an operator corrects the task.
                }
            });
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);
        $middleware->appendToGroup('web', RewriteLegacyWorkspaceUrls::class);
        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'workspace.member' => EnsureWorkspaceMember::class,
            'workspace.role' => EnsureWorkspaceRole::class,
            'workspace.current' => ResolveCurrentWorkspace::class,
            'workspace.legacy-redirect' => RedirectLegacyWorkspaceUrl::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['root_password', 'ssh_private_key', 'cloud_servers_password']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// Allow deployments to load secrets from a protected directory outside this
// shared workspace. Laravel reads its environment file there during startup.
$environmentDirectory = getenv('ALPHALINK_ENV_DIR');
if (is_string($environmentDirectory) && $environmentDirectory !== '') {
    $application->useEnvironmentPath($environmentDirectory);
}

return $application;
