<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\OutboxMessage;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspaces = $request->user()->workspaces()
            ->where('workspaces.status', 'active')
            ->orderBy('workspaces.name')
            ->get(['workspaces.id', 'workspaces.name', 'workspaces.slug', 'workspaces.status']);

        return response()->json([
            'data' => $workspaces->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'role' => $workspace->pivot->role,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'alpha_dash', 'max:120'],
        ]);

        $idempotencyKey = $this->idempotencyKey($request);
        $name = trim($data['name']);
        $slug = Str::slug($data['slug'] ?? $data['name']);
        $slug = $slug !== '' ? $slug : 'workspace';
        $fingerprint = $this->fingerprint(['name' => $name, 'slug' => $slug]);

        $existing = Workspace::query()
            ->where('created_by', $request->user()->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            abort_unless(hash_equals($existing->request_fingerprint, $fingerprint), 409, 'Idempotency key was already used for a different workspace request.');

            return response()->json(['data' => [
                'id' => $existing->id,
                'name' => $existing->name,
                'slug' => $existing->slug,
                'role' => 'owner',
            ]], 200);
        }

        if (Workspace::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['This workspace address is already in use. Choose another one.'],
            ]);
        }

        try {
            $workspace = DB::transaction(function () use ($request, $name, $slug, $idempotencyKey, $fingerprint): Workspace {
                $workspace = Workspace::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                    'status' => 'active',
                    'created_by' => $request->user()->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                ]);

                $workspace->users()->attach($request->user()->getKey(), [
                    'role' => 'owner',
                    'joined_at' => now(),
                ]);

                AuditEvent::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'actor_user_id' => $request->user()->getKey(),
                    'action' => 'workspace.created',
                    'subject_type' => 'workspace',
                    'subject_id' => $workspace->getKey(),
                    'request_id' => $request->attributes->get('request_id'),
                    'properties' => ['role_granted' => 'owner'],
                    'occurred_at' => now(),
                ]);

                OutboxMessage::query()->create([
                    'workspace_id' => $workspace->getKey(),
                    'event_type' => 'workspace.created',
                    'payload' => ['workspace_id' => $workspace->getKey()],
                    'idempotency_key' => (string) Str::uuid(),
                    'available_at' => now(),
                    'created_at' => now(),
                ]);

                return $workspace;
            });
        } catch (QueryException $exception) {
            $existing = Workspace::query()
                ->where('created_by', $request->user()->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                abort_unless(hash_equals($existing->request_fingerprint, $fingerprint), 409, 'Idempotency key was already used for a different workspace request.');

                return response()->json(['data' => [
                    'id' => $existing->id,
                    'name' => $existing->name,
                    'slug' => $existing->slug,
                    'role' => 'owner',
                ]], 200);
            }

            if (Workspace::query()->where('slug', $slug)->exists()) {
                return response()->json(['message' => 'This workspace address is already in use.', 'code' => 'workspace_slug_conflict'], 409);
            }

            throw $exception;
        }

        return response()->json([
            'data' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'role' => 'owner',
            ],
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
