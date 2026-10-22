<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('managed_servers')
            ->where('state', 'pending_host_verification')
            ->update(['state' => 'pending_preflight']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('managed_servers')
            ->where('state', 'pending_preflight')
            ->update(['state' => 'pending_host_verification']);
    }
};
