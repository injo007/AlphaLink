<?php

namespace App\Services\Campaigns;

use App\Jobs\ProcessWarmupSendRun;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\User;
use App\Models\WarmupSendRun;
use App\Models\Workspace;
use App\Models\WorkspaceSeedEmail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrepareWarmupSendRun
{
    public function __construct(private readonly AlphaLinkCampaignSendCatalog $catalog) {}

    public function create(Workspace $workspace, Campaign $campaign, User $actor, string $idempotencyKey): WarmupSendRun
    {
        $campaign->loadMissing('latestRevision');
        $revision = $campaign->latestRevision;
        if ($campaign->kind !== 'warmup' || ! $campaign->is_active || $campaign->status !== 'draft' || ! $revision) {
            throw ValidationException::withMessages(['campaign' => 'Only an active saved Warmup draft can be sent.']);
        }

        $payload = (array) $revision->payload;
        $setup = (array) ($payload['send_setup'] ?? []);
        $role = (string) $workspace->pivot->role;
        $route = (string) ($setup['route_option'] ?? '');
        $ipType = (string) ($setup['ip_type'] ?? '');
        $serverIds = array_values(array_map('strval', (array) ($setup['server_ids'] ?? [])));
        $ipIds = array_values(array_map('strval', (array) ($setup['ip_ids'] ?? [])));
        if ($serverIds === [] || $ipIds === []) {
            throw ValidationException::withMessages(['send_setup.ip_ids' => 'Select at least one installed server and one active IP or MTA route before sending.']);
        }
        $this->catalog->validateRevisionRouting($workspace, $actor, $role, $route, $ipType, $serverIds, $ipIds);

        $seedListIds = array_values(array_unique(array_map('intval', (array) ($payload['seed_list_ids'] ?? []))));
        if ($seedListIds === []) {
            throw ValidationException::withMessages(['seed_list_ids' => 'Select at least one active seed list before sending this Warmup.']);
        }

        $seedLists = $workspace->seedLists()
            ->where('is_active', true)
            ->when($role === 'operator', fn ($query) => $query->where('user_id', $actor->getKey()))
            ->whereIn('id', $seedListIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if (array_diff($seedListIds, $seedLists) !== []) {
            throw ValidationException::withMessages(['seed_list_ids' => 'One or more selected seed lists are inactive or unavailable to this workspace member.']);
        }

        $startFrom = max(0, (int) ($payload['start_from'] ?? 0));
        $totalSend = max(0, (int) ($payload['total_send'] ?? 0));
        $recipients = [];
        $seen = [];
        $skipped = 0;
        $limit = $totalSend > 0 ? $totalSend : PHP_INT_MAX;
        WorkspaceSeedEmail::query()
            ->whereIn('workspace_seed_list_id', $seedListIds)
            ->whereHas('seedList', function ($query) use ($workspace, $actor, $role): void {
                $query->where('workspace_id', $workspace->getKey())
                    ->where('is_active', true)
                    ->when($role === 'operator', fn ($owned) => $owned->where('user_id', $actor->getKey()));
            })
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use (&$recipients, &$seen, &$skipped, $startFrom, $limit): bool {
                foreach ($rows as $row) {
                    $email = mb_strtolower(trim((string) $row->email));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        continue;
                    }
                    $hash = hash('sha256', $email);
                    if (isset($seen[$hash])) {
                        continue;
                    }
                    $seen[$hash] = true;
                    if ($skipped < $startFrom) {
                        $skipped++;

                        continue;
                    }
                    if (count($recipients) >= $limit) {
                        return false;
                    }
                    $recipients[] = ['email' => $email, 'hash' => $hash];
                }

                return count($recipients) < $limit;
            });

        if ($recipients === []) {
            throw ValidationException::withMessages(['seed_list_ids' => 'The selected seed lists have no valid recipients remaining after Start From and Total Send are applied.']);
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages(['idempotency_key' => 'A valid Warmup send key is required.']);
        }

        $sendType = (string) ($payload['send_type'] ?? $setup['send_type'] ?? 'delay');
        $fingerprint = hash('sha256', json_encode([
            'campaign_id' => (string) $campaign->getKey(),
            'revision_id' => (string) $revision->getKey(),
            'send_type' => $sendType,
            'seed_list_ids' => $seedListIds,
            'recipient_hashes' => array_column($recipients, 'hash'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        [$run, $created] = DB::transaction(function () use ($workspace, $campaign, $actor, $revision, $idempotencyKey, $fingerprint, $sendType, $recipients, $payload): array {
            Campaign::query()->where('workspace_id', $workspace->getKey())->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            $activeRun = WarmupSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $campaign->getKey())
                ->whereIn('state', ['planned', 'running'])
                ->latest('created_at')
                ->lockForUpdate()
                ->first();
            if ($activeRun) {
                return [$activeRun, false];
            }

            abort_if(WarmupSendRun::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('campaign_id', $campaign->getKey())
                ->where('revision_id', $revision->getKey())
                ->where(function ($query): void {
                    $query->whereIn('state', ['completed', 'stopped', 'needs_review'])
                        ->orWhere(function ($failed): void {
                            $failed->where('state', 'failed')
                                ->where(function ($progress): void {
                                    $progress->where('accepted_count', '>', 0)->orWhere('uncertain_count', '>', 0);
                                });
                        });
                })
                ->exists(), 409, 'This Warmup revision already has send history. Save a new revision before sending again.');

            $existing = WarmupSendRun::query()->where('workspace_id', $workspace->getKey())->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409, 'This send key was already used with different Warmup data.');

                return [$existing, false];
            }

            $run = WarmupSendRun::query()->create([
                'id' => (string) Str::uuid(),
                'workspace_id' => $workspace->getKey(),
                'campaign_id' => $campaign->getKey(),
                'revision_id' => $revision->getKey(),
                'requested_by' => $actor->getKey(),
                'send_type' => $sendType,
                'state' => 'planned',
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'execution_options' => [
                    'start_from' => (int) ($payload['start_from'] ?? 0),
                    'sleep_email_ms' => max(0, (int) ($payload['sleep_email'] ?? 0)),
                    'sleep_ip_ms' => max(0, (int) ($payload['sleep_ip'] ?? 0)),
                    'fraction_ip' => max(1, (int) ($payload['fraction_ip'] ?? 1)),
                ],
                'recipient_count' => count($recipients),
                'next_position' => 1,
            ]);

            foreach (array_chunk($recipients, 500) as $chunkIndex => $chunk) {
                $rows = [];
                foreach ($chunk as $offset => $recipient) {
                    $rows[] = [
                        'workspace_id' => $workspace->getKey(),
                        'send_run_id' => $run->getKey(),
                        'position' => ($chunkIndex * 500) + $offset + 1,
                        'recipient_ciphertext' => Crypt::encryptString($recipient['email']),
                        'recipient_sha256' => $recipient['hash'],
                        'state' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                DB::table('warmup_send_recipients')->insert($rows);
            }

            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $actor->getKey(),
                'action' => 'warmup.send_started',
                'subject_type' => WarmupSendRun::class,
                'subject_id' => $run->getKey(),
                'properties' => [
                    'campaign_id' => (string) $campaign->getKey(),
                    'revision_id' => (string) $revision->getKey(),
                    'recipient_count' => count($recipients),
                    'send_type' => $sendType,
                ],
                'occurred_at' => now(),
            ]);

            return [$run, true];
        }, attempts: 3);

        if ($created) {
            ProcessWarmupSendRun::dispatch((string) $run->getKey())->onQueue('campaign-sends')->afterCommit();
        }

        return $run;
    }
}
