<?php

namespace App\Services\Campaigns;

use App\Models\CampaignRecipientSnapshot;
use App\Models\CampaignSendRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinalizeCampaignSendRun
{
    private const TERMINAL_CLAIM_STATES = ['accepted', 'rejected', 'suppressed', 'uncertain'];

    public function reconcile(CampaignSendRun $run, CampaignRecipientSnapshot $snapshot, Carbon $at): bool
    {
        $executionOptions = $run->execution_options ?? [];
        $endPosition = min((int) $snapshot->recipient_count, (int) ($executionOptions['end_position'] ?? $snapshot->recipient_count));

        if (! in_array($run->state, ['planned', 'running'], true)
            || (int) $run->next_position <= $endPosition) {
            return false;
        }

        $hasPendingClaims = DB::table('campaign_send_recipient_claims')
            ->where('send_run_id', $run->getKey())
            ->whereNotIn('state', self::TERMINAL_CLAIM_STATES)
            ->exists();
        if ($hasPendingClaims) {
            return false;
        }

        $run->state = (int) $run->uncertain_count > 0 ? 'needs_review' : 'completed';
        $run->completed_at = $at;
        $run->save();

        return true;
    }
}
