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
        Schema::table('audience_lists', function (Blueprint $table): void {
            $table->uuid('segment_source_list_id')->nullable();
            $table->string('segment_action', 16)->nullable();
            $table->char('segment_country_code', 2)->nullable();
            $table->index(
                ['workspace_id', 'segment_source_list_id', 'segment_action', 'segment_country_code'],
                'al_segment_scope_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audience_lists', function (Blueprint $table): void {
            $table->dropIndex('al_segment_scope_idx');
            $table->dropColumn(['segment_source_list_id', 'segment_action', 'segment_country_code']);
        });
    }
};
