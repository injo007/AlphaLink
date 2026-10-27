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
        Schema::create('pmta_config_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('managed_server_id');
            $table->foreignId('actor_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('file', 120);
            $table->string('action', 40);
            $table->longText('contents')->nullable();
            $table->text('result')->nullable();
            $table->boolean('succeeded')->default(false);
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'managed_server_id'], 'pmta_config_history_server_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_servers')->cascadeOnDelete();
            $table->index(['workspace_id', 'managed_server_id', 'created_at'], 'pmta_config_history_lookup_idx');
        });

        Schema::create('pmta_virtual_mtas', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('managed_server_id');
            $table->foreignId('managed_server_ip_id');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('ip', 45);
            $table->string('domain', 253);
            $table->string('name', 120);
            $table->unsignedInteger('duplicate_index')->default(1);
            $table->boolean('route')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'managed_server_id'], 'pmta_vmta_server_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_servers')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'managed_server_ip_id'], 'pmta_vmta_ip_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_server_ips')->cascadeOnDelete();
            $table->index(['workspace_id', 'managed_server_id', 'is_active'], 'pmta_vmta_lookup_idx');
        });

        Schema::create('pmta_smtp_configs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('managed_server_id');
            $table->foreignId('managed_server_ip_id');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('ip', 45);
            $table->string('dns', 253)->nullable();
            $table->string('username', 255);
            $table->text('password');
            $table->string('host', 253);
            $table->unsignedSmallInteger('port');
            $table->boolean('google_script')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'managed_server_id'], 'pmta_smtp_server_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_servers')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'managed_server_ip_id'], 'pmta_smtp_ip_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_server_ips')->cascadeOnDelete();
            $table->index(['workspace_id', 'managed_server_id', 'is_active'], 'pmta_smtp_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pmta_smtp_configs');
        Schema::dropIfExists('pmta_virtual_mtas');
        Schema::dropIfExists('pmta_config_histories');
    }
};
