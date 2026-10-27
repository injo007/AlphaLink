<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->dropUnique('clm_workspace_contact_uq');
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'campaigns_workspace_id_uq');
        });

        Schema::create('campaign_recipient_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('campaign_id');
            $table->foreignUuid('revision_id')->constrained('campaign_revisions')->restrictOnDelete();
            $table->foreignId('offer_id')->constrained('workspace_offers')->restrictOnDelete();
            $table->char('suppression_sha256', 64);
            $table->jsonb('selection');
            $table->unsignedBigInteger('recipient_count');
            $table->unsignedBigInteger('offer_suppressed_count')->default(0);
            $table->unsignedBigInteger('workspace_suppressed_count')->default(0);
            $table->unsignedBigInteger('duplicate_count')->default(0);
            $table->unsignedBigInteger('start_from')->default(0);
            $table->char('recipients_sha256', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique('revision_id', 'campaign_snapshot_revision_uq');
            $table->unique(['workspace_id', 'id'], 'campaign_snapshot_workspace_id_uq');
            $table->foreign(['workspace_id', 'campaign_id'], 'campaign_snapshot_workspace_campaign_fk')
                ->references(['workspace_id', 'id'])->on('campaigns')->restrictOnDelete();
            $table->index(['workspace_id', 'created_at'], 'campaign_snapshot_scope_idx');
        });

        Schema::create('campaign_recipient_snapshot_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('snapshot_id');
            $table->foreignUuid('contact_id');
            $table->unsignedBigInteger('position');
            $table->char('email_lookup_hash', 64);
            $table->uuid('source_list_id');
            $table->string('source_email_type', 24);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['snapshot_id', 'position'], 'campaign_snapshot_position_uq');
            $table->unique(['snapshot_id', 'contact_id'], 'campaign_snapshot_contact_uq');
            $table->foreign(['workspace_id', 'snapshot_id'], 'campaign_snapshot_item_snapshot_fk')
                ->references(['workspace_id', 'id'])->on('campaign_recipient_snapshots')->restrictOnDelete();
            $table->foreign(['workspace_id', 'contact_id'], 'campaign_snapshot_item_contact_fk')
                ->references(['workspace_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->index(['workspace_id', 'contact_id'], 'campaign_snapshot_item_contact_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER alphalink_prevent_mutation BEFORE UPDATE OR DELETE ON campaign_recipient_snapshots FOR EACH ROW EXECUTE FUNCTION alphalink_reject_immutable_mutation()');
            DB::unprepared('CREATE TRIGGER alphalink_prevent_mutation BEFORE UPDATE OR DELETE ON campaign_recipient_snapshot_items FOR EACH ROW EXECUTE FUNCTION alphalink_reject_immutable_mutation()');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS alphalink_prevent_mutation ON campaign_recipient_snapshot_items');
            DB::unprepared('DROP TRIGGER IF EXISTS alphalink_prevent_mutation ON campaign_recipient_snapshots');
        }

        Schema::dropIfExists('campaign_recipient_snapshot_items');
        Schema::dropIfExists('campaign_recipient_snapshots');
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropUnique('campaigns_workspace_id_uq');
        });
        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'contact_id'], 'clm_workspace_contact_uq');
        });
    }
};
