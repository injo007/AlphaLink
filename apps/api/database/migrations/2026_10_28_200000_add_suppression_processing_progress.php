<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_offer_suppression_downloads', function (Blueprint $table): void {
            $table->unsignedBigInteger('processed_entries')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('workspace_offer_suppression_downloads', function (Blueprint $table): void {
            $table->dropColumn('processed_entries');
        });
    }
};
