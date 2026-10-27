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
        Schema::table('server_providers', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('url')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->softDeletesTz();
            $table->index(['workspace_id', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('server_providers', function (Blueprint $table): void {
            $table->dropIndex(['workspace_id', 'deleted_at']);
            $table->dropConstrainedForeignId('workspace_id');
            $table->dropColumn(['url', 'username', 'password', 'deleted_at']);
        });
    }
};
