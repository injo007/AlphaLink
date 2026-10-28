<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('workspace_daily_analytics', function (Blueprint $table): void {
            $table->string('attribution_scope', 16)->default('workspace');
            $table->index(
                ['workspace_id', 'source_type', 'attribution_scope', 'at'],
                'workspace_daily_analytics_scope_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('workspace_daily_analytics', 'attribution_scope')) {
            return;
        }

        DB::table('workspace_daily_analytics')->where('attribution_scope', 'list')->delete();
        Schema::table('workspace_daily_analytics', function (Blueprint $table): void {
            $table->dropIndex('workspace_daily_analytics_scope_idx');
            $table->dropColumn('attribution_scope');
        });
    }
};
