<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONLY run this if your recipients table has no `country` column yet.
 * Then fill it when a recipient is created — e.g. from their IP using
 * MaxMind GeoLite2 (composer require geoip2/geoip2) or a lookup service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->index()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('recipients', function (Blueprint $table) {
            $table->dropIndex(['country']);
            $table->dropColumn('country');
        });
    }
};
