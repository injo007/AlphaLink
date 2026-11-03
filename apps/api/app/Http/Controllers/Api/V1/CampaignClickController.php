<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCampaignTrackingEventJob;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\Workspace;
use App\Services\Campaigns\CampaignIpGeoResolver;
use App\Services\Campaigns\TrackingIngestAuthenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CampaignClickController extends Controller
{
    public function redirect(
        Request $request,
        string $campaign,
        string $item,
        TrackingIngestAuthenticator $authenticator,
        CampaignIpGeoResolver $geoResolver,
    ): Response {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'isp_id' => ['nullable', 'integer', 'min:0'],
            'server_id' => ['nullable', 'string', 'max:128'],
            'ip_id' => ['nullable', 'string', 'max:128'],
            'domain_id' => ['nullable', 'string', 'max:128'],
            'from_id' => ['nullable', 'string', 'max:128'],
            'subject_id' => ['nullable', 'string', 'max:128'],
            'creative_id' => ['nullable', 'string', 'max:128'],
        ]);

        $campaignModel = Campaign::query()->whereKey($campaign)->firstOrFail();
        $workspace = Workspace::query()->whereKey($campaignModel->workspace_id)->firstOrFail();
        $itemModel = CampaignRecipientSnapshotItem::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($item)
            ->firstOrFail();
        $snapshot = CampaignRecipientSnapshot::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('campaign_id', $campaignModel->getKey())
            ->whereKey($itemModel->snapshot_id)
            ->firstOrFail();
        $callbackKey = $authenticator->expectedKey($workspace);
        abort_if($callbackKey === null, 503, 'Campaign tracking is not configured.');
        $callbackRequest = Request::create('/api/v1/campaign', 'POST', [
            'key' => $callbackKey,
            'campaign_id' => (string) $campaignModel->getKey(),
            'email_id' => (string) $itemModel->contact_id,
            'isp_id' => (int) ($data['isp_id'] ?? 0),
            'token' => $data['token'],
            'ip_address' => (string) ($request->ip() ?: '127.0.0.1'),
            'user_agent' => (string) $request->userAgent(),
            'type' => 'red',
        ]);
        $redirectData = app(CampaignRedirectController::class)
            ->store($callbackRequest, $authenticator, $geoResolver);
        if (! $redirectData->isSuccessful()) {
            return response($redirectData->getContent(), $redirectData->getStatusCode(), [
                'Content-Type' => (string) $redirectData->headers->get('Content-Type', 'application/json'),
                'Cache-Control' => 'no-store, no-cache, max-age=0',
            ]);
        }

        $destination = (string) ($redirectData->getData(true)['link'] ?? 'https://www.google.com');

        if ($request->isMethod('GET') && (string) $itemModel->contact_id !== '0') {
            ProcessCampaignTrackingEventJob::dispatch($campaignModel, $workspace, [
                'tracking_event_id' => (string) Str::uuid(),
                'email_id' => (string) $itemModel->contact_id,
                'snapshot_item_id' => (string) $itemModel->getKey(),
                'send_id' => (string) $snapshot->getKey(),
                'list_id' => (string) $itemModel->source_list_id,
                'type' => (string) $itemModel->source_email_type,
                'isp_id' => (int) ($data['isp_id'] ?? 0),
                'action' => 'click',
                'ip_address' => (string) ($request->ip() ?: '127.0.0.1'),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 4096),
                'server_id' => (string) ($data['server_id'] ?? '0'),
                'ip_id' => (string) ($data['ip_id'] ?? '0'),
                'domain_id' => (string) ($data['domain_id'] ?? '0'),
                'from_id' => (string) ($data['from_id'] ?? '0'),
                'subject_id' => (string) ($data['subject_id'] ?? '0'),
                'creative_id' => (string) ($data['creative_id'] ?? '0'),
                'token' => $data['token'],
            ]);
        }

        return redirect()->away($destination)
            ->header('Cache-Control', 'no-store, no-cache, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
