<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sending_domains', function (Blueprint $table) {
            $table->dropUnique('sending_domains_workspace_id_name_unique');
            $table->dropUnique('sending_domains_provider_id_scope_unique');
            $table->softDeletesTz();
        });

        DB::statement('CREATE UNIQUE INDEX sending_domains_workspace_name_active_unique ON sending_domains (workspace_id, name) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX sending_domains_provider_id_active_unique ON sending_domains (workspace_id, provider_domain_id) WHERE deleted_at IS NULL AND provider_domain_id IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX sending_domains_provider_id_active_unique');
        DB::statement('DROP INDEX sending_domains_workspace_name_active_unique');

        Schema::table('sending_domains', function (Blueprint $table) {
            $table->dropSoftDeletesTz();
            $table->unique(['workspace_id', 'name']);
            $table->unique(['workspace_id', 'provider_domain_id'], 'sending_domains_provider_id_scope_unique');
        });
    }
};
