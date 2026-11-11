<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_communications', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 20);
            $table->string('audience', 20);
            $table->string('title', 140);
            $table->text('body');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['workspace_id', 'archived_at', 'created_at']);
            $table->index(['workspace_id', 'kind', 'created_at']);
        });

        Schema::create('workspace_communication_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('communication_id')->constrained('workspace_communications')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['communication_id', 'user_id']);
            $table->index(['user_id', 'read_at', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_communication_recipients');
        Schema::dropIfExists('workspace_communications');
    }
};
