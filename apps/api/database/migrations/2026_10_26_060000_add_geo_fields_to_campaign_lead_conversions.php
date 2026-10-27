<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_lead_conversions', function (Blueprint $table): void {
            $table->string('country')->nullable()->after('conversion_ip');
            $table->string('state')->nullable()->after('country');
            $table->string('city')->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_lead_conversions', function (Blueprint $table): void {
            $table->dropColumn(['country', 'state', 'city']);
        });
    }
};
