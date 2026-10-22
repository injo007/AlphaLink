<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_credentials', function (Blueprint $table) {
            $table->renameColumn('encrypted_password', 'encrypted_secret');
        });
        Schema::table('server_credentials', function (Blueprint $table) {
            $table->string('credential_type', 24)->default('password');
        });
    }

    public function down(): void
    {
        Schema::table('server_credentials', function (Blueprint $table) {
            $table->dropColumn('credential_type');
        });
        Schema::table('server_credentials', function (Blueprint $table) {
            $table->renameColumn('encrypted_secret', 'encrypted_password');
        });
    }
};
