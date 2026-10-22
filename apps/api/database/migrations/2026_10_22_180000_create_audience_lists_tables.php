<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('slug', 160);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('active');
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'slug'], 'al_workspace_slug_uq');
            $table->unique(['workspace_id', 'idempotency_key'], 'al_workspace_idem_uq');
            $table->unique(['workspace_id', 'id'], 'al_workspace_id_uq');
            $table->index(['workspace_id', 'status', 'created_at']);
        });

        Schema::create('contact_list_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('audience_list_id');
            $table->foreignUuid('contact_id');
            $table->string('status', 16)->default('active');
            $table->timestampTz('joined_at');
            $table->timestampTz('left_at')->nullable();
            $table->foreignUuid('last_event_id')->nullable();
            $table->timestampsTz();
            $table->unique(['workspace_id', 'audience_list_id', 'contact_id'], 'clm_workspace_list_contact_uq');
            $table->foreign(['workspace_id', 'audience_list_id'], 'clm_workspace_list_fk')
                ->references(['workspace_id', 'id'])->on('audience_lists')->restrictOnDelete();
            $table->foreign(['workspace_id', 'contact_id'], 'clm_workspace_contact_fk')
                ->references(['workspace_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->index(['workspace_id', 'audience_list_id', 'status', 'joined_at']);
        });

        Schema::create('contact_list_membership_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('audience_list_id');
            $table->foreignUuid('contact_id');
            $table->string('action', 16);
            $table->string('source', 32);
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('occurred_at');
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['workspace_id', 'idempotency_key'], 'clme_workspace_idem_uq');
            $table->foreign(['workspace_id', 'audience_list_id'], 'clme_workspace_list_fk')
                ->references(['workspace_id', 'id'])->on('audience_lists')->restrictOnDelete();
            $table->foreign(['workspace_id', 'contact_id'], 'clme_workspace_contact_fk')
                ->references(['workspace_id', 'id'])->on('contacts')->restrictOnDelete();
            $table->index(['workspace_id', 'audience_list_id', 'contact_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_list_membership_events');
        Schema::dropIfExists('contact_list_memberships');
        Schema::dropIfExists('audience_lists');
    }
};
