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
        Schema::table('campaign_recipient_snapshot_items', function (Blueprint $table): void {
            $table->text('recipient_first_name')->nullable();
            $table->text('recipient_last_name')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('campaign_recipient_snapshot_items')
            ->whereNotNull('recipient_first_name')
            ->orWhereNotNull('recipient_last_name')
            ->exists()) {
            throw new RuntimeException('Personalized recipient names are stored in immutable campaign snapshots and cannot be removed without losing historical send data.');
        }

        Schema::table('campaign_recipient_snapshot_items', function (Blueprint $table): void {
            $table->dropColumn(['recipient_first_name', 'recipient_last_name']);
        });
    }
};
