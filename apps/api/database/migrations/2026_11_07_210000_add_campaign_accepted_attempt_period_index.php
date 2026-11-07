<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_delivery_attempts', function (Blueprint $table): void {
            $table->index(['workspace_id', 'state', 'finished_at'], 'campaign_attempt_sent_period_idx');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_delivery_attempts', function (Blueprint $table): void {
            $table->dropIndex('campaign_attempt_sent_period_idx');
        });
    }
};
