<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasDuplicateAssignments = DB::table('contact_list_memberships')
            ->select(['workspace_id', 'contact_id'])
            ->groupBy(['workspace_id', 'contact_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateAssignments) {
            throw new RuntimeException(
                'Cannot enforce one audience list per contact: resolve existing duplicate workspace assignments first.'
            );
        }

        Schema::table('contact_list_memberships', function (Blueprint $table) {
            $table->unique(['workspace_id', 'contact_id'], 'clm_workspace_contact_uq');
        });
    }

    public function down(): void
    {
        Schema::table('contact_list_memberships', function (Blueprint $table) {
            $table->dropUnique('clm_workspace_contact_uq');
        });
    }
};
