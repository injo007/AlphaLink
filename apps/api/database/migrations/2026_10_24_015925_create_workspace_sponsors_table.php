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
        Schema::create('workspace_sponsors', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->string('name', 255);
            $table->string('affiliate_id', 255)->nullable();
            $table->string('url', 255);
            $table->text('username');
            $table->text('password');
            $table->longText('api_key');
            $table->string('type', 32);
            $table->jsonb('allowed_users')->default(json_encode([]));
            $table->boolean('is_active')->default(true)->index();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['workspace_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_sponsors');
    }
};
