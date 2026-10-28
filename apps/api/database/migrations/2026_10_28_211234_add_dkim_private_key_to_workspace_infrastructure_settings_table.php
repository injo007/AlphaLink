<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_infrastructure_settings', function (Blueprint $table): void {
            $table->longText('dkim_private_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_infrastructure_settings', function (Blueprint $table): void {
            $table->dropColumn('dkim_private_key');
        });
    }
};
