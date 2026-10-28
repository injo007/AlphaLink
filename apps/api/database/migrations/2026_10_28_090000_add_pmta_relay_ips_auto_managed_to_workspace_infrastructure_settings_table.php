<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_infrastructure_settings', function (Blueprint $table): void {
            $table->boolean('pmta_relay_ips_auto_managed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('workspace_infrastructure_settings', function (Blueprint $table): void {
            $table->dropColumn('pmta_relay_ips_auto_managed');
        });
    }
};
