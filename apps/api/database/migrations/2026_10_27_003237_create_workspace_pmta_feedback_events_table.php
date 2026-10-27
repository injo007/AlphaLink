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
        Schema::create('workspace_pmta_feedback_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('managed_server_id')->constrained('managed_servers')->restrictOnDelete();
            $table->char('record_key', 64);
            $table->date('at');
            $table->string('source_type', 12);
            $table->string('source_id', 36);
            $table->uuid('campaign_id')->nullable();
            $table->unsignedBigInteger('workspace_drop_id')->nullable();
            $table->string('metric', 16);
            $table->string('category', 64)->nullable();
            $table->uuid('snapshot_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->string('source_list_id', 64)->nullable();
            $table->string('list_type', 24)->nullable();
            $table->json('dimension_ids');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['workspace_id', 'managed_server_id', 'record_key'], 'pmta_feedback_record_uq');
            $table->index(['workspace_id', 'at', 'source_type', 'source_id'], 'pmta_feedback_daily_idx');
        });

        Schema::table('workspace_daily_analytics', function (Blueprint $table): void {
            $table->unsignedBigInteger('deliveries')->default(0);
            $table->unsignedBigInteger('soft_bounces')->default(0);
            $table->unsignedBigInteger('hard_bounces')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_daily_analytics', function (Blueprint $table): void {
            $table->dropColumn(['deliveries', 'soft_bounces', 'hard_bounces']);
        });
        Schema::dropIfExists('workspace_pmta_feedback_events');
    }
};
