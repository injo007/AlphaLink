<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_revisions', function (Blueprint $table): void {
            $table->unique(['campaign_id', 'id'], 'campaign_revision_campaign_id_uq');
        });

        Schema::table('campaign_recipient_snapshots', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'campaign_id', 'id', 'revision_id'], 'campaign_snapshot_run_scope_uq');
        });

        Schema::table('campaign_recipient_snapshot_items', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'snapshot_id', 'id'], 'campaign_snapshot_item_scope_uq');
        });

        Schema::create('campaign_send_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('campaign_id');
            $table->foreignUuid('revision_id');
            $table->foreignUuid('recipient_snapshot_id');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('send_type', 16);
            $table->string('state', 24)->default('planned');
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->jsonb('execution_options')->nullable();
            $table->unsignedBigInteger('recipient_count');
            $table->unsignedBigInteger('start_position')->default(1);
            $table->unsignedBigInteger('next_position')->default(1);
            $table->unsignedBigInteger('accepted_count')->default(0);
            $table->unsignedBigInteger('rejected_count')->default(0);
            $table->unsignedBigInteger('suppressed_count')->default(0);
            $table->unsignedBigInteger('uncertain_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'id'], 'campaign_send_run_workspace_id_uq');
            $table->unique(['workspace_id', 'id', 'recipient_snapshot_id'], 'campaign_send_run_snapshot_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'campaign_send_run_idempotency_uq');
            $table->foreign(['workspace_id', 'campaign_id'], 'campaign_send_run_campaign_fk')
                ->references(['workspace_id', 'id'])->on('campaigns')->restrictOnDelete();
            $table->foreign(['campaign_id', 'revision_id'], 'campaign_send_run_revision_fk')
                ->references(['campaign_id', 'id'])->on('campaign_revisions')->restrictOnDelete();
            $table->foreign(['workspace_id', 'campaign_id', 'recipient_snapshot_id', 'revision_id'], 'campaign_send_run_snapshot_fk')
                ->references(['workspace_id', 'campaign_id', 'id', 'revision_id'])->on('campaign_recipient_snapshots')->restrictOnDelete();
            $table->index(['workspace_id', 'state', 'created_at'], 'campaign_send_run_state_idx');
            $table->index(['workspace_id', 'campaign_id', 'created_at'], 'campaign_send_run_campaign_idx');
        });

        Schema::create('campaign_send_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('send_run_id');
            $table->unsignedInteger('batch_number');
            $table->uuid('claim_request_key');
            $table->char('claim_request_fingerprint', 64);
            $table->unsignedBigInteger('first_position');
            $table->unsignedBigInteger('last_position');
            $table->string('state', 20)->default('ready');
            $table->string('lease_owner', 128)->nullable();
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->unsignedInteger('claim_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->unsignedInteger('suppressed_count')->default(0);
            $table->unsignedInteger('uncertain_count')->default(0);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['send_run_id', 'batch_number'], 'campaign_send_batch_number_uq');
            $table->unique(['send_run_id', 'claim_request_key'], 'campaign_send_batch_claim_req_uq');
            $table->unique(['workspace_id', 'send_run_id', 'id'], 'campaign_send_batch_scope_uq');
            $table->foreign(['workspace_id', 'send_run_id'], 'campaign_send_batch_run_fk')
                ->references(['workspace_id', 'id'])->on('campaign_send_runs')->restrictOnDelete();
            $table->index(['send_run_id', 'state', 'lease_expires_at'], 'campaign_send_batch_lease_idx');
        });

        Schema::create('campaign_send_recipient_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('send_run_id');
            $table->foreignUuid('recipient_snapshot_id');
            $table->foreignUuid('snapshot_item_id');
            $table->foreignUuid('batch_id');
            $table->unsignedBigInteger('position');
            $table->string('state', 20)->default('claimed');
            $table->uuid('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->unsignedSmallInteger('claim_count')->default(0);
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('last_transition_at')->useCurrent();
            $table->timestampsTz();

            $table->unique(['send_run_id', 'snapshot_item_id'], 'campaign_send_claim_recipient_uq');
            $table->unique(['workspace_id', 'send_run_id', 'snapshot_item_id'], 'campaign_send_claim_scope_uq');
            $table->unique(['workspace_id', 'send_run_id', 'snapshot_item_id', 'batch_id'], 'campaign_send_claim_batch_scope_uq');
            $table->foreign(['workspace_id', 'send_run_id', 'recipient_snapshot_id'], 'campaign_send_claim_run_fk')
                ->references(['workspace_id', 'id', 'recipient_snapshot_id'])->on('campaign_send_runs')->restrictOnDelete();
            $table->foreign(['workspace_id', 'recipient_snapshot_id', 'snapshot_item_id'], 'campaign_send_claim_snapshot_item_fk')
                ->references(['workspace_id', 'snapshot_id', 'id'])->on('campaign_recipient_snapshot_items')->restrictOnDelete();
            $table->foreign(['workspace_id', 'send_run_id', 'batch_id'], 'campaign_send_claim_batch_fk')
                ->references(['workspace_id', 'send_run_id', 'id'])->on('campaign_send_batches')->restrictOnDelete();
            $table->index(['send_run_id', 'state', 'lease_expires_at'], 'campaign_send_claim_lease_idx');
            $table->index(['send_run_id', 'position'], 'campaign_send_claim_position_idx');
        });

        Schema::create('campaign_delivery_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('send_run_id');
            $table->foreignUuid('snapshot_item_id');
            $table->foreignUuid('batch_id');
            $table->unsignedSmallInteger('attempt_number');
            $table->string('state', 20)->default('started');
            $table->string('idempotency_key', 160);
            $table->string('worker_id', 128);
            $table->char('lease_token_sha256', 64)->nullable();
            $table->string('transport_code', 48)->nullable();
            $table->char('outcome_reference_sha256', 64)->nullable();
            $table->char('response_sha256', 64)->nullable();
            $table->string('error_code', 96)->nullable();
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->unique(['send_run_id', 'snapshot_item_id', 'attempt_number'], 'campaign_delivery_attempt_number_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'campaign_delivery_attempt_idem_uq');
            $table->foreign(['workspace_id', 'send_run_id', 'snapshot_item_id', 'batch_id'], 'campaign_delivery_attempt_claim_fk')
                ->references(['workspace_id', 'send_run_id', 'snapshot_item_id', 'batch_id'])->on('campaign_send_recipient_claims')->restrictOnDelete();
            $table->index(['send_run_id', 'state', 'started_at'], 'campaign_delivery_attempt_state_idx');
        });
    }

    public function down(): void
    {
        foreach (['campaign_delivery_attempts', 'campaign_send_recipient_claims', 'campaign_send_batches', 'campaign_send_runs'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Campaign send execution history is in use and cannot be rolled back without losing operational evidence.');
            }
        }

        Schema::dropIfExists('campaign_delivery_attempts');
        Schema::dropIfExists('campaign_send_recipient_claims');
        Schema::dropIfExists('campaign_send_batches');
        Schema::dropIfExists('campaign_send_runs');

        Schema::table('campaign_recipient_snapshots', function (Blueprint $table): void {
            $table->dropUnique('campaign_snapshot_run_scope_uq');
        });
        Schema::table('campaign_recipient_snapshot_items', function (Blueprint $table): void {
            $table->dropUnique('campaign_snapshot_item_scope_uq');
        });
        Schema::table('campaign_revisions', function (Blueprint $table): void {
            $table->dropUnique('campaign_revision_campaign_id_uq');
        });
    }
};
