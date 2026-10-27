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
        Schema::table('campaign_test_mail_attempts', function (Blueprint $table) {
            $table->foreignUuid('managed_server_id')->nullable()->constrained('managed_servers')->restrictOnDelete();
            $table->char('request_fingerprint', 64)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign_test_mail_attempts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('managed_server_id');
            $table->dropColumn('request_fingerprint');
        });
    }
};
