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
    Route::post('/app/workspaces/{workspace}/infrastructure/servers', [InfrastructureConsoleController::class, 'storeServer'])->middleware('throttle:10,1')->name('console.infrastructure.servers.store');
    Route::post('/app/workspaces/{workspace}/infrastructure/servers/{server}/host-key', [InfrastructureConsoleController::class, 'discoverHostKey'])->middleware('throttle:3,1')->name('console.infrastructure.servers.host-key');
    Route::post('/app/workspaces/{workspace}/infrastructure/servers/{server}/preflight', [InfrastructureConsoleController::class, 'preflightServer'])->middleware('throttle:3,1')->name('console.infrastructure.servers.preflight');
    Route::post('/app/workspaces/{workspace}/infrastructure/domains', [InfrastructureConsoleController::class, 'storeDomain'])->middleware('throttle:10,1')->name('console.infrastructure.domains.store');
    Route::post('/app/workspaces/{workspace}/infrastructure/domains/{domain}/verify', [InfrastructureConsoleController::class, 'verifyDomain'])->middleware('throttle:10,1')->name('console.infrastructure.domains.verify');
    Route::post('/app/workspaces/{workspace}/infrastructure/plans', [InfrastructureConsoleController::class, 'storePlan'])->middleware('throttle:10,1')->name('console.infrastructure.plans.store');
});
