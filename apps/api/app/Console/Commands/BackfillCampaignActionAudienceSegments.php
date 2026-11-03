<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Services\Campaigns\BackfillCampaignActionAudienceSegments as BackfillActionSegments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('workspace:campaign-action-segments {campaign : Campaign UUID whose existing tracked actions should be materialized as Lists}')]
#[Description('Create missing tracked campaign action Lists from stored engagement and lead events')]
class BackfillCampaignActionAudienceSegments extends Command
{
    public function handle(BackfillActionSegments $backfill): int
    {
        $campaignId = (string) $this->argument('campaign');
        $validator = Validator::make(['campaign' => $campaignId], ['campaign' => ['required', 'uuid']]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $campaign = Campaign::query()->whereKey($campaignId)->first();
        if (! $campaign) {
            $this->error('The campaign was not found.');

            return self::FAILURE;
        }

        $counts = $backfill->handle($campaign);
        $this->info(sprintf(
            'Checked %d tracked engagement sources and %d campaign leads; reconciled %d action-list memberships.',
            $counts['event_sources'],
            $counts['lead_conversions'],
            $counts['action_segments'],
        ));

        return self::SUCCESS;
    }
}
