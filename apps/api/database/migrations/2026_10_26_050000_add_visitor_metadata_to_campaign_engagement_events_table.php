<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_engagement_events', function (Blueprint $table): void {
            $table->string('country', 128)->nullable();
            $table->string('state', 128)->nullable();
            $table->string('city', 128)->nullable();
            $table->string('device', 128)->nullable();
            $table->string('platform', 128)->nullable();
            $table->string('browser', 128)->nullable();
            $table->boolean('is_mobile')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_engagement_events', function (Blueprint $table): void {
            $table->dropColumn([
                'country', 'state', 'city', 'device', 'platform', 'browser', 'is_mobile',
            ]);
        });
    }
};
