<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\OutboxMessage;
use App\Models\SuppressionEvent;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SuppressionEventController extends Controller
{
    public function index(Request $request, string $workspaceId, string $contact): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $contact = $workspace->contacts()->whereKey($contact)->firstOrFail();
        $events = SuppressionEvent::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('contact_id', $contact->getKey())
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->cursorPaginate(50);

        return response()->json([
            'data' => $events->getCollection()->map(fn (SuppressionEvent $event) => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'reason' => $event->reason,
                'scope_type' => $event->scope_type,
                'scope_key' => $event->scope_key,
                'source' => $event->source,
                'source_reference' => $event->source_reference,
                'effective_at' => $event->effective_at?->toISOString(),
            ]),
            'next_cursor' => $events->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request, string $workspaceId, string $contact): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $contact = $workspace->contacts()->whereKey($contact)->firstOrFail();

        $data = $request->validate([
            'reason' => ['required', 'in:unsubscribe,complaint,hard_bounce,legal_request,operator_block'],
            'offer_id' => [
                'sometimes', 'nullable', 'integer', 'min:1',
                Rule::exists('workspace_offers', 'id')->where('workspace_id', $workspace->getKey()),
            ],
            'source_reference' => ['nullable', 'string', 'max:512'],
            'effective_at' => ['required', 'date'],
        ]);
        if (filled($data['offer_id'] ?? null) && $data['reason'] !== 'unsubscribe') {
            throw ValidationException::withMessages([
                'reason' => ['Offer-scoped exclusions are recorded for unsubscribes, matching the DMS behavior.'],
            ]);
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['Provide a unique key between 16 and 128 characters.'],
            ]);
        }

        $fingerprint = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $existing = SuppressionEvent::query()
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

            return response()->json(['data' => ['id' => $existing->id, 'reason' => $existing->reason]], 200);
        }

        try {
            $event = DB::transaction(function () use ($request, $workspace, $contact, $data, $idempotencyKey, $fingerprint): SuppressionEvent {
                $offerId = $data['offer_id'] ?? null;
                $event = SuppressionEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'contact_id' => $contact->getKey(),
                    'event_type' => 'added',
                    'scope_type' => $offerId === null ? 'workspace' : 'offer',
                    'scope_key' => $offerId === null ? null : (string) $offerId,
                    'source_reference' => $data['source_reference'] ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'actor_user_id' => $request->user()->getKey(),
                    'effective_at' => $data['effective_at'],
                    'reason' => $data['reason'],
                    // Provider feedback must enter through a verified ingestion path.
                    'source' => 'operator_request',
                ]);

                AuditEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'actor_user_id' => $request->user()->getKey(),
                    'action' => 'contact.suppressed',
                    'subject_type' => 'contact',
                    'subject_id' => $contact->getKey(),
                    'request_id' => $request->attributes->get('request_id'),
                    'properties' => [
                        'suppression_event_id' => $event->getKey(),
                        'reason' => $event->reason,
                        'scope_type' => $event->scope_type,
                        ...($offerId === null ? [] : ['offer_id' => (int) $offerId]),
                    ],
                    'occurred_at' => now(),
                ]);

                OutboxMessage::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'event_type' => 'contact.suppressed',
                    'payload' => [
                        'contact_id' => $contact->getKey(),
                        'suppression_event_id' => $event->getKey(),
                        'scope_type' => $event->scope_type,
                        ...($offerId === null ? [] : ['offer_id' => (int) $offerId]),
                    ],
                    'idempotency_key' => (string) Str::uuid(),
                    'available_at' => now(),
                    'created_at' => now(),
                ]);

                return $event;
            });
        } catch (QueryException $exception) {
            $existing = SuppressionEvent::query()
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

                return response()->json(['data' => ['id' => $existing->id, 'reason' => $existing->reason]], 200);
            }

            throw $exception;
        }

        return response()->json([
            'data' => [
                'id' => $event->id,
                'reason' => $event->reason,
                'scope_type' => $event->scope_type,
                'scope_key' => $event->scope_key,
                'effective_at' => $event->effective_at->toISOString(),
            ],
        ], 201);
    }
}
