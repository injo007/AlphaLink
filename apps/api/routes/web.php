<?php

use App\Http\Controllers\Web\AppSettingsConsoleController;
use App\Http\Controllers\Web\AudienceListHistoryController;
use App\Http\Controllers\Web\AudienceListManagementController;
use App\Http\Controllers\Web\AudienceMixController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CampaignComposerToolsController;
use App\Http\Controllers\Web\CloudProviderConsoleController;
use App\Http\Controllers\Web\CloudWorkflowConsoleController;
use App\Http\Controllers\Web\GlobalUserManagementController;
use App\Http\Controllers\Web\InfrastructureConsoleController;
use App\Http\Controllers\Web\MiscellaneousConsoleController;
use App\Http\Controllers\Web\PmtaConsoleController;
use App\Http\Controllers\Web\ServerProviderConsoleController;
use App\Http\Controllers\Web\WorkspaceAnalyticsConsoleController;
use App\Http\Controllers\Web\WorkspaceBlacklistCheckerController;
use App\Http\Controllers\Web\WorkspaceCampaignHeaderController;
use App\Http\Controllers\Web\WorkspaceCampaignNegativeController;
use App\Http\Controllers\Web\WorkspaceCampaignStatisticsConsoleController;
use App\Http\Controllers\Web\WorkspaceCampaignVisitorsConsoleController;
use App\Http\Controllers\Web\WorkspaceConsoleController;
use App\Http\Controllers\Web\WorkspaceDropsConsoleController;
use App\Http\Controllers\Web\WorkspaceDropSuppressionController;
use App\Http\Controllers\Web\WorkspaceEmailManagementController;
use App\Http\Controllers\Web\WorkspaceEncodingConsoleController;
use App\Http\Controllers\Web\WorkspaceGlobalTestController;
use App\Http\Controllers\Web\WorkspaceOfferConsoleController;
use App\Http\Controllers\Web\WorkspaceSeedListConsoleController;
use App\Http\Controllers\Web\WorkspaceSpfScannerController;
use App\Http\Controllers\Web\WorkspaceSponsorConsoleController;
use App\Http\Controllers\Web\WorkspaceTextMechanicConsoleController;
use App\Http\Controllers\Web\WorkspaceToolConsoleController;
use App\Http\Controllers\Web\WorkspaceUserManagementController;
use App\Http\Controllers\WorkspaceEntryController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(Auth::check() ? 'console.dashboard.main' : 'login'));
Route::get('/o/{workspace}/{offerId}/{assetId}', [WorkspaceOfferConsoleController::class, 'publicAssetFile'])
    ->whereNumber(['offerId', 'assetId'])
    ->name('public.offer-assets.file');

Route::middleware('guest')->group(function (): void {
    Route::get('/sign-in', [AuthController::class, 'create'])->name('login');
    Route::post('/sign-in', [AuthController::class, 'store'])->middleware('throttle:10,1')->name('login.store');
});

Route::middleware(['auth', 'active', 'auth.session'])->group(function (): void {
    Route::post('/sign-out', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/profile', [AuthController::class, 'profile'])->name('auth.profile');
    Route::post('/profile', [AuthController::class, 'changePassword'])
        ->middleware('throttle:10,1')->name('auth.change_password');

    Route::get('/dashboard/main', [WorkspaceEntryController::class, 'dashboard'])
        ->middleware('workspace.current')->name('console.dashboard.main');
    Route::get('/null/dashboard', fn () => redirect()->route('console.dashboard.main'))
        ->middleware('workspace.current')->name('console.dashboard.legacy-null');
    Route::get('/workspaces/{workspaceSlug}/dashboard/main', [WorkspaceEntryController::class, 'dashboard'])
        ->middleware('workspace.current')->name('console.workspace.dashboard.main');
    Route::get('/mailer/campaigns', [WorkspaceEntryController::class, 'campaigns'])
        ->middleware('workspace.current')->name('console.mailer.campaigns');

    Route::get('/app/users', [GlobalUserManagementController::class, 'index'])->name('console.global-users.index');
    Route::get('/app/users/create', [GlobalUserManagementController::class, 'create'])->name('console.global-users.create');
    Route::post('/app/users', [GlobalUserManagementController::class, 'store'])->middleware('throttle:10,1')->name('console.global-users.store');
    Route::post('/app/users/impersonation/leave', [GlobalUserManagementController::class, 'stopImpersonating'])->name('console.global-users.impersonation.leave');
    Route::get('/app/users/{user}/edit', [GlobalUserManagementController::class, 'edit'])->whereNumber('user')->name('console.global-users.edit');
    Route::put('/app/users/{user}', [GlobalUserManagementController::class, 'update'])->whereNumber('user')->middleware('throttle:10,1')->name('console.global-users.update');
    Route::patch('/app/users/{user}/status', [GlobalUserManagementController::class, 'status'])->whereNumber('user')->middleware('throttle:10,1')->name('console.global-users.status');
    Route::get('/app/users/{user}/password', [GlobalUserManagementController::class, 'password'])->whereNumber('user')->name('console.global-users.password');
    Route::put('/app/users/{user}/password', [GlobalUserManagementController::class, 'updatePassword'])->whereNumber('user')->middleware('throttle:10,1')->name('console.global-users.password.update');
    Route::post('/app/users/{user}/impersonation', [GlobalUserManagementController::class, 'startImpersonating'])->whereNumber('user')->name('console.global-users.impersonation.start');

    Route::get('/app', [WorkspaceConsoleController::class, 'index'])->name('console.index');
    Route::get('/app/workspaces/{workspace}', [WorkspaceConsoleController::class, 'show'])->name('console.workspace');
    Route::get('/app/workspaces/{workspace}/analytics', [WorkspaceAnalyticsConsoleController::class, 'index'])->name('console.analytics.index');
    Route::get('/app/workspaces/{workspace}/dashboard/all-stats', [WorkspaceAnalyticsConsoleController::class, 'allStats'])->name('console.analytics.all-stats');
    Route::get('/app/workspaces/{workspace}/dashboard/isp', [WorkspaceAnalyticsConsoleController::class, 'ispStats'])->name('console.analytics.isp-stats');
    Route::get('/app/workspaces/{workspace}/dashboard/mailer', [WorkspaceAnalyticsConsoleController::class, 'mailerRevenue'])->name('console.analytics.mailer-revenue');
    Route::get('/app/workspaces/{workspace}/campaign-statistics', [WorkspaceCampaignStatisticsConsoleController::class, 'index'])->name('console.campaign-statistics.index');
    Route::get('/app/workspaces/{workspace}/users', [WorkspaceUserManagementController::class, 'index'])->name('console.users.index');
    Route::get('/app/workspaces/{workspace}/users/create', [WorkspaceUserManagementController::class, 'create'])->name('console.users.create');
    Route::post('/app/workspaces/{workspace}/users', [WorkspaceUserManagementController::class, 'store'])->middleware('throttle:10,1')->name('console.users.store');
    Route::post('/app/workspaces/{workspace}/users/attach', [WorkspaceUserManagementController::class, 'attachExisting'])->middleware('throttle:10,1')->name('console.users.attach');
    Route::get('/app/workspaces/{workspace}/users/{user}/edit', [WorkspaceUserManagementController::class, 'edit'])->whereNumber('user')->name('console.users.edit');
    Route::put('/app/workspaces/{workspace}/users/{user}', [WorkspaceUserManagementController::class, 'update'])->whereNumber('user')->middleware('throttle:10,1')->name('console.users.update');
    Route::patch('/app/workspaces/{workspace}/users/{user}/status', [WorkspaceUserManagementController::class, 'status'])->whereNumber('user')->middleware('throttle:10,1')->name('console.users.status');
    Route::get('/app/workspaces/{workspace}/users/{user}/password', [WorkspaceUserManagementController::class, 'password'])->whereNumber('user')->name('console.users.password');
    Route::put('/app/workspaces/{workspace}/users/{user}/password', [WorkspaceUserManagementController::class, 'updatePassword'])->whereNumber('user')->middleware('throttle:10,1')->name('console.users.password.update');
    Route::get('/app/workspaces/{workspace}/settings', [AppSettingsConsoleController::class, 'index'])->name('console.app-settings.index');
    Route::get('/app/workspaces/{workspace}/settings/secrets/{field}', [AppSettingsConsoleController::class, 'revealSecret'])
        ->where('field', '[a-z_]+')->middleware('throttle:20,1')->name('console.app-settings.secret');
    Route::get('/app/workspaces/{workspace}/miscellaneous/app-health', [MiscellaneousConsoleController::class, 'appHealth'])->name('console.miscellaneous.app-health');
    Route::get('/app/workspaces/{workspace}/miscellaneous/app-errors', [MiscellaneousConsoleController::class, 'appErrors'])->name('console.miscellaneous.app-errors');
    Route::get('/app/workspaces/{workspace}/miscellaneous/subscribers', [MiscellaneousConsoleController::class, 'subscribers'])->name('console.miscellaneous.subscribers');
    Route::get('/app/workspaces/{workspace}/miscellaneous/contacts', [MiscellaneousConsoleController::class, 'contacts'])->name('console.miscellaneous.contacts');
    Route::get('/app/workspaces/{workspace}/miscellaneous/horizon', [MiscellaneousConsoleController::class, 'horizon'])->name('console.miscellaneous.horizon');
    Route::post('/app/workspaces/{workspace}/settings', [AppSettingsConsoleController::class, 'update'])->middleware('throttle:10,1')->name('console.app-settings.update');
    Route::get('/app/workspaces/{workspace}/campaigns', [WorkspaceConsoleController::class, 'campaigns'])->name('console.campaigns.index');
    Route::get('/app/workspaces/{workspace}/campaigns/{campaign}/visitors', [WorkspaceCampaignVisitorsConsoleController::class, 'index'])->whereUuid(['workspace', 'campaign'])->name('console.campaigns.visitors');
    Route::get('/app/workspaces/{workspace}/tools/text-mechanic', [WorkspaceTextMechanicConsoleController::class, 'index'])->name('console.tools.text-mechanic');
    Route::get('/app/workspaces/{workspace}/tools/encoding', [WorkspaceEncodingConsoleController::class, 'index'])->name('console.tools.encoding.index');
    Route::get('/app/workspaces/{workspace}/tools/encoding/popup', [WorkspaceEncodingConsoleController::class, 'popup'])->name('console.tools.encoding.popup');
    Route::post('/app/workspaces/{workspace}/tools/encoding', [WorkspaceEncodingConsoleController::class, 'encode'])->middleware('throttle:30,1')->name('console.tools.encoding.encode');
    Route::get('/app/workspaces/{workspace}/tools/spf-scanner', [WorkspaceSpfScannerController::class, 'index'])->name('console.tools.spf.index');
    Route::get('/app/workspaces/{workspace}/tools/spf-scanner/domains', [WorkspaceSpfScannerController::class, 'domains'])->name('console.tools.spf.domains');
    Route::get('/app/workspaces/{workspace}/tools/spf-scanner/records', [WorkspaceSpfScannerController::class, 'records'])->name('console.tools.spf.records');
    Route::get('/app/workspaces/{workspace}/tools/spf-scanner/export', [WorkspaceSpfScannerController::class, 'export'])->name('console.tools.spf.export');
    Route::post('/app/workspaces/{workspace}/tools/spf-scanner/search', [WorkspaceSpfScannerController::class, 'search'])->middleware('throttle:20,1')->name('console.tools.spf.search');
    Route::post('/app/workspaces/{workspace}/tools/spf-scanner/search/export', [WorkspaceSpfScannerController::class, 'searchExport'])->middleware('throttle:20,1')->name('console.tools.spf.search-export');
    Route::post('/app/workspaces/{workspace}/tools/spf-scanner/upload', [WorkspaceSpfScannerController::class, 'upload'])->middleware('throttle:5,1')->name('console.tools.spf.upload');
    Route::post('/app/workspaces/{workspace}/tools/spf-scanner/manual', [WorkspaceSpfScannerController::class, 'manual'])->middleware('throttle:10,1')->name('console.tools.spf.manual');
    Route::post('/app/workspaces/{workspace}/tools/spf-scanner/volume', [WorkspaceSpfScannerController::class, 'volume'])->middleware('throttle:20,1')->name('console.tools.spf.volume');
    Route::get('/app/workspaces/{workspace}/tools/spf-scanner/scans', [WorkspaceSpfScannerController::class, 'scans'])->name('console.tools.spf.scans');
    Route::post('/app/workspaces/{workspace}/tools/spf-scanner/scans/{scan}/kill', [WorkspaceSpfScannerController::class, 'kill'])->whereNumber('scan')->middleware('throttle:10,1')->name('console.tools.spf.kill');
    Route::get('/app/workspaces/{workspace}/tools/workspace', [WorkspaceToolConsoleController::class, 'index'])->name('console.tools.workspace.index');
    Route::get('/app/workspaces/{workspace}/gcampaigns/projects', [WorkspaceToolConsoleController::class, 'projects'])->name('console.gcampaigns.projects');
    Route::get('/app/workspaces/{workspace}/gcampaigns/accounts', [WorkspaceToolConsoleController::class, 'accounts'])->name('console.gcampaigns.accounts');
    Route::post('/app/workspaces/{workspace}/tools/workspace/projects', [WorkspaceToolConsoleController::class, 'storeProject'])->middleware('throttle:10,1')->name('console.tools.workspace.projects.store');
    Route::post('/app/workspaces/{workspace}/tools/workspace/projects/{project}/sync', [WorkspaceToolConsoleController::class, 'syncProject'])->whereNumber('project')->middleware('throttle:10,1')->name('console.tools.workspace.projects.sync');
    Route::patch('/app/workspaces/{workspace}/tools/workspace/projects/status', [WorkspaceToolConsoleController::class, 'statusProjects'])->middleware('throttle:10,1')->name('console.tools.workspace.projects.bulk-status');
    Route::patch('/app/workspaces/{workspace}/tools/workspace/projects/{project}/status', [WorkspaceToolConsoleController::class, 'statusProject'])->whereNumber('project')->name('console.tools.workspace.projects.status');
    Route::delete('/app/workspaces/{workspace}/tools/workspace/projects', [WorkspaceToolConsoleController::class, 'destroyProjects'])->middleware('throttle:10,1')->name('console.tools.workspace.projects.bulk-destroy');
    Route::delete('/app/workspaces/{workspace}/tools/workspace/projects/{project}', [WorkspaceToolConsoleController::class, 'destroyProject'])->whereNumber('project')->name('console.tools.workspace.projects.destroy');
    Route::post('/app/workspaces/{workspace}/tools/workspace/accounts', [WorkspaceToolConsoleController::class, 'storeAccounts'])->middleware('throttle:10,1')->name('console.tools.workspace.accounts.store');
    Route::patch('/app/workspaces/{workspace}/tools/workspace/accounts/status', [WorkspaceToolConsoleController::class, 'statusAccounts'])->name('console.tools.workspace.accounts.status');
    Route::delete('/app/workspaces/{workspace}/tools/workspace/accounts', [WorkspaceToolConsoleController::class, 'destroyAccounts'])->name('console.tools.workspace.accounts.destroy');
    Route::post('/app/workspaces/{workspace}/tools/workspace/push', [WorkspaceToolConsoleController::class, 'push'])->middleware('throttle:5,1')->name('console.tools.workspace.push');
    Route::delete('/app/workspaces/{workspace}/tools/workspace/reset', [WorkspaceToolConsoleController::class, 'reset'])->middleware('throttle:5,1')->name('console.tools.workspace.reset');
    Route::get('/app/workspaces/{workspace}/drops/suppression', [WorkspaceDropSuppressionController::class, 'index'])->name('console.drops.suppression');
    Route::get('/app/workspaces/{workspace}/drops/suppression/catalog', [WorkspaceDropSuppressionController::class, 'catalog'])->name('console.drops.suppression.catalog');
    Route::get('/app/workspaces/{workspace}/drops/suppression/{suppression}/monitor', [WorkspaceDropSuppressionController::class, 'monitor'])
        ->whereNumber('suppression')->name('console.drops.suppression.monitor');
    Route::post('/app/workspaces/{workspace}/drops/suppression/start', [WorkspaceDropSuppressionController::class, 'start'])
        ->middleware('throttle:5,1')->name('console.drops.suppression.start');
    Route::post('/app/workspaces/{workspace}/drops/suppression/offer-file', [WorkspaceDropSuppressionController::class, 'uploadOfferFile'])
        ->middleware('throttle:5,1')->name('console.drops.suppression.offer-file');
    Route::post('/app/workspaces/{workspace}/drops/suppression/kill', [WorkspaceDropSuppressionController::class, 'kill'])
        ->middleware('throttle:10,1')->name('console.drops.suppression.kill');
    Route::get('/app/workspaces/{workspace}/drops/monitoring', [WorkspaceDropsConsoleController::class, 'monitoring'])->name('console.drops.monitoring');
    Route::get('/app/workspaces/{workspace}/drops/{drop}/monitor', [WorkspaceDropsConsoleController::class, 'monitor'])
        ->whereNumber('drop')->name('console.drops.monitor');
    Route::get('/app/workspaces/{workspace}/drops/{drop}/stats', [WorkspaceDropsConsoleController::class, 'stats'])
        ->whereNumber('drop')->name('console.drops.stats');
    Route::get('/app/workspaces/{workspace}/drops/{drop}/visitors', [WorkspaceDropsConsoleController::class, 'visitors'])
        ->whereNumber('drop')->name('console.drops.visitors');
    Route::get('/app/workspaces/{workspace}/warmups/seeds', [WorkspaceSeedListConsoleController::class, 'index'])->name('console.warmups.seeds.index');
    Route::get('/app/workspaces/{workspace}/warmups/seeds/create', [WorkspaceSeedListConsoleController::class, 'create'])->name('console.warmups.seeds.create');
    Route::post('/app/workspaces/{workspace}/warmups/seeds', [WorkspaceSeedListConsoleController::class, 'store'])->middleware('throttle:10,1')->name('console.warmups.seeds.store');
    Route::get('/app/workspaces/{workspace}/warmups/seeds/clean', [WorkspaceSeedListConsoleController::class, 'cleanForm'])->name('console.warmups.seeds.clean.form');
    Route::post('/app/workspaces/{workspace}/warmups/seeds/clean', [WorkspaceSeedListConsoleController::class, 'clean'])->middleware('throttle:5,1')->name('console.warmups.seeds.clean');
    Route::get('/app/workspaces/{workspace}/warmups/seeds/load', [WorkspaceSeedListConsoleController::class, 'loadEmails'])->name('console.warmups.seeds.load');
    Route::get('/app/workspaces/{workspace}/warmups/seeds/{seed}/upload', [WorkspaceSeedListConsoleController::class, 'uploadForm'])->whereNumber('seed')->name('console.warmups.seeds.upload-form');
    Route::post('/app/workspaces/{workspace}/warmups/seeds/{seed}/upload', [WorkspaceSeedListConsoleController::class, 'upload'])->whereNumber('seed')->middleware('throttle:10,1')->name('console.warmups.seeds.upload');
    Route::get('/app/workspaces/{workspace}/warmups/seeds/{seed}', [WorkspaceSeedListConsoleController::class, 'show'])->whereNumber('seed')->name('console.warmups.seeds.show');
    Route::patch('/app/workspaces/{workspace}/warmups/seeds/{seed}/status', [WorkspaceSeedListConsoleController::class, 'setStatus'])->whereNumber('seed')->middleware('throttle:10,1')->name('console.warmups.seeds.status');
    Route::delete('/app/workspaces/{workspace}/warmups/seeds/{seed}', [WorkspaceSeedListConsoleController::class, 'destroy'])->whereNumber('seed')->middleware('throttle:10,1')->name('console.warmups.seeds.destroy');
    Route::get('/app/workspaces/{workspace}/warmups/seed-emails/{email}/edit', [WorkspaceSeedListConsoleController::class, 'editEmailForm'])->whereNumber('email')->name('console.warmups.seed-emails.edit');
    Route::put('/app/workspaces/{workspace}/warmups/seed-emails/{email}', [WorkspaceSeedListConsoleController::class, 'updateEmail'])->whereNumber('email')->middleware('throttle:10,1')->name('console.warmups.seed-emails.update');
    Route::delete('/app/workspaces/{workspace}/warmups/seeds/{seed}/emails', [WorkspaceSeedListConsoleController::class, 'removeEmails'])->whereNumber('seed')->middleware('throttle:10,1')->name('console.warmups.seeds.emails.destroy');
    Route::get('/app/workspaces/{workspace}/networks/sponsors', [WorkspaceSponsorConsoleController::class, 'networkSponsors'])->name('console.networks.sponsors.index');
    Route::get('/app/workspaces/{workspace}/networks/offers', [WorkspaceOfferConsoleController::class, 'networkIndex'])->name('console.networks.offers.index');
    Route::get('/app/workspaces/{workspace}/sponsors', [WorkspaceSponsorConsoleController::class, 'index'])->name('console.sponsors.index');
    Route::get('/app/workspaces/{workspace}/sponsors/extension', [WorkspaceSponsorConsoleController::class, 'downloadExtension'])->name('console.sponsors.extension');
    Route::get('/app/workspaces/{workspace}/sponsors/create', [WorkspaceSponsorConsoleController::class, 'create'])->name('console.sponsors.create');
    Route::get('/app/workspaces/{workspace}/sponsors/bulk-users', [WorkspaceSponsorConsoleController::class, 'bulkUsers'])->name('console.sponsors.bulk-users');
    Route::get('/app/workspaces/{workspace}/sponsors/{sponsorId}/edit', [WorkspaceSponsorConsoleController::class, 'edit'])->whereNumber('sponsorId')->name('console.sponsors.edit');
    Route::get('/app/workspaces/{workspace}/sponsors/{sponsorId}/users', [WorkspaceSponsorConsoleController::class, 'users'])->whereNumber('sponsorId')->name('console.sponsors.users');
    Route::post('/app/workspaces/{workspace}/sponsors', [WorkspaceSponsorConsoleController::class, 'store'])->middleware('throttle:10,1')->name('console.sponsors.store');
    Route::post('/app/workspaces/{workspace}/sponsors/test-connection', [WorkspaceSponsorConsoleController::class, 'testNewConnection'])->middleware('throttle:10,1')->name('console.sponsors.test-new-connection');
    Route::post('/app/workspaces/{workspace}/sponsors/{sponsorId}/test-connection', [WorkspaceSponsorConsoleController::class, 'testExistingConnection'])->whereNumber('sponsorId')->middleware('throttle:10,1')->name('console.sponsors.test-existing-connection');
    Route::put('/app/workspaces/{workspace}/sponsors/bulk-users', [WorkspaceSponsorConsoleController::class, 'updateUsers'])->middleware('throttle:10,1')->name('console.sponsors.bulk-users.update');
    Route::put('/app/workspaces/{workspace}/sponsors/{sponsorId}', [WorkspaceSponsorConsoleController::class, 'update'])->whereNumber('sponsorId')->middleware('throttle:10,1')->name('console.sponsors.update');
    Route::put('/app/workspaces/{workspace}/sponsors/{sponsorId}/users', [WorkspaceSponsorConsoleController::class, 'updateUsers'])->whereNumber('sponsorId')->middleware('throttle:10,1')->name('console.sponsors.users.update');
    Route::patch('/app/workspaces/{workspace}/sponsors/{sponsorId}/status', [WorkspaceSponsorConsoleController::class, 'setStatus'])->whereNumber('sponsorId')->middleware('throttle:10,1')->name('console.sponsors.status');
    Route::post('/app/workspaces/{workspace}/sponsors/{sponsorId}/login', [WorkspaceSponsorConsoleController::class, 'login'])->whereNumber('sponsorId')->middleware('throttle:5,1')->name('console.sponsors.login');
    Route::get('/app/workspaces/{workspace}/offers', [WorkspaceOfferConsoleController::class, 'index'])->name('console.offers.index');
    Route::get('/app/workspaces/{workspace}/offers/create', [WorkspaceOfferConsoleController::class, 'create'])->name('console.offers.create');
    Route::get('/app/workspaces/{workspace}/offers/bulk-users', [WorkspaceOfferConsoleController::class, 'bulkUsers'])->name('console.offers.bulk-users');
    Route::get('/app/workspaces/{workspace}/offers/share', [WorkspaceOfferConsoleController::class, 'shareAssetsForm'])->name('console.offers.share');
    Route::post('/app/workspaces/{workspace}/offers/share', [WorkspaceOfferConsoleController::class, 'shareAssets'])->middleware('throttle:3,1')->name('console.offers.share.send');
    Route::put('/app/workspaces/{workspace}/offers/bulk-users', [WorkspaceOfferConsoleController::class, 'updateUsers'])->middleware('throttle:10,1')->name('console.offers.bulk-users.update');
    Route::get('/app/workspaces/{workspace}/offers/{offerId}/edit', [WorkspaceOfferConsoleController::class, 'edit'])->whereNumber('offerId')->name('console.offers.edit');
    Route::post('/app/workspaces/{workspace}/offers/{offerId}/suppression-file/download', [WorkspaceOfferConsoleController::class, 'downloadSuppression'])
        ->whereNumber('offerId')->middleware('throttle:5,1')->name('console.offers.suppression-file.download');
    Route::get('/app/workspaces/{workspace}/offers/{offerId}/suppression-file/downloads/{downloadId}', [WorkspaceOfferConsoleController::class, 'suppressionDownloadStatus'])
        ->whereNumber(['offerId', 'downloadId'])->name('console.offers.suppression-file.download-status');
    Route::get('/app/workspaces/{workspace}/offers/{offerId}/users', [WorkspaceOfferConsoleController::class, 'users'])->whereNumber('offerId')->name('console.offers.users');
    Route::get('/app/workspaces/{workspace}/offers/{offerId}/assets', [WorkspaceOfferConsoleController::class, 'assets'])->whereNumber('offerId')->name('console.offers.assets');
    Route::get('/app/workspaces/{workspace}/offers/{offerId}/assets/upload/{type}', [WorkspaceOfferConsoleController::class, 'uploadAssetsForm'])->whereNumber('offerId')->whereIn('type', ['creative', 'unsubscribe'])->name('console.offers.assets.upload-form');
    Route::post('/app/workspaces/{workspace}/offers/{offerId}/assets/upload/{type}', [WorkspaceOfferConsoleController::class, 'uploadAssets'])->whereNumber('offerId')->whereIn('type', ['creative', 'unsubscribe'])->middleware('throttle:10,1')->name('console.offers.assets.upload');
    Route::get('/app/workspaces/{workspace}/offers/{offerId}/assets/{assetId}/file', [WorkspaceOfferConsoleController::class, 'assetFile'])->whereNumber(['offerId', 'assetId'])->name('console.offers.assets.file');
    Route::post('/app/workspaces/{workspace}/offers', [WorkspaceOfferConsoleController::class, 'store'])->middleware('throttle:10,1')->name('console.offers.store');
    Route::post('/app/workspaces/{workspace}/offers/import', [WorkspaceOfferConsoleController::class, 'import'])->middleware('throttle:10,1')->name('console.offers.import');
    Route::put('/app/workspaces/{workspace}/offers/{offerId}', [WorkspaceOfferConsoleController::class, 'update'])->whereNumber('offerId')->middleware('throttle:10,1')->name('console.offers.update');
    Route::put('/app/workspaces/{workspace}/offers/{offerId}/users', [WorkspaceOfferConsoleController::class, 'updateUsers'])->whereNumber('offerId')->middleware('throttle:10,1')->name('console.offers.users.update');
    Route::patch('/app/workspaces/{workspace}/offers/{offerId}/active', [WorkspaceOfferConsoleController::class, 'setActive'])->whereNumber('offerId')->middleware('throttle:10,1')->name('console.offers.active');
    Route::patch('/app/workspaces/{workspace}/offers/{offerId}/business-status', [WorkspaceOfferConsoleController::class, 'setBusinessStatus'])->whereNumber('offerId')->middleware('throttle:10,1')->name('console.offers.status');
    Route::delete('/app/workspaces/{workspace}/offers/{offerId}', [WorkspaceOfferConsoleController::class, 'destroy'])->whereNumber('offerId')->middleware('throttle:10,1')->name('console.offers.destroy');
    Route::patch('/app/workspaces/{workspace}/offers/{offerId}/restore', [WorkspaceOfferConsoleController::class, 'restore'])->whereNumber('offerId')->middleware('throttle:10,1')->name('console.offers.restore');
    Route::post('/app/workspaces/{workspace}/offers/{offerId}/assets', [WorkspaceOfferConsoleController::class, 'addAssets'])->whereNumber('offerId')->middleware('throttle:10,1')->name('console.offers.assets.add');
    Route::patch('/app/workspaces/{workspace}/offers/{offerId}/assets/status', [WorkspaceOfferConsoleController::class, 'setAssetStatuses'])->whereNumber('offerId')->middleware('throttle:10,1')->name('console.offers.assets.bulk-status');
    Route::put('/app/workspaces/{workspace}/offers/assets/{assetId}', [WorkspaceOfferConsoleController::class, 'updateAsset'])->whereNumber('assetId')->middleware('throttle:10,1')->name('console.offers.assets.update');
    Route::patch('/app/workspaces/{workspace}/offers/assets/{assetId}/status', [WorkspaceOfferConsoleController::class, 'setAssetStatus'])->whereNumber('assetId')->middleware('throttle:10,1')->name('console.offers.assets.status');
    Route::delete('/app/workspaces/{workspace}/offers/assets/{assetId}', [WorkspaceOfferConsoleController::class, 'deleteAsset'])->whereNumber('assetId')->middleware('throttle:10,1')->name('console.offers.assets.destroy');
    Route::get('/app/workspaces/{workspace}/campaigns/create', [WorkspaceConsoleController::class, 'createCampaign'])->name('console.campaigns.create');
    Route::post('/app/workspaces/{workspace}/campaign-test-addresses', [WorkspaceConsoleController::class, 'storeCampaignTestAddress'])->middleware('throttle:10,1')->name('console.campaign-test-addresses.store');
    Route::delete('/app/workspaces/{workspace}/campaign-test-addresses/{address}', [WorkspaceConsoleController::class, 'deleteCampaignTestAddress'])->whereNumber('address')->middleware('throttle:10,1')->name('console.campaign-test-addresses.destroy');
    Route::get('/app/workspaces/{workspace}/my-servers', [WorkspaceConsoleController::class, 'myServers'])->name('console.my-servers.index');
    Route::get('/app/workspaces/{workspace}/pmta-admin', [WorkspaceConsoleController::class, 'pmtaAdmin'])->name('console.pmta-admin.index');
    Route::get('/app/workspaces/{workspace}/pmta-admin/servers/{server}/{monitorPath?}', [WorkspaceConsoleController::class, 'pmtaAdminMonitor'])
        ->whereUuid('server')->where('monitorPath', '[A-Za-z0-9._-]*')->name('console.pmta-admin.monitor');
    Route::get('/app/workspaces/{workspace}/pmta-admin/action-history', [WorkspaceConsoleController::class, 'pmtaAdminHistory'])->name('console.pmta-admin.history');
    Route::post('/app/workspaces/{workspace}/pmta-admin/action', [WorkspaceConsoleController::class, 'pmtaAdminAction'])->name('console.pmta-admin.action');
    Route::post('/app/workspaces/{workspace}/pmta-admin/restart', [WorkspaceConsoleController::class, 'pmtaAdminRestart'])->name('console.pmta-admin.restart');
    Route::get('/app/workspaces/{workspace}/pmta-scheduler', [PmtaConsoleController::class, 'scheduler'])->name('console.pmta-scheduler.index');
    Route::get('/app/workspaces/{workspace}/pmta-scheduler/data', [PmtaConsoleController::class, 'scheduledActions'])->name('console.pmta-scheduler.data');
    Route::post('/app/workspaces/{workspace}/pmta-scheduler', [PmtaConsoleController::class, 'storeScheduledAction'])->name('console.pmta-scheduler.store');
    Route::delete('/app/workspaces/{workspace}/pmta-scheduler/{actionId}', [PmtaConsoleController::class, 'deleteScheduledAction'])->whereNumber('actionId')->name('console.pmta-scheduler.destroy');
    Route::get('/app/workspaces/{workspace}/pmta-settings', [PmtaConsoleController::class, 'settings'])->name('console.pmta-settings.index');
    Route::get('/app/workspaces/{workspace}/pmta-settings/servers', [PmtaConsoleController::class, 'settingsServers'])->name('console.pmta-settings.servers');
    Route::get('/app/workspaces/{workspace}/pmta-settings/files', [PmtaConsoleController::class, 'settingsFiles'])->name('console.pmta-settings.files');
    Route::get('/app/workspaces/{workspace}/pmta-settings/file', [PmtaConsoleController::class, 'settingsFile'])->name('console.pmta-settings.file');
    Route::post('/app/workspaces/{workspace}/pmta-settings/edit', [PmtaConsoleController::class, 'editSettingsFile'])->name('console.pmta-settings.edit');
    Route::post('/app/workspaces/{workspace}/pmta-settings/push', [PmtaConsoleController::class, 'pushSettingsFile'])->name('console.pmta-settings.push');
    Route::get('/app/workspaces/{workspace}/pmta-settings/default-domains', [PmtaConsoleController::class, 'defaultDomains'])->name('console.pmta-settings.default-domains');
    Route::get('/app/workspaces/{workspace}/pmta-vmta', [PmtaConsoleController::class, 'virtualMtas'])->name('console.pmta-vmta.index');
    Route::get('/app/workspaces/{workspace}/pmta-vmta/rows', [PmtaConsoleController::class, 'vmtaRows'])->name('console.pmta-vmta.rows');
    Route::post('/app/workspaces/{workspace}/pmta-vmta/push', [PmtaConsoleController::class, 'pushVmta'])->name('console.pmta-vmta.push');
    Route::post('/app/workspaces/{workspace}/pmta-vmta/push-rows', [PmtaConsoleController::class, 'pushVmtaRows'])->name('console.pmta-vmta.push-rows');
    Route::post('/app/workspaces/{workspace}/pmta-vmta/restore', [PmtaConsoleController::class, 'restoreVmta'])->name('console.pmta-vmta.restore');
    Route::post('/app/workspaces/{workspace}/pmta-vmta/restore-v2', [PmtaConsoleController::class, 'restoreVmtaV2'])->name('console.pmta-vmta.restore-v2');
    Route::delete('/app/workspaces/{workspace}/pmta-vmta/rows', [PmtaConsoleController::class, 'deleteVmtaRows'])->name('console.pmta-vmta.rows.destroy');
    Route::get('/app/workspaces/{workspace}/pmta-smtp', [PmtaConsoleController::class, 'smtp'])->name('console.pmta-smtp.index');
    Route::get('/app/workspaces/{workspace}/pmta-smtp/rows', [PmtaConsoleController::class, 'smtpRows'])->name('console.pmta-smtp.rows');
    Route::post('/app/workspaces/{workspace}/pmta-smtp/push', [PmtaConsoleController::class, 'pushSmtp'])->name('console.pmta-smtp.push');
    Route::post('/app/workspaces/{workspace}/pmta-smtp/restore', [PmtaConsoleController::class, 'restoreSmtp'])->name('console.pmta-smtp.restore');
    Route::delete('/app/workspaces/{workspace}/pmta-smtp/rows', [PmtaConsoleController::class, 'deleteSmtpRows'])->name('console.pmta-smtp.rows.destroy');
    Route::get('/app/workspaces/{workspace}/pmta-errors', [PmtaConsoleController::class, 'errors'])->name('console.pmta-errors.index');
    Route::post('/app/workspaces/{workspace}/pmta-errors', [PmtaConsoleController::class, 'bounces'])->name('console.pmta-errors.search');
    Route::get('/app/workspaces/{workspace}/pmta-config-history', [PmtaConsoleController::class, 'configHistory'])->name('console.pmta-config-history.index');
    Route::get('/app/workspaces/{workspace}/pmta-config-history/{historyId}/contents', [PmtaConsoleController::class, 'configHistoryContents'])
        ->whereNumber('historyId')->name('console.pmta-config-history.contents');
    Route::post('/app/workspaces/{workspace}/campaign-tools/encode', [CampaignComposerToolsController::class, 'encode'])->middleware('throttle:30,1')->name('console.campaign-tools.encode');
    Route::post('/app/workspaces/{workspace}/campaign-tools/shortlinks/{provider}', [CampaignComposerToolsController::class, 'shorten'])->middleware('throttle:5,1')->name('console.campaign-tools.shortlinks');
    Route::get('/app/workspaces/{workspace}/campaigns/{campaign}/edit', [WorkspaceConsoleController::class, 'editCampaign'])->name('console.campaigns.edit');
    Route::put('/app/workspaces/{workspace}/campaigns/{campaign}/progress', [CampaignComposerToolsController::class, 'updateProgress'])->middleware('throttle:10,1')->name('console.campaigns.progress');
    Route::get('/app/workspaces/{workspace}/campaigns/test-draft/create', [WorkspaceConsoleController::class, 'createCampaignTestDraft'])->name('console.campaigns.test-draft.create');
    Route::get('/app/workspaces/{workspace}/campaigns/{campaign}/clone', [WorkspaceConsoleController::class, 'cloneCampaign'])->name('console.campaigns.clone');
    Route::post('/app/workspaces/{workspace}/campaigns/{campaign}/clone', [WorkspaceConsoleController::class, 'storeCampaignClone'])->middleware('throttle:10,1')->name('console.campaigns.clone.store');
    Route::get('/app/workspaces/{workspace}/campaign-negatives', [WorkspaceCampaignNegativeController::class, 'index'])->name('console.campaign-negatives.index');
    Route::get('/app/workspaces/{workspace}/campaign-negatives/create', [WorkspaceCampaignNegativeController::class, 'create'])->name('console.campaign-negatives.create');
    Route::post('/app/workspaces/{workspace}/campaign-negatives', [WorkspaceCampaignNegativeController::class, 'store'])->middleware('throttle:10,1')->name('console.campaign-negatives.store');
    Route::get('/app/workspaces/{workspace}/campaign-negatives/{negativeId}/download', [WorkspaceCampaignNegativeController::class, 'download'])->whereNumber('negativeId')->name('console.campaign-negatives.download');
    Route::delete('/app/workspaces/{workspace}/campaign-negatives/{negativeId}', [WorkspaceCampaignNegativeController::class, 'destroy'])->whereNumber('negativeId')->middleware('throttle:10,1')->name('console.campaign-negatives.destroy');
    Route::post('/app/workspaces/{workspace}/campaigns/check-smtp', [WorkspaceConsoleController::class, 'checkCampaignSmtp'])->middleware('throttle:3,1')->name('console.campaigns.smtp-check');
    Route::get('/app/workspaces/{workspace}/composer/options', [WorkspaceConsoleController::class, 'campaignSendOptions'])->name('console.campaigns.send-options');
    Route::post('/app/workspaces/{workspace}/campaigns', [WorkspaceConsoleController::class, 'createCampaignDraft'])->middleware('throttle:10,1')->name('console.campaigns.store');
    Route::post('/app/workspaces/{workspace}/campaigns/{campaign}/revisions', [WorkspaceConsoleController::class, 'reviseCampaignDraft'])->middleware('throttle:10,1')->name('console.campaigns.revisions.store');
    Route::post('/app/workspaces/{workspace}/campaigns/{campaign}/generate-body', [CampaignComposerToolsController::class, 'generateBody'])->middleware('throttle:10,1')->name('console.campaigns.generate-body');
    Route::post('/app/workspaces/{workspace}/campaigns/{campaign}/test-email', [WorkspaceConsoleController::class, 'sendCampaignTestEmail'])->name('console.campaigns.test-email');
    Route::post('/app/workspaces/{workspace}/campaigns/{campaign}/pmta-test', [WorkspaceConsoleController::class, 'sendCampaignPmtaTest'])->name('console.campaigns.pmta-test');
    Route::post('/app/workspaces/{workspace}/campaigns/manual-test', [WorkspaceConsoleController::class, 'sendCampaignManualTest'])->name('console.campaigns.manual-test');
    Route::post('/app/workspaces/{workspace}/campaigns/{campaign}/auto-test', [WorkspaceConsoleController::class, 'sendCampaignAutomaticTest'])->name('console.campaigns.auto-test');
    Route::patch('/app/workspaces/{workspace}/campaigns/bulk-disable', [WorkspaceConsoleController::class, 'disableCampaigns'])->middleware('throttle:10,1')->name('console.campaigns.bulk-disable');
    Route::patch('/app/workspaces/{workspace}/campaigns/{campaign}/status', [WorkspaceConsoleController::class, 'updateCampaignActiveStatus'])->name('console.campaigns.status');
    Route::get('/app/workspaces/{workspace}/campaign-headers', [WorkspaceCampaignHeaderController::class, 'index'])->name('console.campaign-headers.index');
    Route::post('/app/workspaces/{workspace}/campaign-headers', [WorkspaceCampaignHeaderController::class, 'store'])->middleware('throttle:10,1')->name('console.campaign-headers.store');
    Route::get('/app/workspaces/{workspace}/campaign-headers/{headerId}', [WorkspaceCampaignHeaderController::class, 'show'])->whereNumber('headerId')->name('console.campaign-headers.show');
    Route::delete('/app/workspaces/{workspace}/campaign-headers/{headerId}', [WorkspaceCampaignHeaderController::class, 'destroy'])->whereNumber('headerId')->middleware('throttle:10,1')->name('console.campaign-headers.destroy');
    Route::get('/app/workspaces/{workspace}/global-tests', [WorkspaceGlobalTestController::class, 'index'])->name('console.global-tests.index');
    Route::get('/app/workspaces/{workspace}/global-tests/catalog', [WorkspaceGlobalTestController::class, 'catalog'])->name('console.global-tests.catalog');
    Route::post('/app/workspaces/{workspace}/global-tests/encoding', [WorkspaceGlobalTestController::class, 'encode'])->name('console.global-tests.encoding');
    Route::post('/app/workspaces/{workspace}/global-tests', [WorkspaceGlobalTestController::class, 'test'])->name('console.global-tests.test');
    Route::get('/app/workspaces/{workspace}/global-tests/histories', [WorkspaceGlobalTestController::class, 'histories'])->name('console.global-tests.histories');
    Route::get('/app/workspaces/{workspace}/global-tests/histories/{logId}', [WorkspaceGlobalTestController::class, 'history'])->whereUuid('logId')->name('console.global-tests.history');
    Route::get('/app/workspaces/{workspace}/campaigns/{campaign}', [WorkspaceConsoleController::class, 'campaign'])->name('console.campaigns.show');
    Route::get('/app/workspaces/{workspace}/audience-lists', [WorkspaceConsoleController::class, 'audienceLists'])->name('console.audiences.index');
    Route::get('/app/workspaces/{workspace}/audience-lists/{audienceList}', [WorkspaceConsoleController::class, 'audienceList'])->name('console.audiences.show');
    Route::get('/app/workspaces/{workspace}/infrastructure', [InfrastructureConsoleController::class, 'index'])->name('console.infrastructure.index');
    Route::get('/app/workspaces/{workspace}/infrastructure/install', [InfrastructureConsoleController::class, 'install'])->name('console.infrastructure.install.index');
    Route::post('/app/workspaces/{workspace}/infrastructure/install/load-ips', [InfrastructureConsoleController::class, 'loadSingleInstallIps'])
        ->middleware('throttle:10,1')->name('console.infrastructure.install.load-ips');
    Route::post('/app/workspaces/{workspace}/infrastructure/install/{server}/config-ips', [InfrastructureConsoleController::class, 'configureSingleInstallIps'])
        ->whereUuid('server')->middleware('throttle:3,1')->name('console.infrastructure.install.config-ips');
    Route::get('/app/workspaces/{workspace}/infrastructure/bulk-install', [InfrastructureConsoleController::class, 'bulkInstall'])->name('console.infrastructure.bulk-install');
    Route::get('/app/workspaces/{workspace}/infrastructure/change-domain', [InfrastructureConsoleController::class, 'changeDomain'])->name('console.infrastructure.change-domain.index');
    Route::post('/app/workspaces/{workspace}/infrastructure/change-domain/load-ips', [InfrastructureConsoleController::class, 'loadChangeDomainIps'])->middleware('throttle:10,1')->name('console.infrastructure.change-domain.load-ips');
    Route::post('/app/workspaces/{workspace}/infrastructure/change-domain/change', [InfrastructureConsoleController::class, 'changeManagedServerDomain'])->middleware('throttle:2,1')->name('console.infrastructure.change-domain.change');
    Route::get('/app/workspaces/{workspace}/infrastructure/change-domain/{server}/logs', [InfrastructureConsoleController::class, 'changeDomainLogs'])->whereUuid('server')->name('console.infrastructure.change-domain.logs');
    Route::get('/app/workspaces/{workspace}/infrastructure/reinstall', [InfrastructureConsoleController::class, 'reinstall'])->name('console.infrastructure.reinstall.index');
    Route::get('/app/workspaces/{workspace}/infrastructure/reinstall/{server}/config', [InfrastructureConsoleController::class, 'loadReinstallConfiguration'])->whereUuid('server')->name('console.infrastructure.reinstall.config');
    Route::post('/app/workspaces/{workspace}/infrastructure/reinstall/{server}/ips', [InfrastructureConsoleController::class, 'reloadReinstallIps'])->whereUuid('server')->middleware('throttle:3,1')->name('console.infrastructure.reinstall.ips');
    Route::post('/app/workspaces/{workspace}/infrastructure/reinstall/{server}/config-ips', [InfrastructureConsoleController::class, 'configureReinstallIps'])->whereUuid('server')->middleware('throttle:3,1')->name('console.infrastructure.reinstall.config-ips');
    Route::post('/app/workspaces/{workspace}/infrastructure/reinstall/{server}/runs', [InfrastructureConsoleController::class, 'startReinstall'])->whereUuid('server')->middleware('throttle:2,1')->name('console.infrastructure.reinstall.runs.store');
    Route::get('/app/workspaces/{workspace}/infrastructure/reinstall/runs/{runId}/logs', [InfrastructureConsoleController::class, 'reinstallLogs'])->whereUuid('runId')->name('console.infrastructure.reinstall.runs.logs');
    Route::get('/app/workspaces/{workspace}/infrastructure/cloud-providers', [CloudProviderConsoleController::class, 'index'])->name('console.infrastructure.cloud-providers.index');
    Route::get('/app/workspaces/{workspace}/infrastructure/cloud-providers/available/{type}', [CloudProviderConsoleController::class, 'available'])->name('console.infrastructure.cloud-providers.available');
    Route::get('/app/workspaces/{workspace}/infrastructure/cloud-providers/create', [CloudProviderConsoleController::class, 'create'])->name('console.infrastructure.cloud-providers.create');
    Route::post('/app/workspaces/{workspace}/infrastructure/cloud-providers/test-connection', [CloudProviderConsoleController::class, 'testNewConnection'])->middleware('throttle:10,1')->name('console.infrastructure.cloud-providers.test-new-connection');
    Route::post('/app/workspaces/{workspace}/infrastructure/cloud-providers/{providerId}/test-connection', [CloudProviderConsoleController::class, 'testExistingConnection'])->whereNumber('providerId')->middleware('throttle:10,1')->name('console.infrastructure.cloud-providers.test-existing-connection');
    Route::get('/app/workspaces/{workspace}/infrastructure/cloud-providers/{providerId}/edit', [CloudProviderConsoleController::class, 'edit'])->whereNumber('providerId')->name('console.infrastructure.cloud-providers.edit');
    Route::post('/app/workspaces/{workspace}/infrastructure/cloud-providers', [CloudProviderConsoleController::class, 'store'])->middleware('throttle:10,1')->name('console.infrastructure.cloud-providers.store');
    Route::put('/app/workspaces/{workspace}/infrastructure/cloud-providers/{providerId}', [CloudProviderConsoleController::class, 'update'])->whereNumber('providerId')->middleware('throttle:10,1')->name('console.infrastructure.cloud-providers.update');
    Route::delete('/app/workspaces/{workspace}/infrastructure/cloud-providers/{providerId}', [CloudProviderConsoleController::class, 'destroy'])->whereNumber('providerId')->middleware('throttle:10,1')->name('console.infrastructure.cloud-providers.destroy');
    Route::get('/app/workspaces/{workspace}/clouds/{type}', [CloudWorkflowConsoleController::class, 'index'])
        ->whereIn('type', ['linode', 'digitalocean'])
        ->name('console.clouds.index');
    Route::get('/app/workspaces/{workspace}/clouds/{type}/{providerId}/info', [CloudWorkflowConsoleController::class, 'info'])
        ->whereIn('type', ['linode', 'digitalocean'])
        ->whereNumber('providerId')->name('console.clouds.info');
    Route::get('/app/workspaces/{workspace}/clouds/{type}/{providerId}/instances', [CloudWorkflowConsoleController::class, 'instances'])
        ->whereIn('type', ['linode', 'digitalocean'])
        ->whereNumber('providerId')->name('console.clouds.instances');
    Route::post('/app/workspaces/{workspace}/clouds/{type}/{providerId}/instances', [CloudWorkflowConsoleController::class, 'create'])
        ->whereIn('type', ['linode', 'digitalocean'])
        ->whereNumber('providerId')->name('console.clouds.create');
    Route::delete('/app/workspaces/{workspace}/clouds/{type}/{providerId}/instances', [CloudWorkflowConsoleController::class, 'destroy'])
        ->whereIn('type', ['linode', 'digitalocean'])
        ->whereNumber('providerId')->name('console.clouds.destroy');
    Route::post('/app/workspaces/{workspace}/clouds/{type}/{providerId}/servers', [CloudWorkflowConsoleController::class, 'addServers'])
        ->whereIn('type', ['linode', 'digitalocean'])
        ->whereNumber('providerId')->name('console.clouds.servers');
    Route::post('/app/workspaces/{workspace}/clouds/{type}/{providerId}/rdns', [CloudWorkflowConsoleController::class, 'setRdns'])
        ->whereIn('type', ['linode'])
        ->whereNumber('providerId')->name('console.clouds.rdns');
    Route::post('/app/workspaces/{workspace}/clouds/{type}/{providerId}/reputation', [CloudWorkflowConsoleController::class, 'checkReputation'])
        ->whereIn('type', ['linode', 'digitalocean'])
        ->whereNumber('providerId')->name('console.clouds.reputation');
    Route::get('/app/workspaces/{workspace}/infrastructure/isps', [InfrastructureConsoleController::class, 'isps'])->name('console.infrastructure.isps.index');
    Route::get('/app/workspaces/{workspace}/infrastructure/isps/create', [InfrastructureConsoleController::class, 'createIsp'])->name('console.infrastructure.isps.create');
    Route::get('/app/workspaces/{workspace}/infrastructure/isps/{ispId}/edit', [InfrastructureConsoleController::class, 'editIsp'])->whereNumber('ispId')->name('console.infrastructure.isps.edit');
    Route::get('/app/workspaces/{workspace}/infrastructure/isps/{ispId}/users', [InfrastructureConsoleController::class, 'ispUsers'])->whereNumber('ispId')->name('console.infrastructure.isps.users');
    Route::get('/app/workspaces/{workspace}/infrastructure/isps/{ispId}/sponsors', [InfrastructureConsoleController::class, 'ispSponsors'])->whereNumber('ispId')->name('console.infrastructure.isps.sponsors');
    Route::get('/app/workspaces/{workspace}/infrastructure/emails', [WorkspaceEmailManagementController::class, 'index'])->name('console.infrastructure.emails.index');
    Route::post('/app/workspaces/{workspace}/infrastructure/emails/search', [WorkspaceEmailManagementController::class, 'search'])->name('console.infrastructure.emails.search');
    Route::patch('/app/workspaces/{workspace}/infrastructure/emails/action', [WorkspaceEmailManagementController::class, 'action'])->middleware('throttle:20,1')->name('console.infrastructure.emails.action');
    Route::get('/app/workspaces/{workspace}/infrastructure/emails/{contact}/logs', [WorkspaceEmailManagementController::class, 'logs'])->whereUuid('contact')->name('console.infrastructure.emails.logs');
    Route::get('/app/workspaces/{workspace}/infrastructure/blacklist-checker', [WorkspaceBlacklistCheckerController::class, 'index'])->name('console.infrastructure.blacklist-checker');
    Route::post('/app/workspaces/{workspace}/infrastructure/blacklist-checker/upload', [WorkspaceBlacklistCheckerController::class, 'upload'])->middleware('throttle:10,1')->name('console.infrastructure.blacklist-checker.upload');
    Route::patch('/app/workspaces/{workspace}/infrastructure/blacklist-checker/{process}/cancel', [WorkspaceBlacklistCheckerController::class, 'cancel'])->whereUuid(['workspace', 'process'])->middleware('throttle:20,1')->name('console.infrastructure.blacklist-checker.cancel');
    Route::get('/app/workspaces/{workspace}/infrastructure/lists', [AudienceListManagementController::class, 'index'])->name('console.infrastructure.lists.index');
    Route::get('/app/workspaces/{workspace}/audience-mixes', [AudienceMixController::class, 'index'])->name('console.audience-mixes.index');
    Route::get('/app/workspaces/{workspace}/audience-mixes/history', [AudienceMixController::class, 'history'])->name('console.audience-mixes.history');
    Route::get('/app/workspaces/{workspace}/audience-mixes/options', [AudienceMixController::class, 'options'])->name('console.audience-mixes.options');
    Route::post('/app/workspaces/{workspace}/audience-mixes', [AudienceMixController::class, 'store'])->middleware('throttle:10,1')->name('console.audience-mixes.store');
    Route::post('/app/workspaces/{workspace}/audience-mixes/{mixId}/remix', [AudienceMixController::class, 'remix'])->whereUuid('mixId')->middleware('throttle:10,1')->name('console.audience-mixes.remix');
    Route::get('/app/workspaces/{workspace}/infrastructure/list-history', [AudienceListHistoryController::class, 'index'])->name('console.infrastructure.list-history.index');
    Route::get('/app/workspaces/{workspace}/infrastructure/list-history/isps/{ispId}/lists', [AudienceListHistoryController::class, 'lists'])->whereNumber('ispId')->name('console.infrastructure.list-history.lists');
    Route::post('/app/workspaces/{workspace}/infrastructure/list-history/data', [AudienceListHistoryController::class, 'history'])->middleware('throttle:30,1')->name('console.infrastructure.list-history.data');
    Route::get('/app/workspaces/{workspace}/infrastructure/lists/import-example', [AudienceListManagementController::class, 'downloadImportExample'])->name('console.infrastructure.lists.import-example');
    Route::get('/app/workspaces/{workspace}/infrastructure/isps/{ispId}/lists', [AudienceListManagementController::class, 'rows'])->whereNumber('ispId')->name('console.infrastructure.isps.lists');
    Route::get('/app/workspaces/{workspace}/infrastructure/isps/{ispId}/lists/create', [AudienceListManagementController::class, 'create'])->whereNumber('ispId')->name('console.infrastructure.lists.create');
    Route::post('/app/workspaces/{workspace}/infrastructure/isps/{ispId}/lists', [AudienceListManagementController::class, 'store'])->whereNumber('ispId')->middleware('throttle:10,1')->name('console.infrastructure.lists.store');
    Route::get('/app/workspaces/{workspace}/infrastructure/lists/bulk-assignment/{type}', [AudienceListManagementController::class, 'bulkAssignment'])->whereIn('type', ['users', 'sponsors'])->name('console.infrastructure.lists.bulk-assignment');
    Route::put('/app/workspaces/{workspace}/infrastructure/lists/bulk-assignment/{type}', [AudienceListManagementController::class, 'updateBulkAssignment'])->whereIn('type', ['users', 'sponsors'])->middleware('throttle:10,1')->name('console.infrastructure.lists.bulk-assignment.update');
    Route::get('/app/workspaces/{workspace}/infrastructure/lists/{listId}/edit', [AudienceListManagementController::class, 'edit'])->whereUuid('listId')->name('console.infrastructure.lists.edit');
    Route::get('/app/workspaces/{workspace}/infrastructure/lists/{listId}/upload', [AudienceListManagementController::class, 'uploadForm'])->whereUuid('listId')->name('console.infrastructure.lists.upload');
    Route::post('/app/workspaces/{workspace}/infrastructure/lists/{listId}/upload', [AudienceListManagementController::class, 'upload'])->whereUuid('listId')->middleware('throttle:3,1')->name('console.infrastructure.lists.upload.save');
    Route::get('/app/workspaces/{workspace}/infrastructure/lists/{listId}/imports/{batchId}', [AudienceListManagementController::class, 'importStatus'])->whereUuid(['listId', 'batchId'])->name('console.infrastructure.lists.import-status');
    Route::get('/app/workspaces/{workspace}/infrastructure/lists/{listId}/assignment/{type}', [AudienceListManagementController::class, 'assignment'])->whereUuid('listId')->whereIn('type', ['users', 'sponsors'])->name('console.infrastructure.lists.assignment');
    Route::put('/app/workspaces/{workspace}/infrastructure/lists/{listId}/assignment/{type}', [AudienceListManagementController::class, 'updateAssignment'])->whereUuid('listId')->whereIn('type', ['users', 'sponsors'])->middleware('throttle:10,1')->name('console.infrastructure.lists.assignment.update');
    Route::put('/app/workspaces/{workspace}/infrastructure/lists/{listId}', [AudienceListManagementController::class, 'update'])->whereUuid('listId')->middleware('throttle:10,1')->name('console.infrastructure.lists.update');
    Route::patch('/app/workspaces/{workspace}/infrastructure/lists/{listId}/status', [AudienceListManagementController::class, 'setStatus'])->whereUuid('listId')->middleware('throttle:10,1')->name('console.infrastructure.lists.status');
    Route::patch('/app/workspaces/{workspace}/infrastructure/lists/{listId}/track', [AudienceListManagementController::class, 'setTracking'])->whereUuid('listId')->middleware('throttle:10,1')->name('console.infrastructure.lists.track');
    Route::patch('/app/workspaces/{workspace}/infrastructure/lists/{listId}/lock', [AudienceListManagementController::class, 'setLock'])->whereUuid('listId')->middleware('throttle:20,1')->name('console.infrastructure.lists.lock');
    Route::get('/app/workspaces/{workspace}/infrastructure/domains', [InfrastructureConsoleController::class, 'domains'])->name('console.infrastructure.domains.index');
    Route::get('/app/workspaces/{workspace}/infrastructure/domain-providers', [InfrastructureConsoleController::class, 'domainProvidersIndex'])->name('console.infrastructure.domain-providers.index');
    Route::get('/app/workspaces/{workspace}/infrastructure/domains/{domainId}/history', [InfrastructureConsoleController::class, 'domainHistory'])->whereUuid('domainId')->name('console.infrastructure.domains.history');
    Route::get('/app/workspaces/{workspace}/infrastructure/domains/{domainId}/records', [InfrastructureConsoleController::class, 'domainRecords'])->whereUuid('domainId')->name('console.infrastructure.domains.records');
    Route::post('/app/workspaces/{workspace}/infrastructure/domains/{domainId}/records', [InfrastructureConsoleController::class, 'domainRecords'])->whereUuid('domainId')->middleware('throttle:10,1')->name('console.infrastructure.domains.records.update');
    Route::post('/app/workspaces/{workspace}/infrastructure/domains/{domainId}/records/cloudflare', [InfrastructureConsoleController::class, 'createCloudflareDomainRecord'])->whereUuid('domainId')->middleware('throttle:10,1')->name('console.infrastructure.domains.records.cloudflare.store');
    Route::patch('/app/workspaces/{workspace}/infrastructure/domains/{domainId}/records/cloudflare/{recordId}', [InfrastructureConsoleController::class, 'updateCloudflareDomainRecord'])->whereUuid('domainId')->where('recordId', '[A-Fa-f0-9]{32}')->middleware('throttle:10,1')->name('console.infrastructure.domains.records.cloudflare.update');
    Route::delete('/app/workspaces/{workspace}/infrastructure/domains/{domainId}/records/cloudflare/{recordId}', [InfrastructureConsoleController::class, 'deleteCloudflareDomainRecord'])->whereUuid('domainId')->where('recordId', '[A-Fa-f0-9]{32}')->middleware('throttle:10,1')->name('console.infrastructure.domains.records.cloudflare.destroy');
    Route::get('/app/workspaces/{workspace}/infrastructure/domain-providers/create', [InfrastructureConsoleController::class, 'createDmsDomainProvider'])->name('console.infrastructure.domain-providers.create');
    Route::get('/app/workspaces/{workspace}/infrastructure/domain-providers/{providerId}/edit', [InfrastructureConsoleController::class, 'editDmsDomainProvider'])->whereNumber('providerId')->name('console.infrastructure.domain-providers.edit');
    Route::get('/app/workspaces/{workspace}/infrastructure/domains/create', [InfrastructureConsoleController::class, 'createDmsInstallDomains'])->name('console.infrastructure.domains.create');
    Route::get('/app/workspaces/{workspace}/infrastructure/install-username', [InfrastructureConsoleController::class, 'editInstallUsername'])->name('console.infrastructure.install-username.edit');
    Route::get('/app/workspaces/{workspace}/infrastructure/install-settings', [InfrastructureConsoleController::class, 'editInstallSettings'])->name('console.infrastructure.install-settings.edit');
    Route::get('/app/workspaces/{workspace}/infrastructure/install-settings/pmta-domains', [InfrastructureConsoleController::class, 'loadPmtaDomainSettings'])->name('console.infrastructure.install-settings.domains.load');
    Route::get('/app/workspaces/{workspace}/infrastructure/bulk-install/runs/{runId}/logs', [InfrastructureConsoleController::class, 'bulkInstallLogs'])
        ->where('runId', '[0-9]+(?:\\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}')
        ->name('console.infrastructure.bulk-install.logs');
    Route::post('/app/workspaces/{workspace}/infrastructure/bulk-install/runs', [InfrastructureConsoleController::class, 'startBulkInstall'])
        ->middleware('throttle:3,1')->name('console.infrastructure.bulk-install.runs.store');
    Route::post('/app/workspaces/{workspace}/infrastructure/install/runs', [InfrastructureConsoleController::class, 'startSingleInstall'])
        ->middleware('throttle:3,1')->name('console.infrastructure.install.runs.store');
    Route::post('/app/workspaces/{workspace}/infrastructure/isps', [InfrastructureConsoleController::class, 'storeIsp'])->middleware('throttle:10,1')->name('console.infrastructure.isps.store');
    Route::put('/app/workspaces/{workspace}/infrastructure/isps/{ispId}', [InfrastructureConsoleController::class, 'updateIsp'])->whereNumber('ispId')->middleware('throttle:10,1')->name('console.infrastructure.isps.update');
    Route::patch('/app/workspaces/{workspace}/infrastructure/isps/{ispId}/status', [InfrastructureConsoleController::class, 'setIspStatus'])->whereNumber('ispId')->middleware('throttle:10,1')->name('console.infrastructure.isps.status');
    Route::put('/app/workspaces/{workspace}/infrastructure/isps/{ispId}/users', [InfrastructureConsoleController::class, 'updateIspUsers'])->whereNumber('ispId')->middleware('throttle:10,1')->name('console.infrastructure.isps.users.update');
    Route::put('/app/workspaces/{workspace}/infrastructure/isps/{ispId}/sponsors', [InfrastructureConsoleController::class, 'updateIspSponsors'])->whereNumber('ispId')->middleware('throttle:10,1')->name('console.infrastructure.isps.sponsors.update');
    Route::patch('/app/workspaces/{workspace}/infrastructure/domains/{domainId}/active', [InfrastructureConsoleController::class, 'setDomainActive'])->whereUuid('domainId')->name('console.infrastructure.domains.active');
    Route::patch('/app/workspaces/{workspace}/infrastructure/domains/{domainId}/status', [InfrastructureConsoleController::class, 'setDomainStatus'])->whereUuid('domainId')->name('console.infrastructure.domains.status');
    Route::put('/app/workspaces/{workspace}/infrastructure/domains/{domainId}', [InfrastructureConsoleController::class, 'refreshDomain'])->whereUuid('domainId')->middleware('throttle:10,1')->name('console.infrastructure.domains.update');
    Route::delete('/app/workspaces/{workspace}/infrastructure/domains', [InfrastructureConsoleController::class, 'deleteDomains'])->name('console.infrastructure.domains.destroy');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers', [InfrastructureConsoleController::class, 'serversIndex'])->name('console.infrastructure.servers.index');
    Route::get('/app/workspaces/{workspace}/infrastructure/server-providers', [ServerProviderConsoleController::class, 'index'])->name('console.infrastructure.server-providers.index');
    Route::get('/app/workspaces/{workspace}/infrastructure/server-providers/create', [ServerProviderConsoleController::class, 'create'])->name('console.infrastructure.server-providers.create');
    Route::get('/app/workspaces/{workspace}/infrastructure/server-providers/{providerId}/edit', [ServerProviderConsoleController::class, 'edit'])->whereNumber('providerId')->name('console.infrastructure.server-providers.edit');
    Route::post('/app/workspaces/{workspace}/infrastructure/server-providers', [ServerProviderConsoleController::class, 'store'])->middleware('throttle:10,1')->name('console.infrastructure.server-providers.store');
    Route::put('/app/workspaces/{workspace}/infrastructure/server-providers/{providerId}', [ServerProviderConsoleController::class, 'update'])->whereNumber('providerId')->middleware('throttle:10,1')->name('console.infrastructure.server-providers.update');
    Route::delete('/app/workspaces/{workspace}/infrastructure/server-providers/{providerId}', [ServerProviderConsoleController::class, 'destroy'])->whereNumber('providerId')->middleware('throttle:10,1')->name('console.infrastructure.server-providers.destroy');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers/{server}/ips', [InfrastructureConsoleController::class, 'serverIps'])->whereUuid('server')->name('console.infrastructure.servers.ips');
    Route::get('/app/workspaces/{workspace}/infrastructure/my-ips', [InfrastructureConsoleController::class, 'myIps'])->name('console.infrastructure.my-ips');
    Route::get('/app/workspaces/{workspace}/infrastructure/my-ips/export', [InfrastructureConsoleController::class, 'exportMyIps'])->name('console.infrastructure.my-ips.export');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers/{server}/mtas/{type}', [InfrastructureConsoleController::class, 'serverMtas'])->whereUuid('server')->whereIn('type', ['vmta', 'smtp'])->name('console.infrastructure.servers.mtas');
    Route::patch('/app/workspaces/{workspace}/infrastructure/servers/{server}/ips/{ip}', [InfrastructureConsoleController::class, 'setServerIpStatus'])->whereUuid('server')->whereNumber('ip')->middleware('throttle:10,1')->name('console.infrastructure.servers.ips.status');
    Route::patch('/app/workspaces/{workspace}/infrastructure/servers/{server}/mtas/{mta}', [InfrastructureConsoleController::class, 'setServerMtaStatus'])->whereUuid('server')->whereNumber('mta')->middleware('throttle:10,1')->name('console.infrastructure.servers.mtas.status');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers/{server}/ips/{ip}/history', [InfrastructureConsoleController::class, 'serverIpHistory'])->whereUuid('server')->whereNumber('ip')->name('console.infrastructure.servers.ips.history');
    Route::match(['get', 'post'], '/app/workspaces/{workspace}/infrastructure/servers/{server}/console', [InfrastructureConsoleController::class, 'serverConsole'])->whereUuid('server')->name('console.infrastructure.servers.console');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers/{server}/history', [InfrastructureConsoleController::class, 'serverHistory'])->name('console.infrastructure.servers.history');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers/create', [InfrastructureConsoleController::class, 'createServer'])->name('console.infrastructure.servers.create');
    Route::get('/app/workspaces/{workspace}/infrastructure/servers/{server}/edit', [InfrastructureConsoleController::class, 'editServer'])->whereUuid('server')->name('console.infrastructure.servers.edit');
    Route::put('/app/workspaces/{workspace}/infrastructure/servers/{server}', [InfrastructureConsoleController::class, 'updateServer'])->whereUuid('server')->middleware('throttle:10,1')->name('console.infrastructure.servers.update');
    Route::post('/app/workspaces/{workspace}/infrastructure/servers/{server}/test-connection', [InfrastructureConsoleController::class, 'testExistingServerConnection'])->whereUuid('server')->middleware('throttle:10,1')->name('console.infrastructure.servers.test-existing-connection');
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
    Route::post('/app/workspaces/{workspace}/infrastructure/domains/manual', [InfrastructureConsoleController::class, 'storeDmsInstallDomains'])->middleware('throttle:10,1')->name('console.infrastructure.domains.manual');
    Route::post('/app/workspaces/{workspace}/infrastructure/domains/import', [InfrastructureConsoleController::class, 'importDmsInstallDomains'])->middleware('throttle:3,1')->name('console.infrastructure.domains.import');
    Route::post('/app/workspaces/{workspace}/infrastructure/domain-providers', [InfrastructureConsoleController::class, 'storeDomainProvider'])->middleware('throttle:10,1')->name('console.infrastructure.domain-providers.store');
    Route::post('/app/workspaces/{workspace}/infrastructure/domain-providers/test-connection', [InfrastructureConsoleController::class, 'testNewDomainProviderConnection'])->middleware('throttle:10,1')->name('console.infrastructure.domain-providers.test-new-connection');
    Route::post('/app/workspaces/{workspace}/infrastructure/domain-providers/{providerId}/test-connection', [InfrastructureConsoleController::class, 'testExistingDomainProviderConnection'])->whereNumber('providerId')->middleware('throttle:10,1')->name('console.infrastructure.domain-providers.test-existing-connection');
    Route::put('/app/workspaces/{workspace}/infrastructure/domain-providers/{providerId}', [InfrastructureConsoleController::class, 'updateDmsDomainProvider'])->whereNumber('providerId')->middleware('throttle:10,1')->name('console.infrastructure.domain-providers.update');
    Route::patch('/app/workspaces/{workspace}/infrastructure/domain-providers/{providerId}/status', [InfrastructureConsoleController::class, 'setDmsDomainProviderStatus'])->whereNumber('providerId')->middleware('throttle:10,1')->name('console.infrastructure.domain-providers.status');
    Route::put('/app/workspaces/{workspace}/infrastructure/install-username', [InfrastructureConsoleController::class, 'updateInstallUsername'])->middleware('throttle:10,1')->name('console.infrastructure.install-username.update');
    Route::post('/app/workspaces/{workspace}/infrastructure/install-settings', [AppSettingsConsoleController::class, 'updateInstallSettings'])->middleware('throttle:10,1')->name('console.infrastructure.install-settings.update');
    Route::post('/app/workspaces/{workspace}/infrastructure/install-settings/pmta-domains', [InfrastructureConsoleController::class, 'updatePmtaDomainSettings'])->middleware('throttle:10,1')->name('console.infrastructure.install-settings.domains.update');
    Route::post('/app/workspaces/{workspace}/infrastructure/domains/{domain}/verify', [InfrastructureConsoleController::class, 'verifyDomain'])->middleware('throttle:10,1')->name('console.infrastructure.domains.verify');
    Route::post('/app/workspaces/{workspace}/infrastructure/plans', [InfrastructureConsoleController::class, 'storePlan'])->middleware('throttle:10,1')->name('console.infrastructure.plans.store');
});

$workspaceRoutePrefix = 'app/workspaces/{workspace}';
$existingRouteKeys = [];
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    foreach ($registeredRoute->methods() as $method) {
        $existingRouteKeys[$method.' '.$registeredRoute->uri()] = true;
    }
}

foreach (Route::getRoutes()->getRoutes() as $workspaceRoute) {
    $legacyUri = $workspaceRoute->uri();
    if ($legacyUri !== $workspaceRoutePrefix && ! str_starts_with($legacyUri, $workspaceRoutePrefix.'/')) {
        continue;
    }

    $workspaceRoute->middleware('workspace.legacy-redirect');
    $suffix = trim(substr($legacyUri, strlen($workspaceRoutePrefix)), '/');
    $cleanUri = match ($suffix) {
        '', 'analytics' => 'dashboard/main',
        'campaigns' => 'mailer/campaigns',
        default => str_starts_with($suffix, 'campaigns/')
            ? 'mailer/campaigns/'.substr($suffix, strlen('campaigns/'))
            : $suffix,
    };
    $middleware = array_values(array_unique(array_merge(
        ['auth', 'active', 'workspace.current'],
        array_diff($workspaceRoute->gatherMiddleware(), ['auth', 'active', 'workspace.member', 'workspace.current', 'workspace.legacy-redirect']),
    )));
    $action = $workspaceRoute->getAction();
    unset($action['as']);
    $action['middleware'] = $middleware;

    $workspaceMethods = $workspaceRoute->methods();
    if (in_array('GET', $workspaceMethods, true)) {
        // Laravel's GET routes already answer HEAD requests. Cloning the
        // implicit HEAD method separately creates a second route with the
        // same name and makes `route:cache` fail during deployment.
        $workspaceMethods = array_values(array_diff($workspaceMethods, ['HEAD']));
    }

    $missingMethods = array_values(array_filter(
        $workspaceMethods,
        fn (string $method): bool => ! isset($existingRouteKeys[$method.' '.$cleanUri]),
    ));
    if ($missingMethods === []) {
        continue;
    }

    $cleanRouteName = match ($suffix) {
        'analytics' => 'console.dashboard.main',
        'campaigns' => in_array('GET', $missingMethods, true)
            ? 'console.mailer.campaigns'
            : ($workspaceRoute->getName() ? 'clean.'.$workspaceRoute->getName() : null),
        default => $workspaceRoute->getName() ? 'clean.'.$workspaceRoute->getName() : null,
    };

    $cleanRoute = Route::match($missingMethods, $cleanUri, $action)->middleware($middleware);
    $constraints = $workspaceRoute->wheres;
    unset($constraints['workspace']);
    if ($constraints !== []) {
        $cleanRoute->where($constraints);
    }
    if ($cleanRouteName !== null) {
        $cleanRoute->name($cleanRouteName);
    }
    foreach ($missingMethods as $method) {
        $existingRouteKeys[$method.' '.$cleanUri] = true;
    }
}
