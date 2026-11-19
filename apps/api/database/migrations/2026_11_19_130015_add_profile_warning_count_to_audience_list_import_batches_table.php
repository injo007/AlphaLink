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
        Schema::table('audience_list_import_batches', function (Blueprint $table): void {
            $table->unsignedBigInteger('profile_warning_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('audience_list_import_batches')->where('profile_warning_count', '>', 0)->exists()) {
            throw new RuntimeException('Audience import profile warnings are recorded and cannot be removed without losing diagnostics.');
        }

        Schema::table('audience_list_import_batches', function (Blueprint $table): void {
            $table->dropColumn('profile_warning_count');
        });
    }
};
