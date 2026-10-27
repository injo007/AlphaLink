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
        Schema::create('workspace_daily_analytics', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 12);
            $table->string('source_id', 36);
            $table->date('at');
            $table->char('dimensions_hash', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->uuid('campaign_id')->nullable();
            $table->unsignedBigInteger('workspace_drop_id')->nullable();
            $table->string('isp_id', 64)->nullable();
            $table->string('list_id', 64)->nullable();
            $table->string('list_type', 24)->nullable();
            $table->string('server_id', 128)->nullable();
            $table->string('ip_id', 128)->nullable();
            $table->string('domain_id', 128)->nullable();
            $table->string('sponsor_id', 64)->nullable();
            $table->string('offer_id', 64)->nullable();
            $table->string('from_id', 128)->nullable();
            $table->string('subject_id', 128)->nullable();
            $table->string('creative_id', 128)->nullable();
            $table->unsignedBigInteger('opens')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('leads')->default(0);
            $table->unsignedBigInteger('unsubscribes')->default(0);
            $table->unsignedBigInteger('bots')->default(0);
            $table->decimal('lead_revenue', 18, 4)->default(0);
            $table->timestampsTz();

            $table->unique(
                ['workspace_id', 'source_type', 'source_id', 'at', 'dimensions_hash'],
                'workspace_daily_analytics_bucket_uq',
            );
            $table->index(['workspace_id', 'at'], 'workspace_daily_analytics_date_idx');
            $table->index(['workspace_id', 'campaign_id', 'at'], 'workspace_daily_analytics_campaign_idx');
            $table->index(['workspace_id', 'workspace_drop_id', 'at'], 'workspace_daily_analytics_drop_idx');
            $table->index(['workspace_id', 'isp_id', 'at'], 'workspace_daily_analytics_isp_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_daily_analytics');
    }
};
