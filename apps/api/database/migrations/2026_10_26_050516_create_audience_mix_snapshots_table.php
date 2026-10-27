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
        Schema::create('audience_mix_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('isp_id')->constrained('isps')->restrictOnDelete();
            $table->string('name', 100);
            $table->jsonb('criteria');
            $table->unsignedBigInteger('recipient_count')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestampTz('expired_at')->nullable();
            $table->timestampsTz();
            $table->index(['workspace_id', 'created_by', 'created_at'], 'aud_mix_workspace_owner_created_idx');
            $table->index(['workspace_id', 'isp_id', 'status', 'created_at'], 'aud_mix_workspace_isp_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audience_mix_snapshots');
    }
};
