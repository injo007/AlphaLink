<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'clm_workspace_id_pair_uq');
        });

        Schema::table('campaign_recipient_snapshot_items', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'campaign_snapshot_item_workspace_id_uq');
        });

        Schema::table('campaign_engagement_events', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'campaign_event_workspace_id_uq');
        });

        Schema::table('audience_mix_snapshot_members', function (Blueprint $table): void {
            $table->foreignUuid('source_membership_id')->nullable();
            $table->foreign(['workspace_id', 'source_membership_id'], 'aud_mix_member_workspace_membership_fk')
                ->references(['workspace_id', 'id'])->on('contact_list_memberships')->restrictOnDelete();
            $table->index(['workspace_id', 'source_membership_id'], 'aud_mix_member_source_membership_idx');
        });

        Schema::create('campaign_recipient_snapshot_item_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('snapshot_item_id');
            $table->foreignUuid('source_membership_id')->nullable();
            $table->foreignUuid('source_list_id');
            $table->string('source_email_type', 24);
            $table->char('source_key', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['workspace_id', 'snapshot_item_id', 'source_key'], 'campaign_snapshot_source_unique');
            $table->foreign(['workspace_id', 'snapshot_item_id'], 'campaign_snapshot_source_item_fk')
                ->references(['workspace_id', 'id'])->on('campaign_recipient_snapshot_items')->restrictOnDelete();
            $table->foreign(['workspace_id', 'source_membership_id'], 'campaign_snapshot_source_membership_fk')
                ->references(['workspace_id', 'id'])->on('contact_list_memberships')->restrictOnDelete();
            $table->foreign(['workspace_id', 'source_list_id'], 'campaign_snapshot_source_list_fk')
                ->references(['workspace_id', 'id'])->on('audience_lists')->restrictOnDelete();
            $table->index(['workspace_id', 'source_list_id', 'source_email_type'], 'campaign_snapshot_source_list_type_idx');
        });

        Schema::create('campaign_engagement_event_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('engagement_event_id');
            $table->foreignUuid('source_membership_id')->nullable();
            $table->foreignUuid('source_list_id');
            $table->string('source_email_type', 24);
            $table->char('source_key', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['workspace_id', 'engagement_event_id', 'source_key'], 'campaign_event_source_unique');
            $table->foreign(['workspace_id', 'engagement_event_id'], 'campaign_event_source_event_fk')
                ->references(['workspace_id', 'id'])->on('campaign_engagement_events')->restrictOnDelete();
            $table->foreign(['workspace_id', 'source_membership_id'], 'campaign_event_source_membership_fk')
                ->references(['workspace_id', 'id'])->on('contact_list_memberships')->restrictOnDelete();
            $table->foreign(['workspace_id', 'source_list_id'], 'campaign_event_source_list_fk')
                ->references(['workspace_id', 'id'])->on('audience_lists')->restrictOnDelete();
            $table->index(['workspace_id', 'source_list_id', 'created_at'], 'campaign_event_source_list_time_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER alphalink_prevent_mutation BEFORE UPDATE OR DELETE ON campaign_recipient_snapshot_item_sources FOR EACH ROW EXECUTE FUNCTION alphalink_reject_immutable_mutation()');
            DB::unprepared('CREATE TRIGGER alphalink_prevent_mutation BEFORE UPDATE OR DELETE ON campaign_engagement_event_sources FOR EACH ROW EXECUTE FUNCTION alphalink_reject_immutable_mutation()');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('campaign_recipient_snapshot_item_sources')
            && (DB::table('campaign_recipient_snapshot_item_sources')->exists()
                || DB::table('campaign_engagement_event_sources')->exists())) {
            throw new RuntimeException('Campaign source provenance is in use and cannot be rolled back without losing attribution data.');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS alphalink_prevent_mutation ON campaign_engagement_event_sources');
            DB::unprepared('DROP TRIGGER IF EXISTS alphalink_prevent_mutation ON campaign_recipient_snapshot_item_sources');
        }

        Schema::dropIfExists('campaign_engagement_event_sources');
        Schema::dropIfExists('campaign_recipient_snapshot_item_sources');

        Schema::table('audience_mix_snapshot_members', function (Blueprint $table): void {
            $table->dropIndex('aud_mix_member_source_membership_idx');
            $table->dropForeign('aud_mix_member_workspace_membership_fk');
            $table->dropColumn('source_membership_id');
        });
        Schema::table('campaign_engagement_events', function (Blueprint $table): void {
            $table->dropUnique('campaign_event_workspace_id_uq');
        });
        Schema::table('campaign_recipient_snapshot_items', function (Blueprint $table): void {
            $table->dropUnique('campaign_snapshot_item_workspace_id_uq');
        });
        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->dropUnique('clm_workspace_id_pair_uq');
        });
    }
};
