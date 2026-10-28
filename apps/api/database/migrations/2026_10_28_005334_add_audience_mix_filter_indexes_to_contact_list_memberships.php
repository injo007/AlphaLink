<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('contact_list_memberships', 'profile_sex_index')) {
            Schema::table('contact_list_memberships', function (Blueprint $table): void {
                $table->string('profile_sex_index', 32)->nullable();
                $table->string('email_domain_index', 32)->nullable();
                $table->unsignedSmallInteger('attribute_index_version')->nullable();
            });
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS clm_mix_sex_filter_idx ON contact_list_memberships (workspace_id, audience_list_id, email_type, status, attribute_index_version, profile_sex_index, id)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS clm_mix_domain_filter_idx ON contact_list_memberships (workspace_id, audience_list_id, email_type, status, attribute_index_version, email_domain_index, id)');

            return;
        }

        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->index(
                ['workspace_id', 'audience_list_id', 'email_type', 'status', 'attribute_index_version', 'profile_sex_index', 'id'],
                'clm_mix_sex_filter_idx',
            );
            $table->index(
                ['workspace_id', 'audience_list_id', 'email_type', 'status', 'attribute_index_version', 'email_domain_index', 'id'],
                'clm_mix_domain_filter_idx',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS clm_mix_domain_filter_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS clm_mix_sex_filter_idx');
        } else {
            Schema::table('contact_list_memberships', function (Blueprint $table): void {
                $table->dropIndex('clm_mix_domain_filter_idx');
                $table->dropIndex('clm_mix_sex_filter_idx');
            });
        }

        Schema::table('contact_list_memberships', function (Blueprint $table): void {
            $table->dropColumn(['attribute_index_version', 'email_domain_index', 'profile_sex_index']);
        });
    }
};
