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
        Schema::create('audience_mix_snapshot_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('snapshot_id')->constrained('audience_mix_snapshots')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUuid('source_list_id')->constrained('audience_lists')->restrictOnDelete();
            $table->string('email_type', 24);
            $table->unsignedBigInteger('position');
            $table->timestampTz('created_at')->useCurrent();
            // DMS Mix writes each selected list/type row to the snapshot. A
            // contact may therefore appear more than once across source lists
            // or email types; campaign send applies its own recipient dedupe.
            $table->unique(['snapshot_id', 'contact_id', 'source_list_id', 'email_type'], 'aud_mix_member_snapshot_source_uq');
            $table->index(['workspace_id', 'snapshot_id', 'position'], 'aud_mix_member_workspace_snapshot_position_idx');
            $table->index(['workspace_id', 'source_list_id', 'email_type'], 'aud_mix_member_workspace_source_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audience_mix_snapshot_members');
    }
};
