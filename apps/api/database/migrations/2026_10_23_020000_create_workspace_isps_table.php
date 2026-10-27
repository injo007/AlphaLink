<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isps', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true)->index();
            $table->jsonb('allowed_users')->default(json_encode([]));
            $table->jsonb('allowed_sponsors')->default(json_encode([]));
            $table->jsonb('tracked_countries')->nullable();
            $table->jsonb('quality_emails')->nullable();
            $table->timestampsTz();
            $table->index(['workspace_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isps');
    }
};
