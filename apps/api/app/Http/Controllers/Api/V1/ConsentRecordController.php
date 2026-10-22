<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\ConsentRecord;
use App\Models\OutboxMessage;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConsentRecordController extends Controller
{
    public function index(Request $request, string $workspaceId, string $contact): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $contact = $workspace->contacts()->whereKey($contact)->firstOrFail();
        $records = ConsentRecord::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('contact_id', $contact->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->cursorPaginate(50);

        return response()->json([
            'data' => $records->getCollection()->map(fn (ConsentRecord $record) => [
                'id' => $record->id,
                'action' => $record->action,
                'purpose' => $record->purpose,
                'channel' => $record->channel,
                'basis' => $record->basis,
                'source' => $record->source,
                'evidence_reference' => $record->evidence_reference,
                'policy_version' => $record->policy_version,
                'occurred_at' => $record->occurred_at?->toISOString(),
                'expires_at' => $record->expires_at?->toISOString(),
            ]),
            'next_cursor' => $records->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request, string $workspaceId, string $contact): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $contact = $workspace->contacts()->whereKey($contact)->firstOrFail();

        $data = $request->validate([
            'action' => ['required', 'in:granted,withdrawn,expired,corrected'],
            'purpose' => ['required', 'string', 'max:64'],
            'channel' => ['required', 'in:email'],
            'basis' => ['required_if:action,granted', 'nullable', 'string', 'max:64'],
            'source' => ['required', 'in:operator,import'],
            'evidence_reference' => ['required_if:action,granted', 'nullable', 'string', 'max:512'],
            'policy_version' => ['nullable', 'string', 'max:64'],
            'occurred_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:occurred_at'],
        ]);

        $idempotencyKey = $this->idempotencyKey($request);
        $fingerprint = $this->fingerprint($data);
        $existing = ConsentRecord::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            abort_unless(
                $existing->contact_id === $contact->getKey()
                    && hash_equals($existing->request_fingerprint, $fingerprint),
                409,
                'Idempotency key was already used for a different request.',
            );

            return response()->json(['data' => ['id' => $existing->id, 'action' => $existing->action]], 200);
        }

        try {
            $record = DB::transaction(function () use ($request, $workspace, $contact, $data, $idempotencyKey, $fingerprint): ConsentRecord {
                $record = ConsentRecord::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'contact_id' => $contact->getKey(),
                    ...$data,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'actor_user_id' => $request->user()->getKey(),
                ]);

                AuditEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'actor_user_id' => $request->user()->getKey(),
                    'action' => 'contact.consent_recorded',
                    'subject_type' => 'contact',
                    'subject_id' => $contact->getKey(),
                    'request_id' => $request->attributes->get('request_id'),
                    'properties' => ['record_id' => $record->getKey(), 'action' => $record->action, 'purpose' => $record->purpose],
                    'occurred_at' => now(),
                ]);

                OutboxMessage::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'event_type' => $record->action === 'withdrawn' ? 'contact.consent_withdrawn' : 'contact.consent_recorded',
                    'payload' => ['contact_id' => $contact->getKey(), 'consent_record_id' => $record->getKey()],
                    'idempotency_key' => (string) Str::uuid(),
                    'available_at' => now(),
                    'created_at' => now(),
                ]);

                return $record;
            });
        } catch (QueryException $exception) {
            $existing = ConsentRecord::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                abort_unless(
                    $existing->contact_id === $contact->getKey()
                        && hash_equals($existing->request_fingerprint, $fingerprint),
                    409,
                    'Idempotency key was already used for a different request.',
                );

                return response()->json(['data' => ['id' => $existing->id, 'action' => $existing->action]], 200);
            }

            throw $exception;
        }

        return response()->json([
            'data' => ['id' => $record->id, 'action' => $record->action, 'occurred_at' => $record->occurred_at->toISOString()],
        ], 201);
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

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
