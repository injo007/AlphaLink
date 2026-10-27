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
        Schema::table('workspace_infrastructure_settings', function (Blueprint $table): void {
            $table->longText('cloud_servers_password')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_infrastructure_settings', function (Blueprint $table): void {
            $table->dropColumn('cloud_servers_password');
        });
    }
};
