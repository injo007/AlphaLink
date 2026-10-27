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
        Schema::create('campaign_engagement_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('campaign_id');
            $table->foreignUuid('revision_id')->constrained('campaign_revisions')->restrictOnDelete();
            $table->foreignUuid('recipient_snapshot_id');
            $table->foreignUuid('snapshot_item_id')->constrained('campaign_recipient_snapshot_items')->restrictOnDelete();
            $table->foreignUuid('contact_id');
            $table->foreignUuid('audience_list_id');
            $table->string('source_email_type', 24);
            $table->string('action', 24);
            $table->string('send_id', 128)->nullable();
            $table->unsignedBigInteger('isp_id')->nullable();
            $table->string('client_ip', 45);
            $table->text('user_agent');
            $table->jsonb('dimension_ids')->nullable();
            $table->char('token_sha256', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['workspace_id', 'campaign_id'], 'campaign_events_workspace_campaign_fk')
                ->references(['workspace_id', 'id'])->on('campaigns')->restrictOnDelete();
            $table->foreign(['workspace_id', 'recipient_snapshot_id'], 'campaign_events_workspace_snapshot_fk')
                ->references(['workspace_id', 'id'])->on('campaign_recipient_snapshots')->restrictOnDelete();
            $table->foreign(['workspace_id', 'contact_id'], 'campaign_events_workspace_contact_fk')
                ->references(['workspace_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->foreign(['workspace_id', 'audience_list_id'], 'campaign_events_workspace_list_fk')
                ->references(['workspace_id', 'id'])->on('audience_lists')->restrictOnDelete();
            $table->index(['workspace_id', 'campaign_id', 'created_at'], 'campaign_events_campaign_time_idx');
            $table->index(['workspace_id', 'audience_list_id', 'created_at'], 'campaign_events_list_time_idx');
            $table->index(['workspace_id', 'isp_id', 'created_at'], 'campaign_events_isp_time_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER alphalink_prevent_mutation BEFORE UPDATE OR DELETE ON campaign_engagement_events FOR EACH ROW EXECUTE FUNCTION alphalink_reject_immutable_mutation()');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS alphalink_prevent_mutation ON campaign_engagement_events');
        }

        Schema::dropIfExists('campaign_engagement_events');
    }
};
