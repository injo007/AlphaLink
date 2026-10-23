<?php

use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\InfrastructureConsoleController;
use App\Http\Controllers\Web\WorkspaceConsoleController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'console.index' : 'login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/sign-in', [AuthController::class, 'create'])->name('login');
    Route::post('/sign-in', [AuthController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/sign-out', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/app', [WorkspaceConsoleController::class, 'index'])->name('console.index');
    Route::get('/app/workspaces/{workspace}', [WorkspaceConsoleController::class, 'show'])->name('console.workspace');
    Route::get('/app/workspaces/{workspace}/campaigns', [WorkspaceConsoleController::class, 'campaigns'])->name('console.campaigns.index');
    Route::get('/app/workspaces/{workspace}/campaigns/{campaign}', [WorkspaceConsoleController::class, 'campaign'])->name('console.campaigns.show');
    Route::get('/app/workspaces/{workspace}/audience-lists', [WorkspaceConsoleController::class, 'audienceLists'])->name('console.audiences.index');
    Route::get('/app/workspaces/{workspace}/audience-lists/{audienceList}', [WorkspaceConsoleController::class, 'audienceList'])->name('console.audiences.show');
    Route::get('/app/workspaces/{workspace}/infrastructure', [InfrastructureConsoleController::class, 'index'])->name('console.infrastructure.index');
    Route::get('/app/workspaces/{workspace}/infrastructure/bulk-install', [InfrastructureConsoleController::class, 'bulkInstall'])->name('console.infrastructure.bulk-install');
    Route::get('/app/workspaces/{workspace}/infrastructure/bulk-install/runs/{runId}/logs', [InfrastructureConsoleController::class, 'bulkInstallLogs'])
        ->whereUuid('runId')->name('console.infrastructure.bulk-install.logs');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers', [InfrastructureConsoleController::class, 'serversIndex'])->name('console.infrastructure.servers.index');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers/{server}/history', [InfrastructureConsoleController::class, 'serverHistory'])->name('console.infrastructure.servers.history');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers/create', [InfrastructureConsoleController::class, 'createServer'])->name('console.infrastructure.servers.create');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers/bulk-users', [InfrastructureConsoleController::class, 'bulkServerUsers'])->name('console.infrastructure.servers.bulk-users');
    Route::put('/app/workspaces/{workspace}/infrastructure/servers/bulk-users', [InfrastructureConsoleController::class, 'updateBulkServerUsers'])->name('console.infrastructure.servers.bulk-users.update');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers/{server}/users', [InfrastructureConsoleController::class, 'serverUsers'])->name('console.infrastructure.servers.users');
    Route::put('/app/workspaces/{workspace}/infrastructure/servers/{server}/users', [InfrastructureConsoleController::class, 'updateServerUsers'])->name('console.infrastructure.servers.users.update');
    Route::patch('/app/workspaces/{workspace}/infrastructure/servers/{server}/lock', [InfrastructureConsoleController::class, 'setServerLock'])->name('console.infrastructure.servers.lock');
    Route::patch('/app/workspaces/{workspace}/infrastructure/servers/{server}', [InfrastructureConsoleController::class, 'setServerStatus'])->name('console.infrastructure.servers.status');
    Route::delete('/app/workspaces/{workspace}/infrastructure/servers', [InfrastructureConsoleController::class, 'returnServers'])->name('console.infrastructure.servers.return');
    Route::patch('/app/workspaces/{workspace}/infrastructure/servers/{server}/restore', [InfrastructureConsoleController::class, 'restoreServer'])->name('console.infrastructure.servers.restore');
    Route::post('/app/workspaces/{workspace}/infrastructure/servers/test-connection', [InfrastructureConsoleController::class, 'testServerConnection'])->middleware('throttle:10,1')->name('console.infrastructure.servers.test-connection');
    Route::post('/app/workspaces/{workspace}/infrastructure/servers', [InfrastructureConsoleController::class, 'storeServer'])->middleware('throttle:10,1')->name('console.infrastructure.servers.store');
    Route::post('/app/workspaces/{workspace}/infrastructure/servers/{server}/preflight', [InfrastructureConsoleController::class, 'preflightServer'])->middleware('throttle:3,1')->name('console.infrastructure.servers.preflight');
    Route::post('/app/workspaces/{workspace}/infrastructure/domains', [InfrastructureConsoleController::class, 'storeDomain'])->middleware('throttle:10,1')->name('console.infrastructure.domains.store');
    Route::post('/app/workspaces/{workspace}/infrastructure/domains/{domain}/verify', [InfrastructureConsoleController::class, 'verifyDomain'])->middleware('throttle:10,1')->name('console.infrastructure.domains.verify');
    Route::post('/app/workspaces/{workspace}/infrastructure/plans', [InfrastructureConsoleController::class, 'storePlan'])->middleware('throttle:10,1')->name('console.infrastructure.plans.store');
});
