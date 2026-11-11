<?php

use App\Http\Controllers\Dashboard\DeliverabilityGeoController;
use Illuminate\Support\Facades\Route;

// Add this inside routes/web.php (inside your auth middleware group):
Route::get('/dashboard/geo-deliverability', DeliverabilityGeoController::class)
    ->middleware('auth')
    ->name('dashboard.geo-deliverability');
