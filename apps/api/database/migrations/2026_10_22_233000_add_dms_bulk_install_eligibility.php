<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique();
        });

        Schema::table('managed_servers', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_installed')->default(false)->index();
        });

        Schema::create('domain_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
        });

        Schema::table('sending_domains', function (Blueprint $table) {
            $table->foreignId('domains_provider_id')->nullable()->constrained('domain_providers')->restrictOnDelete();
            $table->string('status', 50)->nullable()->index();
            $table->boolean('is_our')->nullable()->index();
            $table->boolean('is_active')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('sending_domains', function (Blueprint $table) {
            $table->dropForeign(['domains_provider_id']);
            $table->dropColumn(['domains_provider_id', 'status', 'is_our', 'is_active']);
        });

        Schema::dropIfExists('domain_providers');

        Schema::table('managed_servers', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'is_installed']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
