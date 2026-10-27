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
        Schema::create('managed_server_ip_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id');
            $table->foreignId('managed_server_ip_id');
            $table->string('ptr')->default('NA');
            $table->boolean('ip_listed_spamhaus')->default(false);
            $table->boolean('ip_listed_spamcop')->default(false);
            $table->boolean('ip_listed_barracuda')->default(false);
            $table->string('sender_score')->default('NA');
            $table->boolean('domain_listed_hostkarma')->default(false);
            $table->boolean('domain_listed_spamhaus_dbl')->default(false);
            $table->boolean('domain_listed_surbl')->default(false);
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'managed_server_ip_id'], 'managed_server_ip_history_ip_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_server_ips')->cascadeOnDelete();
            $table->index(['workspace_id', 'managed_server_ip_id', 'created_at'], 'managed_server_ip_history_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('managed_server_ip_histories');
    }
};
