<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS clm_campaign_keyset_idx ON contact_list_memberships (workspace_id, audience_list_id, email_type, status, joined_at, id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS aud_mix_campaign_keyset_idx ON audience_mix_snapshot_members (workspace_id, snapshot_id, position, id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS suppression_events_campaign_scope_idx ON suppression_events (workspace_id, contact_id, event_type, scope_type, scope_key, effective_at)');

            return;
        }

        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->index(
                ['workspace_id', 'audience_list_id', 'email_type', 'status', 'joined_at', 'id'],
                'clm_campaign_keyset_idx',
            );
        });
        Schema::table('audience_mix_snapshot_members', function (Blueprint $table): void {
            $table->index(
                ['workspace_id', 'snapshot_id', 'position', 'id'],
                'aud_mix_campaign_keyset_idx',
            );
        });
        Schema::table('suppression_events', function (Blueprint $table): void {
            $table->index(
                ['workspace_id', 'contact_id', 'event_type', 'scope_type', 'scope_key', 'effective_at'],
                'suppression_events_campaign_scope_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS clm_campaign_keyset_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS aud_mix_campaign_keyset_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS suppression_events_campaign_scope_idx');

            return;
        }

        Schema::table('suppression_events', function (Blueprint $table): void {
            $table->dropIndex('suppression_events_campaign_scope_idx');
        });
        Schema::table('audience_mix_snapshot_members', function (Blueprint $table): void {
            $table->dropIndex('aud_mix_campaign_keyset_idx');
        });
        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->dropIndex('clm_campaign_keyset_idx');
        });
    }
};
