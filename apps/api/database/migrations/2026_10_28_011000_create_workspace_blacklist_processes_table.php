<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_blacklist_processes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('queued');
            $table->string('email_type', 24);
            $table->string('input_mode', 16)->default('email');
            $table->string('original_filename', 255);
            $table->string('storage_path', 512);
            $table->unsignedBigInteger('file_size_bytes')->default(0);
            $table->unsignedBigInteger('byte_offset')->default(0);
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->unsignedBigInteger('matched_count')->default(0);
            $table->unsignedBigInteger('updated_count')->default(0);
            $table->unsignedBigInteger('invalid_count')->default(0);
            $table->text('failure_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('history_recalculated_at')->nullable();
            $table->timestampsTz();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'status', 'created_at']);
        });

        Schema::create('workspace_blacklist_process_lists', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('process_id')->constrained('workspace_blacklist_processes')->cascadeOnDelete();
            $table->foreignUuid('audience_list_id');
            $table->primary(['process_id', 'audience_list_id'], 'wbc_process_list_pk');
            $table->foreign(['workspace_id', 'audience_list_id'], 'wbc_workspace_list_fk')
                ->references(['workspace_id', 'id'])->on('audience_lists')->cascadeOnDelete();
            $table->index(['workspace_id', 'audience_list_id'], 'wbc_workspace_list_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_blacklist_process_lists');
        Schema::dropIfExists('workspace_blacklist_processes');
    }
};
