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
        Schema::create('sending_domain_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('sending_domain_id');
            $table->string('a_record')->nullable();
            $table->text('spf')->nullable();
            $table->text('dkim')->nullable();
            $table->text('dmarc')->nullable();
            $table->boolean('is_listed_spamhaus')->nullable();
            $table->boolean('is_listed_hostkarma')->nullable();
            $table->timestampsTz();
            $table->foreign(['workspace_id', 'sending_domain_id'], 'sending_domain_history_scope_fk')
                ->references(['workspace_id', 'id'])->on('sending_domains')->cascadeOnDelete();
            $table->index(['workspace_id', 'sending_domain_id', 'created_at'], 'sending_domain_history_lookup_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sending_domain_histories');
    }
};
