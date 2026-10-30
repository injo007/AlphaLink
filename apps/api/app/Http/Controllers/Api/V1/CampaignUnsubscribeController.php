<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignRecipientSnapshotItem;
use App\Models\CampaignRevision;
use App\Models\Workspace;
use App\Services\Campaigns\ProcessCampaignTrackingEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CampaignUnsubscribeController extends Controller
{
    public function unsubscribe(Request $request, string $campaign, string $item, ProcessCampaignTrackingEvent $processor): Response
    {
        if (! $request->isMethod('post')) {
            $action = e($request->fullUrl());
            $html = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Unsubscribe</title><body style="font:16px system-ui;max-width:36rem;margin:10vh auto;padding:1rem"><h1>Unsubscribe from this offer</h1><p>Confirm to stop receiving this offer by email.</p><form method="post" action="'.$action.'"><button type="submit" style="padding:.7rem 1.2rem">Unsubscribe</button></form></body></html>';

            return response($html)->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $campaignModel = Campaign::query()->whereKey($campaign)->firstOrFail();
        $itemModel = CampaignRecipientSnapshotItem::query()
            ->where('workspace_id', $campaignModel->workspace_id)
            ->whereKey($item)
            ->firstOrFail();
        $snapshot = CampaignRecipientSnapshot::query()
            ->where('workspace_id', $campaignModel->workspace_id)
            ->where('campaign_id', $campaignModel->getKey())
            ->whereKey($itemModel->snapshot_id)
            ->firstOrFail();
        $revision = CampaignRevision::query()->whereKey($snapshot->revision_id)->firstOrFail();
        $workspace = Workspace::query()->whereKey($campaignModel->workspace_id)->firstOrFail();

        $processor->ingest($campaignModel, $workspace, [
            'tracking_event_id' => (string) Str::uuid(),
            'email_id' => (string) $itemModel->contact_id,
            'snapshot_item_id' => (string) $itemModel->getKey(),
            'send_id' => (string) $snapshot->getKey(),
            'list_id' => (string) $itemModel->source_list_id,
            'type' => (string) $itemModel->source_email_type,
            'isp_id' => (int) data_get($revision->payload, 'send_setup.isp_id', 0),
            'action' => 'unsubscribe',
            'ip_address' => (string) ($request->ip() ?: '127.0.0.1'),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 4096),
            'server_id' => '0',
            'ip_id' => '0',
            'domain_id' => '0',
            'from_id' => '0',
            'subject_id' => '0',
            'creative_id' => '0',
            'token' => (string) $request->query('signature', 'signed-unsubscribe'),
        ]);

        return response('You are unsubscribed from this offer.', 200)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
