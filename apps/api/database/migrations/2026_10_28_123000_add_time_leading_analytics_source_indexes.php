<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, array{columns: list<string>, name: string}> */
    private const INDEXES = [
        'campaign_engagement_events' => [
            'columns' => ['created_at', 'workspace_id'],
            'name' => 'campaign_events_analytics_time_idx',
        ],
        'campaign_engagement_event_sources' => [
            'columns' => ['created_at', 'workspace_id'],
            'name' => 'campaign_event_sources_analytics_time_idx',
        ],
        'campaign_lead_conversions' => [
            'columns' => ['converted_at', 'workspace_id'],
            'name' => 'campaign_leads_analytics_time_idx',
        ],
        'workspace_drop_visitors' => [
            'columns' => ['created_at', 'workspace_id'],
            'name' => 'drop_visitors_analytics_time_idx',
        ],
        'workspace_pmta_feedback_events' => [
            'columns' => ['at', 'workspace_id'],
            'name' => 'pmta_feedback_analytics_time_idx',
        ],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (self::INDEXES as $table => $index) {
                $this->createPostgresIndexConcurrently($table, $index['columns'], $index['name']);
            }

            return;
        }

        foreach (self::INDEXES as $table => $index) {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->index($index['columns'], $index['name']);
            });
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (self::INDEXES as $index) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index['name']);
            }

            return;
        }

        foreach (self::INDEXES as $table => $index) {
            Schema::table($table, function (Blueprint $blueprint) use ($index): void {
                $blueprint->dropIndex($index['name']);
            });
        }
    }

    /**
     * Build large event-table indexes without blocking writes, repairing an invalid remnant from an interrupted concurrent build.
     *
     * @param  list<string>  $columns
     */
    private function createPostgresIndexConcurrently(string $table, array $columns, string $name): void
    {
        $existing = DB::table('pg_class as index_catalog')
            ->join('pg_index as index_metadata', 'index_metadata.indexrelid', '=', 'index_catalog.oid')
            ->join('pg_class as table_catalog', 'table_catalog.oid', '=', 'index_metadata.indrelid')
            ->where('index_catalog.relname', $name)
            ->where('table_catalog.relname', $table)
            ->value('index_metadata.indisvalid');

        if ($existing === false || $existing === 'f') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.$name);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$name.' ON '.$table.' ('.implode(', ', $columns).')');
    }
};
