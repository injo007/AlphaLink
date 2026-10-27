<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_servers', function (Blueprint $table): void {
            $table->json('install_parameters')->nullable();
        });

        Schema::create('managed_server_ips', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('managed_server_id');
            $table->foreignUuid('sending_domain_id');
            $table->ipAddress('ip');
            $table->string('type', 50)->index();
            $table->string('dns', 253);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['workspace_id', 'id']);
            $table->foreign(['workspace_id', 'managed_server_id'], 'managed_server_ip_server_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_servers')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'sending_domain_id'], 'managed_server_ip_domain_scope_fk')
                ->references(['workspace_id', 'id'])->on('sending_domains')->restrictOnDelete();
            $table->index(['workspace_id', 'managed_server_id', 'is_active'], 'managed_server_ip_lookup_idx');
        });

        Schema::create('managed_server_mtas', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('managed_server_id');
            $table->foreignId('managed_server_ip_id');
            $table->ipAddress('ip');
            $table->string('dns', 253);
            $table->string('ip_type', 50)->index();
            $table->string('mta_type', 50)->index();
            $table->string('username')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'managed_server_id'], 'managed_server_mta_server_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_servers')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'managed_server_ip_id'], 'managed_server_mta_ip_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_server_ips')->cascadeOnDelete();
            $table->index(['workspace_id', 'managed_server_id'], 'managed_server_mta_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_server_mtas');
        Schema::dropIfExists('managed_server_ips');

        Schema::table('managed_servers', function (Blueprint $table): void {
            $table->dropColumn('install_parameters');
        });
    }
};
