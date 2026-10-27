<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_manual_test_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('campaign_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('managed_server_id')->constrained()->restrictOnDelete();
            $table->char('recipient_sha256', 64);
            $table->char('idempotency_key_hash', 64)->unique();
            $table->char('request_fingerprint', 64);
            $table->string('status', 32);
            $table->string('exception_type', 160)->nullable();
            $table->timestampsTz();
            $table->index(['workspace_id', 'campaign_id', 'recipient_sha256', 'status'], 'campaign_manual_test_scope_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_manual_test_attempts');
    }
};
