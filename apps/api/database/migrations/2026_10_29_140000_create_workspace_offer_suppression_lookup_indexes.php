<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_offer_suppression_indexes', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('workspace_offers')->cascadeOnDelete();
            $table->char('suppression_sha256', 64);
            $table->string('suppression_format', 16);
            $table->char('key_fingerprint', 64);
            $table->unsignedBigInteger('file_bytes');
            $table->unsignedBigInteger('entry_count')->default(0);
            $table->string('status', 16)->default('building');
            $table->timestampTz('ready_at')->nullable();
            $table->timestampsTz();
            $table->unique(['offer_id', 'suppression_sha256', 'key_fingerprint'], 'offer_suppression_index_identity_uq');
            $table->index(['workspace_id', 'offer_id', 'status'], 'offer_suppression_index_ready_idx');
        });

        Schema::create('workspace_offer_suppression_index_entries', function (Blueprint $table): void {
            $table->foreignId('suppression_index_id')
                ->constrained('workspace_offer_suppression_indexes')
                ->cascadeOnDelete();
            $table->char('entry_hash', 64);
            $table->primary(['suppression_index_id', 'entry_hash'], 'offer_suppression_index_entries_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_offer_suppression_index_entries');
        Schema::dropIfExists('workspace_offer_suppression_indexes');
    }
};
