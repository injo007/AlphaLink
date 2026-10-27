<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_test_mail_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('revision_id')->constrained('campaign_revisions')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('recipient_sha256', 64);
            $table->char('idempotency_key_hash', 64)->unique();
            $table->string('mailer', 32);
            $table->string('status', 32);
            $table->string('exception_type', 160)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['workspace_id', 'campaign_id', 'status', 'created_at'], 'campaign_test_attempts_scope_idx');
            $table->index(['campaign_id', 'revision_id', 'recipient_sha256'], 'campaign_test_attempts_repeat_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_test_mail_attempts');
    }
};
