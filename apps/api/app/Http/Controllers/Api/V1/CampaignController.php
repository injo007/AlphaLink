<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Campaign;
use App\Models\CampaignRevision;
use App\Models\OutboxMessage;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CampaignController extends Controller
{
    public function show(Request $request, string $workspaceId, string $campaign): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $campaign = $workspace->campaigns()->whereKey($campaign)->firstOrFail();
        $revision = $campaign->latestRevision;

        return response()->json(['data' => $this->present($campaign, $revision)]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $campaigns = $workspace->campaigns()
            ->with('latestRevision')
            ->orderByDesc('created_at')
            ->paginate(25);

        return response()->json([
            'data' => $campaigns->getCollection()->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'kind' => $campaign->kind,
                'status' => $campaign->status,
                'latest_revision' => $campaign->latestRevision?->only(['id', 'revision_number', 'content_sha256', 'created_at']),
                'created_at' => $campaign->created_at?->toISOString(),
            ]),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $data = $this->validatedContent($request, true);
        $idempotencyKey = $this->idempotencyKey($request);
        $content = $this->contentPayload($data);
        $contentHash = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $prior = $workspace->campaigns()->where('idempotency_key', $idempotencyKey)->first();
        if ($prior) {
            $priorRevision = $prior->revisions()->where('revision_number', 1)->first();
            abort_unless(
                $prior->name === trim($data['name'])
                    && $prior->kind === $data['kind']
                    && $priorRevision
                    && hash_equals($priorRevision->content_sha256, $contentHash),
                409,
                'Idempotency key was already used for a different campaign.',
            );

            return response()->json(['data' => $this->present($prior, $priorRevision)], 200);
        }

        try {
            $campaign = DB::transaction(function () use ($request, $workspace, $data, $content, $contentHash, $idempotencyKey): Campaign {
                $campaign = $workspace->campaigns()->create([
                    'created_by' => $request->user()->getKey(),
                    'name' => trim($data['name']),
                    'kind' => $data['kind'],
                    'status' => 'draft',
                    'idempotency_key' => $idempotencyKey,
                ]);

                $revision = $campaign->revisions()->create([
                    'revision_number' => 1,
                    'payload' => $content,
                    'content_sha256' => $contentHash,
                    'idempotency_key' => 'create:'.hash('sha256', $idempotencyKey),
                    'request_fingerprint' => $contentHash,
                    'created_by' => $request->user()->getKey(),
                    'created_at' => now(),
                ]);

                AuditEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'actor_user_id' => $request->user()->getKey(),
                    'action' => 'campaign.draft_created',
                    'subject_type' => 'campaign',
                    'subject_id' => $campaign->getKey(),
                    'request_id' => $request->attributes->get('request_id'),
                    'properties' => ['revision_id' => $revision->getKey(), 'content_sha256' => $contentHash],
                    'occurred_at' => now(),
                ]);

                OutboxMessage::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'event_type' => 'campaign.draft_created',
                    'payload' => ['campaign_id' => $campaign->getKey(), 'revision_id' => $revision->getKey()],
                    'idempotency_key' => (string) Str::uuid(),
                    'available_at' => now(),
                    'created_at' => now(),
                ]);

                return $campaign->setRelation('revisions', collect([$revision]));
            });
        } catch (QueryException $exception) {
            $prior = $workspace->campaigns()->where('idempotency_key', $idempotencyKey)->first();
            $priorRevision = $prior?->revisions()->where('revision_number', 1)->first();
            if ($prior && $priorRevision) {
                abort_unless(
                    $prior->name === trim($data['name'])
                        && $prior->kind === $data['kind']
                        && hash_equals($priorRevision->content_sha256, $contentHash),
                    409,
                    'Idempotency key was already used for a different campaign.',
                );

                return response()->json(['data' => $this->present($prior, $priorRevision)], 200);
            }

            throw $exception;
        }

        return response()->json(['data' => $this->present($campaign, $campaign->revisions->first())], 201);
    }

    public function revise(Request $request, string $workspaceId, string $campaign): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $campaign = $workspace->campaigns()->whereKey($campaign)->firstOrFail();

        $data = $this->validatedContent($request, false);
        $idempotencyKey = $this->idempotencyKey($request);
        $content = $this->contentPayload($data);
        $contentHash = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $prior = $campaign->revisions()->where('idempotency_key', $idempotencyKey)->first();
        if ($prior) {
            abort_unless(hash_equals($prior->request_fingerprint, $contentHash), 409, 'Idempotency key was already used for different campaign content.');

            return response()->json(['data' => $this->present($campaign, $prior)], 200);
        }

        abort_unless($campaign->status === 'draft', 409, 'Only draft campaigns can be revised.');

        [$revision, $created] = DB::transaction(function () use ($request, $workspace, $campaign, $content, $contentHash, $idempotencyKey): array {
            $lockedCampaign = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            $prior = $campaign->revisions()->where('idempotency_key', $idempotencyKey)->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_fingerprint, $contentHash), 409, 'Idempotency key was already used for different campaign content.');

                return [$prior, false];
            }

            abort_unless($lockedCampaign->status === 'draft', 409, 'Only draft campaigns can be revised.');

            $latest = $campaign->revisions()->lockForUpdate()->first();

            $revision = $campaign->revisions()->create([
                'revision_number' => ($latest?->revision_number ?? 0) + 1,
                'payload' => $content,
                'content_sha256' => $contentHash,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $contentHash,
                'created_by' => $request->user()->getKey(),
                'created_at' => now(),
            ]);

            AuditEvent::query()->create([
                'workspace_id' => $workspace->getKey(),
                'actor_user_id' => $request->user()->getKey(),
                'action' => 'campaign.revised',
                'subject_type' => 'campaign',
                'subject_id' => $campaign->getKey(),
                'request_id' => $request->attributes->get('request_id'),
                'properties' => ['revision_id' => $revision->getKey(), 'content_sha256' => $contentHash],
                'occurred_at' => now(),
            ]);

            OutboxMessage::query()->create([
                'workspace_id' => $workspace->getKey(),
                'event_type' => 'campaign.revised',
                'payload' => ['campaign_id' => $campaign->getKey(), 'revision_id' => $revision->getKey()],
                'idempotency_key' => (string) Str::uuid(),
                'available_at' => now(),
                'created_at' => now(),
            ]);

            return [$revision, true];
        });

        return response()->json(['data' => $this->present($campaign, $revision)], $created ? 201 : 200);
    }

    private function validatedContent(Request $request, bool $includeCampaignFields): array
    {
        $rules = [
            'subject' => ['required', 'string', 'max:998'],
            'from_name' => ['required', 'string', 'max:160'],
            'from_email' => ['required', 'email:rfc', 'max:320'],
            'reply_to' => ['nullable', 'email:rfc', 'max:320'],
            'text_body' => ['nullable', 'string', 'max:1000000', 'required_without:html_body'],
            'html_body' => ['nullable', 'string', 'max:2000000', 'required_without:text_body'],
        ];

        if ($includeCampaignFields) {
            $rules['name'] = ['required', 'string', 'max:160'];
            $rules['kind'] = ['required', 'in:marketing,transactional,warmup'];
        }

        return $request->validate($rules);
    }

    private function contentPayload(array $data): array
    {
        return [
            'subject' => trim($data['subject']),
            'from_name' => trim($data['from_name']),
            'from_email' => mb_strtolower(trim($data['from_email'])),
            'reply_to' => isset($data['reply_to']) ? mb_strtolower(trim($data['reply_to'])) : null,
            'text_body' => $data['text_body'] ?? null,
            'html_body' => $data['html_body'] ?? null,
        ];
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || strlen($key) < 16 || strlen($key) > 128) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['Provide a unique key between 16 and 128 characters.'],
            ]);
        }

        return $key;
    }

    private function present(Campaign $campaign, ?CampaignRevision $revision): array
    {
        return [
            'id' => $campaign->id,
            'name' => $campaign->name,
            'kind' => $campaign->kind,
            'status' => $campaign->status,
            'revision' => $revision ? [
                'id' => $revision->id,
                'number' => $revision->revision_number,
                'content_sha256' => $revision->content_sha256,
                'content' => $revision->payload,
                'created_at' => $revision->created_at?->toISOString(),
            ] : null,
            'created_at' => $campaign->created_at?->toISOString(),
        ];
    }
}
