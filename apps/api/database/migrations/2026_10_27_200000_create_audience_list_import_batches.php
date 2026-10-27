<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audience_list_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('audience_list_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('validating');
            $table->string('phase', 24)->default('validation');
            $table->string('source_format', 16);
            $table->string('membership_type', 24);
            $table->string('filter', 255)->nullable();
            $table->string('storage_path', 512);
            $table->char('file_sha256', 64)->nullable();
            $table->unsignedBigInteger('file_size_bytes');
            $table->boolean('checksum_verified')->default(false);
            $table->unsignedBigInteger('validation_offset')->default(0);
            $table->unsignedBigInteger('import_offset')->default(0);
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->unsignedBigInteger('valid_count')->default(0);
            $table->unsignedBigInteger('filtered_count')->default(0);
            $table->unsignedBigInteger('invalid_count')->default(0);
            $table->unsignedBigInteger('imported_count')->default(0);
            $table->unsignedBigInteger('duplicate_count')->default(0);
            $table->string('failure_message', 255)->nullable();
            $table->string('request_id', 128)->nullable();
            $table->timestampTz('next_dispatch_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['workspace_id', 'audience_list_id'], 'aud_import_workspace_list_fk')
                ->references(['workspace_id', 'id'])->on('audience_lists')->restrictOnDelete();
            $table->index(['workspace_id', 'audience_list_id', 'created_at'], 'aud_import_list_created_idx');
            $table->index(['status', 'next_dispatch_at'], 'aud_import_resume_idx');
        });

        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->index(['workspace_id', 'audience_list_id', 'email_type'], 'clm_workspace_list_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->dropIndex('clm_workspace_list_type_idx');
        });

        Schema::dropIfExists('audience_list_import_batches');
    }
};
