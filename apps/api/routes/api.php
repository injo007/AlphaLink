<?php

use App\Http\Controllers\Api\V1\AudienceListController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\CampaignRedirectController;
use App\Http\Controllers\Api\V1\CampaignTrackingController;
use App\Http\Controllers\Api\V1\CampaignUnsubscribeController;
use App\Http\Controllers\Api\V1\ConsentRecordController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DropTrackingController;
use App\Http\Controllers\Api\V1\InfrastructureController;
use App\Http\Controllers\Api\V1\PublicLandingController;
use App\Http\Controllers\Api\V1\SuppressionEventController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'up']));
    Route::post('/subscribe', [PublicLandingController::class, 'subscribe'])->middleware('throttle:2,1');
    Route::post('/contact', [PublicLandingController::class, 'contact'])->middleware('throttle:2,1');
    Route::post('/opt-out', [PublicLandingController::class, 'optOut'])->middleware('throttle:2,1');
    Route::post('/tracking/campaign-events', [CampaignTrackingController::class, 'store']);
    Route::post('/stats', [CampaignTrackingController::class, 'store']);
    Route::post('/campaign', [CampaignRedirectController::class, 'store']);
    Route::match(['get', 'post'], '/campaigns/{campaign}/{item}/unsubscribe', [CampaignUnsubscribeController::class, 'unsubscribe'])
        ->whereUuid('campaign')
        ->whereUuid('item')
        ->middleware('signed')
        ->name('public.campaign-unsubscribe');
    Route::post('/drop', [DropTrackingController::class, 'redirect']);
    Route::post('/stats-drop', [DropTrackingController::class, 'store']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/me', fn () => response()->json([
            'data' => request()->user()->only(['id', 'name', 'email']),
        ]));

        Route::get('/workspaces', [WorkspaceController::class, 'index']);
        Route::post('/workspaces', [WorkspaceController::class, 'store'])->middleware('throttle:10,1');

        Route::prefix('/workspaces/{workspace}')
            ->middleware('workspace.member')
            ->group(function (): void {
                Route::middleware('workspace.role:owner,admin,operator,privacy_officer')->group(function (): void {
                    Route::get('/audience-lists', [AudienceListController::class, 'index'])->middleware('throttle:60,1');
                    Route::get('/audience-lists/{list}', [AudienceListController::class, 'show'])->middleware('throttle:60,1');
                    Route::get('/audience-lists/{list}/members', [AudienceListController::class, 'members'])->middleware('throttle:60,1');
                    Route::get('/audience-lists/{list}/members/{contact}/events', [AudienceListController::class, 'membershipEvents'])->middleware('throttle:60,1');
                    Route::get('/contacts', [ContactController::class, 'index'])->middleware('throttle:60,1');
                    Route::get('/contacts/{contact}', [ContactController::class, 'show'])->middleware('throttle:60,1');
                    Route::post('/contacts', [ContactController::class, 'store'])->middleware('throttle:30,1');
                    Route::get('/contacts/{contact}/consent-records', [ConsentRecordController::class, 'index'])->middleware('throttle:60,1');
                    Route::post('/contacts/{contact}/consent-records', [ConsentRecordController::class, 'store'])->middleware('throttle:30,1');
                    Route::get('/contacts/{contact}/suppression-events', [SuppressionEventController::class, 'index'])->middleware('throttle:60,1');
                });

                Route::middleware('workspace.role:owner,admin,operator')->group(function (): void {
                    Route::post('/audience-lists', [AudienceListController::class, 'store'])->middleware('throttle:30,1');
                    Route::post('/audience-lists/{list}/members', [AudienceListController::class, 'addMember'])->middleware('throttle:30,1');
                    Route::post('/audience-lists/{list}/members/import', [AudienceListController::class, 'importMembers'])->middleware('throttle:10,1');
                    Route::get('/audience-lists/{list}/members/imports/{batch}', [AudienceListController::class, 'importStatus'])->middleware('throttle:60,1');
                    Route::post('/audience-lists/{list}/members/{contact}/remove', [AudienceListController::class, 'removeMember'])->middleware('throttle:30,1');
                });

                Route::post('/contacts/{contact}/suppressions', [SuppressionEventController::class, 'store'])
                    ->middleware(['workspace.role:owner,admin,privacy_officer', 'throttle:30,1']);

                Route::middleware('workspace.role:owner,admin,operator')->group(function (): void {
                    Route::get('/campaigns', [CampaignController::class, 'index']);
                    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
                    Route::post('/campaigns', [CampaignController::class, 'store'])->middleware('throttle:20,1');
                    Route::post('/campaigns/{campaign}/revisions', [CampaignController::class, 'revise'])->middleware('throttle:20,1');
                });

                Route::middleware('workspace.role:owner,admin')->group(function (): void {
                    Route::get('/infrastructure', [InfrastructureController::class, 'index']);
                    Route::post('/servers/test-connection', [InfrastructureController::class, 'testServerConnection'])->middleware('throttle:10,1');
                    Route::post('/servers', [InfrastructureController::class, 'storeServer'])->middleware('throttle:10,1');
                    Route::post('/servers/{server}/preflight', [InfrastructureController::class, 'preflightServer'])->middleware('throttle:3,1');
                    Route::post('/domains', [InfrastructureController::class, 'storeDomain'])->middleware('throttle:10,1');
                    Route::post('/domains/{domain}/verify', [InfrastructureController::class, 'verifyDomain'])->middleware('throttle:10,1');
                    Route::post('/installation-plans', [InfrastructureController::class, 'storePlan'])->middleware('throttle:10,1');
                });
            });
    });
});
