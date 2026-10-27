<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessDropTrackingEventJob;
use App\Models\WorkspaceDrop;
use App\Services\Campaigns\TrackingIngestAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DropTrackingController extends Controller
{
    public function redirect(Request $request, TrackingIngestAuthenticator $authenticator): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'drop_id' => ['required', 'integer', 'min:1'],
            'email_id' => ['required', 'string', 'max:64'],
            'isp_id' => ['required', 'integer', 'min:0'],
            'token' => ['required', 'string', 'max:4096'],
            'ip_address' => ['required', 'ip'],
            'user_agent' => ['nullable', 'string', 'max:4096'],
            'type' => ['required', 'string', 'in:red,unsub'],
        ]);

        $drop = WorkspaceDrop::query()->whereKey($data['drop_id'])->first();
        if ($drop === null || $drop->is_restricted) {
            return response()->json('NFE', 404);
        }

        $workspace = $drop->workspace()->firstOrFail();
        $authenticator->assertValid($workspace, $data['key']);
        $offer = $drop->offer()->where('workspace_id', $workspace->getKey())->first();
        $sponsor = $offer?->sponsor;
        if ($offer === null || $sponsor === null) {
            return response()->json('NFE', 404);
        }

        return response()->json($data['type'] === 'red'
            ? ['type' => $sponsor->type, 'link' => $offer->redirect, 'user' => $drop->user_id]
            : ['link' => $offer->unsubscribe ?: 'unsubscribe?u=1']);
    }

    public function store(Request $request, TrackingIngestAuthenticator $authenticator): Response
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:255'],
            'action' => ['required', 'string', 'in:click,unsubscribe,open,bot'],
            'ip_address' => ['required', 'ip'],
            'user_agent' => ['required', 'string', 'max:4096'],
            'referral' => ['nullable', 'string', 'max:4096'],
            'drop_id' => ['required', 'integer', 'min:1', 'exists:workspace_drops,id'],
            'email_id' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', 'max:24'],
            'list_id' => ['required', 'string', 'max:64'],
            'isp_id' => ['required', 'integer', 'min:0'],
            'server_id' => ['required', 'string', 'max:128'],
            'ip_id' => ['required', 'string', 'max:128'],
            'domain_id' => ['required', 'string', 'max:128'],
            'from_id' => ['required', 'string', 'max:128'],
            'subject_id' => ['required', 'string', 'max:128'],
            'creative_id' => ['required', 'string', 'max:128'],
            'token' => ['required', 'string', 'max:4096'],
        ]);

        $drop = WorkspaceDrop::query()->whereKey((int) $data['drop_id'])->firstOrFail();
        $workspace = $drop->workspace()->firstOrFail();
        $authenticator->assertValid($workspace, $data['key']);

        if ($data['email_id'] !== '0') {
            ProcessDropTrackingEventJob::dispatch($drop, $workspace, $data);
        }

        return response()->noContent();
    }
}
