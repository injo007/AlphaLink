<?php

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
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\AssignRequestId::class);
        $middleware->alias([
            'workspace.member' => \App\Http\Middleware\EnsureWorkspaceMember::class,
            'workspace.role' => \App\Http\Middleware\EnsureWorkspaceRole::class,
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
