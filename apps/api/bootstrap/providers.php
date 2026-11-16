<?php

use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;
use Laravel\Sanctum\SanctumServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    SanctumServiceProvider::class,
];
