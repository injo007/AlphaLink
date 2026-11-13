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
        Schema::table('workspace_spf_scans', function (Blueprint $table) {
            $table->json('worker_offsets')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_spf_scans', function (Blueprint $table) {
            $table->dropColumn('worker_offsets');
        });
    }
};
