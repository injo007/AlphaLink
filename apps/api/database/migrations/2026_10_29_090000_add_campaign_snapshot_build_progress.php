<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->string('snapshot_build_stage', 32)->nullable();
            $table->unsignedTinyInteger('snapshot_build_percent')->default(0);
            $table->unsignedBigInteger('snapshot_build_processed')->default(0);
            $table->unsignedBigInteger('snapshot_build_total')->nullable();
            $table->text('snapshot_build_error')->nullable();
            $table->timestampTz('snapshot_build_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'snapshot_build_stage',
                'snapshot_build_percent',
                'snapshot_build_processed',
                'snapshot_build_total',
                'snapshot_build_error',
                'snapshot_build_updated_at',
            ]);
        });
    }
};
