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
        Schema::create('workspace_spf_roots', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('domain', 253);
            $table->string('status', 24);
            $table->text('record')->nullable();
            $table->json('cidrs')->nullable();
            $table->json('hosts')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'domain']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('workspace_spf_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_path');
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('total_lines')->default(0);
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('batch_size')->default(200);
            $table->boolean('skip_existing')->default(true);
            $table->unsignedTinyInteger('workers')->default(1);
            $table->unsignedBigInteger('pid')->nullable();
            $table->json('worker_pids')->nullable();
            $table->unsignedInteger('total_found')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_spf_scans');
        Schema::dropIfExists('workspace_spf_roots');
    }
};
