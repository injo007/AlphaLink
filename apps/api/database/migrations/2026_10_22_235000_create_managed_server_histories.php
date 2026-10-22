<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_server_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('managed_server_id');
            $table->boolean('is_ssh_down')->default(false);
            $table->boolean('is_apache_down')->default(false);
            $table->boolean('is_pmta_down')->default(false);
            $table->timestamps();
            $table->foreign(['workspace_id', 'managed_server_id'], 'server_history_scope_fk')
                ->references(['workspace_id', 'id'])->on('managed_servers')->cascadeOnDelete();
            $table->index(['workspace_id', 'managed_server_id', 'created_at'], 'server_history_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_server_histories');
    }
};
