<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\CampaignRecipientSnapshot;
use App\Models\Workspace;
use App\Services\Campaigns\DmsCampaignBodyGenerator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CampaignComposerToolsController extends Controller
{
    private const CAMPAIGN_ROLES = ['owner', 'admin', 'operator'];

    public function encode(Request $request, string $workspaceId): JsonResponse
    {
        $this->workspaceFor($request, $workspaceId);
        $data = $request->validate([
            'string' => ['required', 'string', 'max:10000'],
            'schema' => ['required', 'in:Q,HQ,B'],
            'charset' => ['required', 'in:UTF-8,UTF-7,ISO-8859-1,ISO-8859-15,windows-1252,US-ASCII,CP1256'],
        ]);

        if ($data['schema'] === 'HQ') {
            $hex = '';
            for ($index = 0, $length = strlen($data['string']); $index < $length; $index++) {
                $hex .= '='.bin2hex($data['string'][$index]);
            }

            return response()->json('=?'.$data['charset'].'?Q?'.strtoupper($hex).'?=');
        }

        $encoded = iconv_mime_encode('', $data['string'], [
            'scheme' => $data['schema'],
            'input-charset' => 'UTF-8',
            'output-charset' => $data['charset'],
            'line-length' => 76,
            'line-break-chars' => "\r\n",
        ]);

        abort_if($encoded === false, 422, 'This text cannot be encoded with the selected charset.');

        return response()->json(trim(str_replace(':', '', $encoded)));
    }

    public function shorten(Request $request, string $workspaceId, string $provider): JsonResponse
    {
        $this->workspaceFor($request, $workspaceId);
        abort_unless(in_array($provider, ['tinyurl', 'isgd'], true), 404);
        $data = $request->validate([
            'links' => ['required', 'array', 'min:1', 'max:50'],
            'links.*' => ['required', 'url:http,https', 'max:2048', 'distinct'],
        ]);
        $endpoint = $provider === 'tinyurl'
            ? 'https://tinyurl.com/api-create.php'
            : 'https://is.gd/create.php';
        $links = array_values(array_unique($data['links']));
        $result = [];

        foreach ($links as $link) {
            try {
                $response = Http::timeout(15)->get($endpoint, $provider === 'tinyurl'
                    ? ['url' => $link]
                    : ['format' => 'simple', 'url' => $link]);
            } catch (ConnectionException) {
                return response()->json(['message' => 'The short-link provider could not be reached.'], 502);
            }

            if (! $response->successful() || trim($response->body()) === '') {
                return response()->json(['message' => 'The short-link provider did not return a link.'], 502);
            }

            $result[] = ['link' => $link, 'shortlink' => trim($response->body())];
        }

        return response()->json($result);
    }

    public function generateBody(
        Request $request,
        string $workspaceId,
        string $campaignId,
        DmsCampaignBodyGenerator $generator,
    ): JsonResponse {
        $workspace = $this->workspaceFor($request, $workspaceId);
        $campaign = $workspace->campaigns()
            ->with('latestRevision')
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaignId)
            ->firstOrFail();
        abort_unless($campaign->kind === 'marketing' && $campaign->status === 'draft' && $campaign->is_active, 409);
        $data = $request->validate(['body' => ['required', 'string', 'max:2000000']]);
        $ispId = $campaign->latestRevision?->payload['send_setup']['isp_id'] ?? null;
        abort_unless(is_numeric($ispId), 409, 'The campaign has no saved ISP for link generation.');

        return response()->json(['data' => $generator->generate((string) $campaign->getKey(), (int) $ispId, $data['body'])]);
    }

    public function updateProgress(Request $request, string $workspaceId, string $campaignId): JsonResponse
    {
        $workspace = $this->workspaceFor($request, $workspaceId);
        $campaign = $workspace->campaigns()
            ->where('created_by', $request->user()->getKey())
            ->whereKey($campaignId)
            ->firstOrFail();
        abort_unless($campaign->kind === 'marketing' && $campaign->status === 'draft' && $campaign->is_active, 409);
        $sendCount = (int) (CampaignRecipientSnapshot::query()->where('campaign_id', $campaign->getKey())->max('recipient_count') ?? 0);
        $data = $request->validate(['send_progress' => ['required', 'integer', 'min:0', 'max:'.$sendCount]]);
        $previousProgress = $campaign->send_progress;
        $campaign->forceFill(['send_progress' => (int) $data['send_progress']])->save();
        AuditEvent::query()->create([
            'workspace_id' => $workspace->getKey(),
            'actor_user_id' => $request->user()->getKey(),
            'action' => 'campaign.send_progress_updated',
            'subject_type' => 'campaign',
            'subject_id' => $campaign->getKey(),
            'request_id' => $request->attributes->get('request_id'),
            'properties' => ['from' => $previousProgress, 'to' => $campaign->send_progress, 'send_count' => $sendCount],
            'occurred_at' => now(),
        ]);

        return response()->json(['data' => 'Progress updated', 'send_progress' => $campaign->send_progress, 'send_count' => $sendCount]);
    }

    private function workspaceFor(Request $request, string $workspaceId): Workspace
    {
        $workspace = Workspace::query()
            ->whereKey($workspaceId)
            ->whereHas('users', fn ($query) => $query->whereKey($request->user()->getKey()))
            ->firstOrFail();
        $member = $workspace->users()->whereKey($request->user()->getKey())->firstOrFail();
        abort_unless(in_array($member->pivot->role, self::CAMPAIGN_ROLES, true), 403);

        return $workspace;
    }
}
