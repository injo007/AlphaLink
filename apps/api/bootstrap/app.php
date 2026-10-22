<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureWorkspaceMember;
use App\Http\Middleware\EnsureWorkspaceRole;
use App\Models\ManagedServerHistory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('servers:collect-health')
            ->everyTenMinutes()
            ->withoutOverlapping()
            ->runInBackground();
        $schedule->command('model:prune', ['--model' => ManagedServerHistory::class])
            ->daily()
            ->runInBackground();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(AssignRequestId::class);
        $middleware->alias([
            'workspace.member' => EnsureWorkspaceMember::class,
            'workspace.role' => EnsureWorkspaceRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['root_password', 'ssh_private_key']);
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
