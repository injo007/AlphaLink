<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('pmta_action_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('managed_server_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedBigInteger('actor_user_id')->index();
            $table->string('action');
            $table->mediumText('result');
            $table->timestamps();
            $table->index(['workspace_id', 'managed_server_id', 'id'], 'pmta_action_histories_workspace_server_id');
        });

        DB::table('audit_events')
            ->join('managed_servers', function (JoinClause $join): void {
                $join->on(DB::raw('CAST(managed_servers.id AS TEXT)'), '=', 'audit_events.subject_id')
                    ->on('managed_servers.workspace_id', '=', 'audit_events.workspace_id');
            })
            ->where('audit_events.action', 'pmta.action')
            ->where('audit_events.subject_type', 'managed_server')
            ->whereNotNull('audit_events.workspace_id')
            ->whereNotNull('audit_events.actor_user_id')
            ->where('audit_events.occurred_at', '>', now()->subMonth()->endOfDay())
            ->select([
                'audit_events.workspace_id',
                'audit_events.actor_user_id',
                'managed_servers.id as managed_server_id',
                'audit_events.properties',
                'audit_events.occurred_at',
            ])
            ->orderBy('audit_events.occurred_at')
            ->orderBy('audit_events.id')
            ->chunk(250, function (Collection $events): void {
                foreach ($events as $event) {
                    $properties = is_array($event->properties)
                        ? $event->properties
                        : (json_decode((string) $event->properties, true) ?: []);
                    $action = trim((string) ($properties['pmta_action'] ?? ''));
                    if (! in_array($action, ['pause', 'resume', 'delete', 'reset', 'schedule', 'restart'], true)) {
                        continue;
                    }

                    $createdAt = $event->occurred_at ?? now();
                    DB::table('pmta_action_histories')->insert([
                        'workspace_id' => $event->workspace_id,
                        'managed_server_id' => $event->managed_server_id,
                        'actor_user_id' => $event->actor_user_id,
                        'action' => $action,
                        'result' => (string) ($properties['result'] ?? ''),
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pmta_action_histories');
    }
};
