<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('isps', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'id'], 'isps_workspace_id_pair_uq');
        });

        Schema::table('audience_lists', function (Blueprint $table): void {
            $table->unsignedBigInteger('isp_id')->nullable();
            $table->string('country', 2)->nullable();
            $table->jsonb('allowed_users')->default(json_encode([]));
            $table->jsonb('allowed_sponsors')->default(json_encode([]));
            $table->index(['workspace_id', 'isp_id'], 'audience_lists_workspace_isp_idx');
            $table->foreign(['workspace_id', 'isp_id'], 'audience_lists_workspace_isp_fk')
                ->references(['workspace_id', 'id'])
                ->on('isps')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audience_lists', function (Blueprint $table): void {
            $table->dropForeign('audience_lists_workspace_isp_fk');
            $table->dropIndex('audience_lists_workspace_isp_idx');
            $table->dropColumn(['isp_id', 'country', 'allowed_users', 'allowed_sponsors']);
        });

        Schema::table('isps', function (Blueprint $table): void {
            $table->dropUnique('isps_workspace_id_pair_uq');
        });
    }
};
