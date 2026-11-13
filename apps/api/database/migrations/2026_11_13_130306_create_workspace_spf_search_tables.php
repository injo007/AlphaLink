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
        Schema::create('workspace_spf_search_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('ips');
            $table->string('status', 24)->default('pending');
            $table->string('stage', 24)->default('queued');
            $table->unsignedInteger('total_roots')->default(0);
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('total_matches')->default(0);
            $table->boolean('dns_checks_limited')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('failure')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status', 'id']);
        });

        Schema::create('workspace_spf_search_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_run_id')->constrained('workspace_spf_search_runs')->cascadeOnDelete();
            $table->foreignId('root_id')->constrained('workspace_spf_roots')->cascadeOnDelete();
            $table->string('matched_ip', 45);
            $table->string('root', 253);
            $table->string('include', 253);
            $table->text('network');
            $table->timestamps();
            $table->unique(['search_run_id', 'root_id', 'matched_ip', 'include'], 'spf_search_result_dedupe');
            $table->index(['search_run_id', 'root']);
            $table->index(['search_run_id', 'matched_ip']);
            $table->index(['search_run_id', 'include']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_spf_search_results');
        Schema::dropIfExists('workspace_spf_search_runs');
    }
};
