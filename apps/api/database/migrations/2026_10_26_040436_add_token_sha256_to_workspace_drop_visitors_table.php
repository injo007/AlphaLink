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
        Schema::table('workspace_drop_visitors', function (Blueprint $table): void {
            $table->char('token_sha256', 64)->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('workspace_drop_visitors', function (Blueprint $table): void {
            $table->dropIndex(['token_sha256']);
            $table->dropColumn('token_sha256');
        });
    }
};
