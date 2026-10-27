<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_lead_conversions', function (Blueprint $table): void {
            $table->dropUnique('campaign_lead_provider_identity_uq');
            $table->unique(['workspace_id', 'provider_conversion_id'], 'campaign_lead_workspace_conversion_uq');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_lead_conversions', function (Blueprint $table): void {
            $table->dropUnique('campaign_lead_workspace_conversion_uq');
            $table->unique(['workspace_id', 'sponsor_id', 'provider_conversion_id'], 'campaign_lead_provider_identity_uq');
        });
    }
};
