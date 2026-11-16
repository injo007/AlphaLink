<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_number')->nullable()->after('created_by');
            $table->index(['workspace_id', 'campaign_number']);
        });

        // Backfill sequential campaign numbers per workspace based on creation order
        $workspaceIds = DB::table('campaigns')
            ->select('workspace_id')
            ->distinct()
            ->pluck('workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            $campaigns = DB::table('campaigns')
                ->where('workspace_id', $workspaceId)
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->select('id')
                ->get();

            $num = 1;
            foreach ($campaigns as $camp) {
                DB::table('campaigns')
                    ->where('id', $camp->id)
                    ->update(['campaign_number' => $num++]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'campaign_number']);
            $table->dropColumn('campaign_number');
        });
    }
};
