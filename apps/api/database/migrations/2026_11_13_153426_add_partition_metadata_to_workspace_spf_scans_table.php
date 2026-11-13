<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_spf_scans', function (Blueprint $table): void {
            $table->unsignedTinyInteger('partition_count')->default(1);
            $table->json('partition_paths')->nullable();
            $table->json('partition_offsets')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->unsignedBigInteger('preparation_bytes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('workspace_spf_scans', function (Blueprint $table): void {
            $table->dropColumn([
                'partition_count',
                'partition_paths',
                'partition_offsets',
                'file_size_bytes',
                'preparation_bytes',
            ]);
        });
    }
};
