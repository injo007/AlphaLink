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
        Schema::create('workspace_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('sponsor_id')->constrained('workspace_sponsors')->restrictOnDelete();
            $table->string('offer_id', 255)->nullable();
            $table->string('name', 255);
            $table->string('type', 32)->default('cpa');
            $table->string('vertical', 255);
            $table->jsonb('geo_target');
            $table->decimal('payout', 12, 4)->default(0);
            $table->text('redirect');
            $table->text('unsubscribe')->nullable();
            $table->text('note')->nullable();
            $table->jsonb('allowed_days');
            $table->string('status', 32)->default('live');
            $table->string('suppression_path')->nullable();
            $table->date('suppression_updated_at')->nullable();
            $table->jsonb('allowed_users')->default(json_encode([]));
            $table->boolean('is_active')->default(false)->index();
            $table->timestamps();
            $table->softDeletesTz();
            $table->unique(['workspace_id', 'sponsor_id', 'offer_id'], 'workspace_offer_identity_uq');
            $table->index(['workspace_id', 'sponsor_id', 'status', 'is_active'], 'workspace_offers_catalog_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_offers');
    }
};
