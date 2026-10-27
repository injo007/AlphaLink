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
        Schema::create('workspace_offer_list_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('workspace_offers')->cascadeOnDelete();
            $table->foreignUuid('audience_list_id')->constrained('audience_lists')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignId('suppression_run_id')->constrained('workspace_drop_suppressions')->cascadeOnDelete();
            $table->char('suppression_sha256', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['workspace_id', 'offer_id', 'audience_list_id', 'contact_id'], 'offer_list_suppression_identity_unique');
            $table->index(['workspace_id', 'offer_id', 'audience_list_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_offer_list_suppressions');
    }
};
