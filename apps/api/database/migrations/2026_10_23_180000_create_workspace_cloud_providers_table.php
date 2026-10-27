<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_cloud_providers', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('name');
            $table->longText('tokens');
            $table->json('allowed_users')->nullable();
            $table->timestamps();
            $table->softDeletesTz();
            $table->index(['workspace_id', 'type', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_cloud_providers');
    }
};
