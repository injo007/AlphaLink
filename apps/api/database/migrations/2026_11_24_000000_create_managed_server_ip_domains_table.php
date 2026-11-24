<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_server_ips', function (Blueprint $table): void {
            $table->string('ehlo_hostname', 253)->nullable();
        });

        Schema::create('managed_server_ip_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('managed_server_id');
            $table->foreignId('managed_server_ip_id');
            $table->foreignUuid('sending_domain_id');
            $table->string('hostname', 253);
            $table->string('tracking_host', 253)->nullable();
            $table->string('dkim_selector', 63)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('tracking_https_ready')->default(false);
            $table->timestampTz('dns_verified_at')->nullable();
            $table->timestampTz('authentication_verified_at')->nullable();
            $table->timestampsTz();

            $table->unique(['workspace_id', 'id']);
            $table->unique(['workspace_id', 'managed_server_ip_id', 'hostname'], 'server_ip_domain_hostname_unique');
            $table->foreign(['workspace_id', 'managed_server_id'], 'server_ip_domain_server_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_servers')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'managed_server_ip_id'], 'server_ip_domain_ip_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_server_ips')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'sending_domain_id'], 'server_ip_domain_domain_scope_fk')
                ->references(['workspace_id', 'id'])->on('sending_domains')->restrictOnDelete();
            $table->index(['workspace_id', 'managed_server_id', 'is_active'], 'server_ip_domain_lookup_idx');
        });

        DB::table('managed_server_ips')->orderBy('id')->chunkById(500, function ($ips): void {
            $now = now();
            $rows = [];
            foreach ($ips as $ip) {
                $hostname = strtolower(rtrim(trim((string) $ip->dns), '.'));
                DB::table('managed_server_ips')->where('id', $ip->id)->update(['ehlo_hostname' => $hostname]);
                $rows[] = [
                    'workspace_id' => $ip->workspace_id,
                    'managed_server_id' => $ip->managed_server_id,
                    'managed_server_ip_id' => $ip->id,
                    'sending_domain_id' => $ip->sending_domain_id,
                    'hostname' => $hostname,
                    'is_primary' => true,
                    'is_active' => $ip->is_active,
                    'tracking_https_ready' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('managed_server_ip_domains')->insert($rows);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_server_ip_domains');
        Schema::table('managed_server_ips', function (Blueprint $table): void {
            $table->dropColumn('ehlo_hostname');
        });
    }
};
