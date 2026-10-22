<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_servers', function (Blueprint $table): void {
            $table->boolean('is_locked')->default(false)->index();
        });

        Schema::create('managed_server_user', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('managed_server_id');
            $table->foreignId('user_id');
            $table->primary(['workspace_id', 'managed_server_id', 'user_id'], 'managed_server_user_primary');
            $table->foreign(['workspace_id', 'managed_server_id'], 'managed_server_user_server_fk')
                ->references(['workspace_id', 'id'])->on('managed_servers')->cascadeOnDelete();
            $table->foreign(['workspace_id', 'user_id'], 'managed_server_user_member_fk')
                ->references(['workspace_id', 'user_id'])->on('workspace_user')->cascadeOnDelete();
            $table->index(['user_id', 'workspace_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_server_user');

        Schema::table('managed_servers', function (Blueprint $table): void {
            $table->dropColumn('is_locked');
        });
    }
};
