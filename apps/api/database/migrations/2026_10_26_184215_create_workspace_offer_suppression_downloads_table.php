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
        Schema::create('workspace_offer_suppression_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('workspace_offers')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->index();
            $table->unsignedBigInteger('downloaded_bytes')->default(0);
            $table->unsignedBigInteger('total_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('entry_count')->nullable();
            $table->string('format', 8)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'offer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_offer_suppression_downloads');
    }
};
