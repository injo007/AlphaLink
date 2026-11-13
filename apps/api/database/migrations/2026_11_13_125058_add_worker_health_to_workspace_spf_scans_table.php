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
            $table->string('stage', 24)->default('queued')->after('status');
            $table->string('current_domain', 253)->nullable()->after('stage');
            $table->timestamp('heartbeat_at')->nullable()->after('started_at');
            $table->timestamp('progress_at')->nullable()->after('heartbeat_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_spf_scans', function (Blueprint $table) {
            $table->dropColumn(['stage', 'current_domain', 'heartbeat_at', 'progress_at']);
        });
    }
};
