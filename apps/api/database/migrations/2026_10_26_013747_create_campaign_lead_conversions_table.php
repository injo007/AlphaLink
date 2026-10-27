<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('campaign_lead_conversions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->restrictOnDelete();
            $table->foreignUuid('revision_id')->constrained('campaign_revisions')->restrictOnDelete();
            $table->foreignUuid('recipient_snapshot_id')->constrained('campaign_recipient_snapshots')->restrictOnDelete();
            $table->foreignUuid('snapshot_item_id')->constrained('campaign_recipient_snapshot_items')->restrictOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUuid('audience_list_id')->constrained('audience_lists')->restrictOnDelete();
            $table->foreignId('sponsor_id')->constrained('workspace_sponsors')->restrictOnDelete();
            $table->foreignId('offer_id')->constrained('workspace_offers')->restrictOnDelete();
            $table->string('provider_type', 32);
            $table->string('provider_conversion_id', 255);
            $table->decimal('revenue', 16, 4);
            $table->string('conversion_ip', 45)->nullable();
            $table->foreignId('isp_id')->nullable()->constrained('isps')->nullOnDelete();
            $table->jsonb('dimension_ids')->nullable();
            $table->timestampTz('converted_at');
            $table->timestampsTz();
            $table->unique(['workspace_id', 'sponsor_id', 'provider_conversion_id'], 'campaign_lead_provider_identity_uq');
            $table->index(['workspace_id', 'campaign_id', 'converted_at'], 'campaign_leads_campaign_time_idx');
            $table->index(['workspace_id', 'audience_list_id', 'converted_at'], 'campaign_leads_list_time_idx');
            $table->index(['workspace_id', 'isp_id', 'converted_at'], 'campaign_leads_isp_time_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_lead_conversions');
    }
};
