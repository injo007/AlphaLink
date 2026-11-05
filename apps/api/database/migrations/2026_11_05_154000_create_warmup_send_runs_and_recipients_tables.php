<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warmup_send_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('campaign_id');
            $table->foreignUuid('revision_id');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('send_type', 16);
            $table->string('state', 24)->default('planned');
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->jsonb('execution_options')->nullable();
            $table->unsignedBigInteger('recipient_count');
            $table->unsignedBigInteger('next_position')->default(1);
            $table->unsignedBigInteger('accepted_count')->default(0);
            $table->unsignedBigInteger('rejected_count')->default(0);
            $table->unsignedBigInteger('uncertain_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'id'], 'warmup_send_run_workspace_id_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'warmup_send_run_idempotency_uq');
            $table->foreign(['workspace_id', 'campaign_id'], 'warmup_send_run_campaign_fk')
                ->references(['workspace_id', 'id'])->on('campaigns')->restrictOnDelete();
            $table->foreign(['campaign_id', 'revision_id'], 'warmup_send_run_revision_fk')
                ->references(['campaign_id', 'id'])->on('campaign_revisions')->restrictOnDelete();
            $table->index(['workspace_id', 'state', 'created_at'], 'warmup_send_run_state_idx');
            $table->index(['workspace_id', 'campaign_id', 'created_at'], 'warmup_send_run_campaign_idx');
        });

        Schema::create('warmup_send_recipients', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('send_run_id');
            $table->unsignedBigInteger('position');
            $table->text('recipient_ciphertext');
            $table->char('recipient_sha256', 64);
            $table->string('state', 20)->default('pending');
            $table->string('transport_code', 48)->nullable();
            $table->string('error_code', 96)->nullable();
            $table->char('response_sha256', 64)->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['send_run_id', 'position'], 'warmup_send_recipient_position_uq');
            $table->unique(['send_run_id', 'recipient_sha256'], 'warmup_send_recipient_hash_uq');
            $table->foreign(['workspace_id', 'send_run_id'], 'warmup_send_recipient_run_fk')
                ->references(['workspace_id', 'id'])->on('warmup_send_runs')->restrictOnDelete();
            $table->index(['send_run_id', 'state', 'position'], 'warmup_send_recipient_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warmup_send_recipients');
        Schema::dropIfExists('warmup_send_runs');
    }
};
