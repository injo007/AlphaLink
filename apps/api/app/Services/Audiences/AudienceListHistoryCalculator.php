<?php

namespace App\Services\Audiences;

use App\Models\AudienceList;
use App\Models\AudienceListHistory;
use App\Models\ContactListMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AudienceListHistoryCalculator
{
    public function calculate(AudienceList $list, ?Carbon $at = null): AudienceListHistory
    {
        $counts = array_fill_keys([
            ...ContactListMembership::CAMPAIGN_TYPES,
            ...ContactListMembership::HISTORY_TRACKED_TYPES,
        ], 0);

        DB::table('contact_list_memberships')
            ->select('email_type')
            ->selectRaw('COUNT(*) as aggregate')
            ->where('workspace_id', $list->workspace_id)
            ->where('audience_list_id', $list->getKey())
            ->groupBy('email_type')
            ->get()
            ->each(function (object $row) use (&$counts): void {
                if (array_key_exists($row->email_type, $counts)) {
                    $counts[$row->email_type] = (int) $row->aggregate;
                }
            });

        return AudienceListHistory::query()->updateOrCreate(
            [
                'workspace_id' => $list->workspace_id,
                'audience_list_id' => $list->getKey(),
                'at' => ($at ?? now())->toDateString(),
            ],
            $counts,
        );
    }

    public function calculateAll(): int
    {
        $calculated = 0;

        AudienceList::query()->select(['id', 'workspace_id'])->orderBy('id')->lazy()->each(function (AudienceList $list) use (&$calculated): void {
            $this->calculate($list);
            $calculated++;
        });

        return $calculated;
    }
}
