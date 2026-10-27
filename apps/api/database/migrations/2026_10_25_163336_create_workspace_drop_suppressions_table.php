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
        Schema::create('workspace_drop_suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sponsor_id')->constrained('workspace_sponsors')->cascadeOnDelete();
            $table->foreignId('offer_id')->constrained('workspace_offers')->cascadeOnDelete();
            $table->json('isps');
            $table->json('lists');
            $table->string('status', 32)->default('start')->index();
            $table->unsignedBigInteger('found')->default(0);
            $table->string('elapsed', 16)->default('NA');
            $table->text('failure_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['workspace_id', 'created_at']);
            $table->index(['workspace_id', 'offer_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_drop_suppressions');
    }
};
