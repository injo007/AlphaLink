<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_campaign_negatives', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('path', 512)->unique();
            $table->string('original_filename', 255);
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->timestampsTz();
            $table->index(['workspace_id', 'created_by', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_campaign_negatives');
    }
};
