<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_manual_test_attempts', function (Blueprint $table): void {
            $table->longText('test_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_manual_test_attempts', function (Blueprint $table): void {
            $table->dropColumn('test_snapshot');
        });
    }
};
