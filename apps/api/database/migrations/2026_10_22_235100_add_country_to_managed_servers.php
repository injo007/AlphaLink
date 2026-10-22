<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_servers', function (Blueprint $table): void {
            $table->string('country', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('managed_servers', function (Blueprint $table): void {
            $table->dropColumn('country');
        });
    }
};
