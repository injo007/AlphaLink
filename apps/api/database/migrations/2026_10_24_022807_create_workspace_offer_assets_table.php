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
        Schema::create('workspace_offer_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('workspace_offers')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->string('type', 32)->index();
            $table->text('value');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['workspace_id', 'offer_id', 'type', 'is_active'], 'workspace_offer_assets_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_offer_assets');
    }
};
