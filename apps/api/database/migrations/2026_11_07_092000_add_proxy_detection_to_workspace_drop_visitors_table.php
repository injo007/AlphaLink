<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_drop_visitors', function (Blueprint $table): void {
            $table->boolean('is_proxy')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_drop_visitors', function (Blueprint $table): void {
            $table->dropColumn('is_proxy');
        });
    }
};
