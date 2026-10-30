<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_google_projects', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('workspace_google_projects', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'name']);
            $table->unique(['workspace_id', 'name']);
        });
    }
};
