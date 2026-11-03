<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('audience_list_import_batches')
            ->where('source_format', 'dms_csv')
            ->update(['source_format' => 'profile_csv']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('audience_list_import_batches')
            ->where('source_format', 'profile_csv')
            ->update(['source_format' => 'dms_csv']);
    }
};
