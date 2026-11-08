<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_sponsor_revenues', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('sponsor_id')->constrained('workspace_sponsors')->cascadeOnDelete();
            $table->string('provider_type', 32);
            $table->string('provider_conversion_id', 255);
            $table->decimal('amount', 20, 4);
            $table->char('currency_code', 3)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
            $table->unique(['workspace_id', 'sponsor_id', 'provider_conversion_id'], 'sponsor_revenue_provider_identity_uq');
            $table->index(['workspace_id', 'occurred_at', 'sponsor_id'], 'sponsor_revenue_workspace_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_sponsor_revenues');
    }
};
