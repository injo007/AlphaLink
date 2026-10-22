<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_servers', function (Blueprint $table) {
            $table->string('observed_host_fingerprint', 128)->nullable();
            $table->timestampTz('last_probe_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('managed_servers', function (Blueprint $table) {
            $table->dropColumn(['observed_host_fingerprint', 'last_probe_at']);
        });
    }
};
