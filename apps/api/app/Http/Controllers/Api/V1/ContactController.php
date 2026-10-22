<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\OutboxMessage;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContactController extends Controller
{
    public function show(Request $request, string $workspaceId, string $contact): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $contact = $workspace->contacts()->whereKey($contact)->firstOrFail();

        return response()->json(['data' => $this->present($contact)]);
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $page = $workspace->contacts()
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursorPaginate(50);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Contact $contact) => $this->present($contact)),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('alphalink.workspace');
        $data = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:320'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 128) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => ['Provide a unique key between 16 and 128 characters.'],
            ]);
        }

        $lookupKey = config('alphalink.contact_lookup_key');
        if (! is_string($lookupKey) || strlen($lookupKey) < 32) {
            return response()->json([
                'message' => 'Contact storage is not configured. Set a dedicated lookup key before importing personal data.',
                'code' => 'contact_storage_unavailable',
            ], 503);
        }

        $normalizedEmail = mb_strtolower(trim($data['email']));
        $emailHash = hash_hmac('sha256', $normalizedEmail, $lookupKey);
        $priorRequest = $workspace->contacts()->where('idempotency_key', $idempotencyKey)->first();

        if ($priorRequest) {
            abort_unless(hash_equals($priorRequest->email_lookup_hash, $emailHash), 409, 'Idempotency key was already used for a different request.');

            return response()->json(['data' => $this->present($priorRequest)], 200);
        }

        if ($workspace->contacts()->where('email_lookup_hash', $emailHash)->exists()) {
            return response()->json([
                'message' => 'A contact with this address already exists in this workspace.',
                'code' => 'contact_already_exists',
            ], 409);
        }

        try {
            $contact = DB::transaction(function () use ($request, $workspace, $normalizedEmail, $emailHash, $idempotencyKey): Contact {
                $contact = $workspace->contacts()->create([
                    'email' => $normalizedEmail,
                    'email_lookup_hash' => $emailHash,
                    'lookup_key_version' => config('alphalink.contact_lookup_key_version'),
                    'status' => 'active',
                    'idempotency_key' => $idempotencyKey,
                ]);

                AuditEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'actor_user_id' => $request->user()->getKey(),
                    'action' => 'contact.created',
                    'subject_type' => 'contact',
                    'subject_id' => $contact->getKey(),
                    'request_id' => $request->attributes->get('request_id'),
                    'properties' => ['email_redacted' => true],
                    'occurred_at' => now(),
                ]);

                OutboxMessage::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'event_type' => 'contact.created',
                    'payload' => ['contact_id' => $contact->getKey()],
                    'idempotency_key' => (string) Str::uuid(),
                    'available_at' => now(),
                    'created_at' => now(),
                ]);

                return $contact;
            });
        } catch (QueryException $exception) {
            $idempotentContact = $workspace->contacts()->where('idempotency_key', $idempotencyKey)->first();
            if ($idempotentContact) {
                abort_unless(hash_equals($idempotentContact->email_lookup_hash, $emailHash), 409, 'Idempotency key was already used for a different request.');

                return response()->json(['data' => $this->present($idempotentContact)], 200);
            }

            // The unique index is authoritative if concurrent imports race.
            if ($workspace->contacts()->where('email_lookup_hash', $emailHash)->exists()) {
                return response()->json([
                    'message' => 'A contact with this address already exists in this workspace.',
                    'code' => 'contact_already_exists',
                ], 409);
            }

            throw $exception;
        }

        return response()->json(['data' => $this->present($contact)], 201);
    }

    private function present(Contact $contact): array
    {
        [$local, $domain] = array_pad(explode('@', $contact->email, 2), 2, '');
        $masked = $local === '' || $domain === '' ? '[redacted]' : substr($local, 0, 1).'***@'.$domain;

        return [
            'id' => $contact->id,
            'email_masked' => $masked,
            'status' => $contact->status,
            'created_at' => $contact->created_at?->toISOString(),
        ];
    }
}
