<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipient_snapshot_item_addresses', function (Blueprint $table): void {
            $table->uuid('snapshot_item_id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->char('email_lookup_hash', 64);
            $table->text('email');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('snapshot_item_id', 'campaign_snapshot_address_item_fk')
                ->references('id')->on('campaign_recipient_snapshot_items')->restrictOnDelete();
            $table->index(['workspace_id', 'email_lookup_hash'], 'campaign_snapshot_address_hash_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('CREATE TRIGGER alphalink_prevent_mutation BEFORE UPDATE OR DELETE ON campaign_recipient_snapshot_item_addresses FOR EACH ROW EXECUTE FUNCTION alphalink_reject_immutable_mutation()');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('campaign_recipient_snapshot_item_addresses')
            && DB::table('campaign_recipient_snapshot_item_addresses')->exists()) {
            throw new RuntimeException('Campaign recipient address rollback would discard immutable recipient data. Use a forward migration to preserve it.');
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS alphalink_prevent_mutation ON campaign_recipient_snapshot_item_addresses');
        }

        Schema::dropIfExists('campaign_recipient_snapshot_item_addresses');
    }
};
