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
        Schema::create('workspace_drops', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sponsor_id')->nullable()->constrained('workspace_sponsors')->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('workspace_offers')->nullOnDelete();
            $table->foreignId('isp_id')->nullable()->constrained('isps')->nullOnDelete();
            $table->string('send_type')->nullable()->index();
            $table->json('servers')->nullable();
            $table->json('lists')->nullable();
            $table->json('filters')->nullable();
            $table->json('countries')->nullable();
            $table->string('send_status')->default('start');
            $table->unsignedInteger('send_progress')->default(0);
            $table->unsignedInteger('send_count')->nullable();
            $table->boolean('is_processed')->default(false)->index();
            $table->boolean('is_scheduled')->default(false);
            $table->dateTime('scheduled_at')->nullable();
            $table->boolean('is_restricted')->default(false);
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_drops');
    }
};
