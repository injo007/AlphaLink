<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_servers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('provider', 120);
            $table->ipAddress('ip_address');
            $table->unsignedSmallInteger('ssh_port')->default(22);
            $table->string('ssh_username', 32)->default('root');
            $table->string('expected_host_fingerprint', 128)->nullable();
            $table->string('state', 40)->default('pending_preflight');
            $table->jsonb('host_facts')->nullable();
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'idempotency_key']);
            $table->unique(['workspace_id', 'ip_address']);
        });

        Schema::create('server_credentials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('managed_server_id')->unique();
            $table->text('encrypted_password');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('rotated_at')->useCurrent();
            $table->foreign(['workspace_id', 'managed_server_id'], 'server_credential_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_servers')->restrictOnDelete();
        });

        Schema::create('sending_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->string('name', 253);
            $table->string('state', 24)->default('pending');
            $table->string('verification_token', 80);
            $table->timestampTz('verified_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('installation_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('managed_server_id');
            $table->foreignUuid('sending_domain_id');
            $table->string('pmta_version', 12);
            $table->jsonb('options');
            $table->jsonb('preview');
            $table->char('preview_sha256', 64);
            $table->string('state', 24)->default('blocked');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['workspace_id', 'managed_server_id'], 'installation_server_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_servers')->restrictOnDelete();
            $table->foreign(['workspace_id', 'sending_domain_id'], 'installation_domain_scope_fk')
                ->references(['workspace_id', 'id'])->on('sending_domains')->restrictOnDelete();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_plans');
        Schema::dropIfExists('sending_domains');
        Schema::dropIfExists('server_credentials');
        Schema::dropIfExists('managed_servers');
    }
};
