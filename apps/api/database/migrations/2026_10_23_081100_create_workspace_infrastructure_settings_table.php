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
        Schema::create('workspace_infrastructure_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('pmta_port')->nullable();
            $table->unsignedSmallInteger('pmta_admin_port')->nullable();
            $table->unsignedSmallInteger('proxy_port')->nullable();
            $table->boolean('apply_firewall')->nullable();
            $table->boolean('allow_all_monitor')->nullable();
            $table->text('dev_ips')->nullable();
            $table->text('production_ips')->nullable();
            $table->text('rdp_ips')->nullable();
            $table->text('dkim_key')->nullable();
            $table->text('webmail_username')->nullable();
            $table->text('webmail_password')->nullable();
            $table->text('proxy_username')->nullable();
            $table->text('proxy_password')->nullable();
            $table->text('mini_app_key')->nullable();
            $table->longText('pmta_domain_configs')->nullable();
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_infrastructure_settings');
    }
};
