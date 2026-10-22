<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 120)->unique();
            $table->string('status', 24)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 128)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['created_by', 'idempotency_key']);
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->timestampTz('joined_at')->useCurrent();
            $table->timestampsTz();
            $table->primary(['workspace_id', 'user_id']);
            $table->index(['user_id', 'role']);
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->text('email');
            $table->char('email_lookup_hash', 64);
            $table->unsignedSmallInteger('lookup_key_version')->default(1);
            $table->string('status', 24)->default('active');
            $table->string('idempotency_key', 128);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'email_lookup_hash']);
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->unique(['workspace_id', 'id']);
            $table->index(['workspace_id', 'status', 'created_at']);
        });

        Schema::create('consent_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('contact_id');
            $table->string('action', 24);
            $table->string('purpose', 64);
            $table->string('channel', 32);
            $table->string('basis', 64)->nullable();
            $table->string('source', 64);
            $table->string('evidence_reference', 512)->nullable();
            $table->string('policy_version', 64)->nullable();
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->timestampTz('expires_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->foreign(['workspace_id', 'contact_id'], 'consent_workspace_contact_fk')
                ->references(['workspace_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->index(['workspace_id', 'contact_id', 'occurred_at']);
        });

        Schema::create('suppression_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('contact_id');
            $table->string('event_type', 16)->default('added');
            $table->string('reason', 32);
            $table->string('scope_type', 24)->default('workspace');
            $table->string('scope_key', 128)->nullable();
            $table->string('source', 64);
            $table->string('source_reference', 512)->nullable();
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('effective_at');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->foreign(['workspace_id', 'contact_id'], 'suppression_workspace_contact_fk')
                ->references(['workspace_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->index(['workspace_id', 'contact_id', 'effective_at']);
            $table->index(['workspace_id', 'reason', 'effective_at']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('kind', 32);
            $table->string('status', 24)->default('draft');
            $table->string('idempotency_key', 128);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->index(['workspace_id', 'status', 'created_at']);
        });

        Schema::create('campaign_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->text('payload');
            $table->char('content_sha256', 64);
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['campaign_id', 'revision_number']);
            $table->unique(['campaign_id', 'idempotency_key']);
            $table->index(['campaign_id', 'content_sha256']);
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 96);
            $table->string('subject_type', 96)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('request_id', 128)->nullable();
            $table->jsonb('properties')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['workspace_id', 'occurred_at']);
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 128);
            $table->jsonb('payload');
            $table->string('idempotency_key', 128)->unique();
            $table->timestampTz('available_at')->useCurrent();
            $table->timestampTz('published_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['published_at', 'available_at']);
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('campaign_revisions');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('suppression_events');
        Schema::dropIfExists('consent_records');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
    }
};
