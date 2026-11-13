<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_spf_scan_workers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scan_id')->constrained('workspace_spf_scans')->cascadeOnDelete();
            $table->unsignedSmallInteger('worker_index');
            $table->string('status', 24)->default('starting');
            $table->string('stage', 32)->nullable();
            $table->string('current_domain', 253)->nullable();
            $table->unsignedInteger('domains_processed')->default(0);
            $table->unsignedInteger('spf_records_found')->default(0);
            $table->unsignedInteger('dns_errors')->default(0);
            $table->unsignedInteger('last_lookup_duration_ms')->nullable();
            $table->unsignedInteger('last_batch_duration_ms')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['scan_id', 'worker_index']);
            $table->index(['scan_id', 'status']);
        });

        Schema::create('workspace_spf_scan_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scan_id')->constrained('workspace_spf_scans')->cascadeOnDelete();
            $table->string('level', 16)->default('info');
            $table->string('event_type', 40);
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['scan_id', 'id']);
            $table->index(['scan_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_spf_scan_events');
        Schema::dropIfExists('workspace_spf_scan_workers');
    }
};
