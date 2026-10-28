<?php

namespace App\Services\Audiences;

use App\Models\AudienceList;
use App\Models\AudienceMixSnapshot;
use App\Models\ContactListMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSponsor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BuildAudienceMixSnapshot
{
    private const EMAIL_TYPES = ['fresh', 'delivery', 'open', 'click', 'lead', 'unsubscribe'];

    public function __construct(private readonly AudienceMembershipFilterIndexer $filterIndexer) {}

    /** @param array<string, mixed> $input */
    public function create(Workspace $workspace, User $user, array $input): ?AudienceMixSnapshot
    {
        $selected = $this->parseSelectedSegments($input['audience_segments']);
        $listIds = array_keys($selected);
        $role = (string) ($workspace->pivot?->role ?? 'member');
        $ispQuery = $workspace->isps()->where('is_active', true);
        if ($role === 'operator') {
            $ispQuery->whereJsonContains('allowed_users', (int) $user->getKey());
        }
        $isp = $ispQuery->whereKey($input['isp_id'])->first();
        if (! $isp) {
            throw ValidationException::withMessages(['isp_id' => 'Choose an active ISP available to this workspace.']);
        }

        $sponsorId = filled($input['sponsor_id'] ?? null) ? (int) $input['sponsor_id'] : null;
        if ($sponsorId !== null) {
            $sponsorQuery = WorkspaceSponsor::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('is_active', true)
                ->whereKey($sponsorId);
            if ($role === 'operator') {
                $sponsorQuery->whereJsonContains('allowed_users', (int) $user->getKey());
            }
            if (! $sponsorQuery->exists()) {
                throw ValidationException::withMessages(['sponsor_id' => 'Choose an active sponsor available to this workspace.']);
            }
        }

        $listsQuery = $workspace->audienceLists()
            ->where('isp_id', $isp->getKey())
            ->where('status', 'active')
            ->whereIn('id', $listIds);
        if (filled($input['country'] ?? null)) {
            $listsQuery->where('country', strtoupper((string) $input['country']));
        }
        if ($sponsorId !== null) {
            $listsQuery->whereJsonContains('allowed_sponsors', $sponsorId);
        }
        if ($role === 'operator') {
            $listsQuery->whereJsonContains('allowed_users', (int) $user->getKey());
        }
        $lists = $listsQuery->get()->keyBy(fn (AudienceList $list): string => (string) $list->getKey());
        if ($lists->count() !== count($listIds)) {
            throw ValidationException::withMessages(['audience_segments' => 'One or more selected lists are inactive or unavailable. Reload the list choices.']);
        }

        foreach ($selected as $listId => $types) {
            $list = $lists->get($listId);
            foreach ($types as $type) {
                if ((bool) $list->{'is_'.$type.'_locked'}) {
                    throw ValidationException::withMessages(['audience_segments' => 'A selected email type is locked on its list. Reload the list choices.']);
                }
            }
        }

        $criteria = [
            'isp_id' => (int) $isp->getKey(),
            'sponsor_id' => $sponsorId,
            'country' => filled($input['country'] ?? null) ? strtoupper((string) $input['country']) : null,
            'audience_segments' => $input['audience_segments'],
            'source_list_ids' => $listIds,
            'email_sex' => $input['email_sex'] ?? null,
            'email_device' => $input['email_device'] ?? null,
            'last_insert_between' => $input['last_insert_between'] ?? [],
            'extensions' => $input['extensions'] ?? [],
            'filters' => $input['filters'] ?? [],
            'activity_between' => $input['activity_between'] ?? [],
        ];
        $createdAt = now();
        $snapshot = null;
        $recipientCount = 0;
        $position = 0;

        DB::transaction(function () use ($workspace, $user, $isp, $selected, $criteria, $input, $createdAt, &$snapshot, &$recipientCount, &$position): void {
            $snapshot = AudienceMixSnapshot::query()->create([
                'workspace_id' => $workspace->getKey(),
                'created_by' => $user->getKey(),
                'isp_id' => $isp->getKey(),
                'name' => trim((string) ($input['name'] ?? '')) ?: 'Audience mix',
                'criteria' => $criteria,
                'recipient_count' => 0,
                'status' => 'active',
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            foreach ($selected as $listId => $types) {
                foreach ($types as $type) {
                    $query = ContactListMembership::query()
                        ->with('contact')
                        ->where('workspace_id', $workspace->getKey())
                        ->where('audience_list_id', $listId)
                        ->where('email_type', $type)
                        ->where('status', 'active')
                        ->whereHas('contact', fn ($contacts) => $contacts->where('status', 'active'))
                        ->when(count($criteria['last_insert_between']) === 2, fn ($memberships) => $memberships->whereBetween('joined_at', [
                            Carbon::parse($criteria['last_insert_between'][0])->startOfDay(),
                            Carbon::parse($criteria['last_insert_between'][1])->endOfDay(),
                        ]))
                        ->orderBy('id');

                    if (filled($criteria['email_sex']) && $this->filterIndexer->isReady()) {
                        $this->constrainToIndexedValues(
                            $query,
                            'profile_sex_index',
                            [$this->filterIndexer->sexIndex($workspace->getKey(), $listId, ['sex' => $criteria['email_sex']])],
                        );
                    }
                    if ($criteria['extensions'] !== [] && $this->filterIndexer->isReady()) {
                        $this->constrainToIndexedValues(
                            $query,
                            'email_domain_index',
                            array_map(
                                fn (string $extension): string => $this->filterIndexer->domainIndex(
                                    $workspace->getKey(),
                                    $listId,
                                    'address@'.$extension,
                                ),
                                $criteria['extensions'],
                            ),
                        );
                    }

                    $query->chunkById(500, function ($memberships) use ($workspace, $listId, $type, $criteria, $snapshot, $createdAt, &$recipientCount, &$position): void {
                        $contactIds = $memberships->pluck('contact_id')->map(fn ($id): string => (string) $id)->all();
                        if ($contactIds === []) {
                            return;
                        }

                        $deviceMatches = filled($criteria['email_device'])
                            ? array_fill_keys($this->matchingContactIds(
                                $workspace,
                                $listId,
                                $contactIds,
                                'device',
                                [(string) $criteria['email_device']],
                                [],
                                [],
                            ), true)
                            : [];
                        $filterMatches = [];
                        foreach ($criteria['filters'] as $index => $filter) {
                            $filterMatches[$index] = array_fill_keys($this->matchingContactIds(
                                $workspace,
                                $listId,
                                $contactIds,
                                $filter['field'],
                                $filter['values'],
                                $filter['actions'] ?? [],
                                $criteria['activity_between'],
                            ), true);
                        }

                        $rows = [];
                        foreach ($memberships as $membership) {
                            $contact = $membership->contact;
                            if (! $contact) {
                                continue;
                            }
                            $contactId = (string) $contact->getKey();
                            if (filled($criteria['email_device']) && ! isset($deviceMatches[$contactId])) {
                                continue;
                            }
                            $hasCurrentIndexes = $this->filterIndexer->isReady()
                                && (int) $membership->attribute_index_version === $this->filterIndexer->version();
                            if (filled($criteria['email_sex']) && ! $hasCurrentIndexes
                                && ! $this->matchesSex($membership->profile ?? $contact->profile ?? [], $criteria['email_sex'])) {
                                continue;
                            }
                            if ($criteria['extensions'] !== [] && ! $hasCurrentIndexes
                                && ! $this->matchesExtensions((string) $contact->email, $criteria['extensions'])) {
                                continue;
                            }

                            $matchesFilters = true;
                            foreach ($criteria['filters'] as $index => $filter) {
                                $found = isset($filterMatches[$index][$contactId]);
                                if (($filter['effect'] === 'include' && ! $found)
                                    || ($filter['effect'] === 'exclude' && $found)) {
                                    $matchesFilters = false;
                                    break;
                                }
                            }
                            if (! $matchesFilters) {
                                continue;
                            }

                            $position++;
                            $rows[] = [
                                'id' => (string) Str::uuid(),
                                'workspace_id' => $workspace->getKey(),
                                'snapshot_id' => $snapshot->getKey(),
                                'contact_id' => $contactId,
                                'source_list_id' => $listId,
                                'source_membership_id' => $membership->getKey(),
                                'email_type' => $type,
                                'position' => $position,
                                'created_at' => $createdAt,
                            ];
                        }

                        if ($rows !== []) {
                            DB::table('audience_mix_snapshot_members')->insert($rows);
                            $recipientCount += count($rows);
                        }
                    });
                }
            }

            if ($recipientCount === 0) {
                $snapshot->delete();

                return;
            }

            $snapshot->forceFill([
                'name' => $this->snapshotName($input['name'] ?? null, $user, $recipientCount),
                'recipient_count' => $recipientCount,
            ])->save();
        });

        return $recipientCount > 0 ? $snapshot : null;
    }

    /** @param array<string, mixed> $criteria */
    public function remix(Workspace $workspace, User $user, AudienceMixSnapshot $source): ?AudienceMixSnapshot
    {
        $criteria = $source->criteria;
        $criteria['name'] = null;

        return $this->create($workspace, $user, $criteria);
    }

    /** @param list<string> $segments
     * @return array<string, list<string>>
     */
    public function parseSelectedSegments(array $segments): array
    {
        $selected = [];
        foreach ($segments as $segment) {
            if (! is_string($segment) || ! preg_match('/^([0-9a-f-]{36})_(fresh|delivery|open|click|lead|unsubscribe)$/i', $segment, $matches)) {
                throw ValidationException::withMessages(['audience_segments' => 'One or more selected list email types are invalid.']);
            }
            $selected[$matches[1]][] = strtolower($matches[2]);
        }

        foreach ($selected as &$types) {
            $types = array_values(array_unique($types));
        }

        return $selected;
    }

    /** @param list<string> $contactIds
     * @param  list<string>  $values
     * @param  list<string>  $actions
     * @param  list<string>  $activityBetween
     * @return list<string>
     */
    private function matchingContactIds(
        Workspace $workspace,
        string $listId,
        array $contactIds,
        string $field,
        array $values,
        array $actions,
        array $activityBetween,
    ): array {
        if ($values === []) {
            return [];
        }

        $start = count($activityBetween) === 2 ? Carbon::parse($activityBetween[0])->startOfDay() : null;
        $end = count($activityBetween) === 2 ? Carbon::parse($activityBetween[1])->endOfDay() : null;
        $matched = [];
        $campaignEvents = DB::table('campaign_engagement_events as event')
            ->where('event.workspace_id', $workspace->getKey())
            ->where('event.audience_list_id', $listId)
            ->whereIn('event.contact_id', $contactIds)
            ->when($actions !== [], fn ($query) => $query->whereIn('event.action', $actions))
            ->when($start && $end, fn ($query) => $query->whereBetween('event.created_at', [$start, $end]));
        $this->applyEventDimension($campaignEvents, $field, $values, 'campaign');
        foreach ($campaignEvents->pluck('event.contact_id') as $contactId) {
            $matched[(string) $contactId] = true;
        }

        $dropEvents = DB::table('workspace_drop_visitors as event')
            ->where('event.workspace_id', $workspace->getKey())
            ->where('event.list_id', $listId)
            ->whereIn('event.email_id', $contactIds)
            ->when($actions !== [], fn ($query) => $query->whereIn('event.type', $actions))
            ->when($start && $end, fn ($query) => $query->whereBetween('event.created_at', [$start, $end]));
        $this->applyEventDimension($dropEvents, $field, $values, 'drop');
        foreach ($dropEvents->pluck('event.email_id') as $contactId) {
            $matched[(string) $contactId] = true;
        }

        if ($field !== 'device' && ($actions === [] || in_array('lead', $actions, true))) {
            $leadConversions = DB::table('campaign_lead_conversions as event')
                ->where('event.workspace_id', $workspace->getKey())
                ->where('event.audience_list_id', $listId)
                ->whereIn('event.contact_id', $contactIds)
                ->when($start && $end, fn ($query) => $query->whereBetween('event.converted_at', [$start, $end]));
            $this->applyEventDimension($leadConversions, $field, $values, 'lead');
            foreach ($leadConversions->pluck('event.contact_id') as $contactId) {
                $matched[(string) $contactId] = true;
            }
        }

        return array_keys($matched);
    }

    /** @param list<string> $values */
    private function applyEventDimension($query, string $field, array $values, string $source): void
    {
        if ($field === 'country') {
            $query->whereIn('event.country', $values);

            return;
        }

        if ($field === 'device') {
            $query->whereIn('event.device', $values);

            return;
        }

        if ($source === 'campaign') {
            $query->join('campaign_recipient_snapshots as mix_snapshot', function ($join): void {
                $join->on('mix_snapshot.id', '=', 'event.recipient_snapshot_id')
                    ->on('mix_snapshot.workspace_id', '=', 'event.workspace_id');
            });
            $offerColumn = 'mix_snapshot.offer_id';
        } elseif ($source === 'drop') {
            $query->join('workspace_drops as mix_drop', function ($join): void {
                $join->on('mix_drop.id', '=', 'event.workspace_drop_id')
                    ->on('mix_drop.workspace_id', '=', 'event.workspace_id');
            });
            $offerColumn = 'mix_drop.offer_id';
        } else {
            $offerColumn = 'event.offer_id';
        }

        if ($field === 'offer_id') {
            $query->whereIn($offerColumn, $values);

            return;
        }

        $query->join('workspace_offers as mix_offer', function ($join) use ($offerColumn): void {
            $join->on('mix_offer.id', '=', $offerColumn)
                ->on('mix_offer.workspace_id', '=', 'event.workspace_id');
        })->whereIn('mix_offer.vertical', $values);
    }

    /** @param array<string, mixed>|null $profile */
    private function matchesSex(?array $profile, ?string $sex): bool
    {
        if (! filled($sex)) {
            return true;
        }

        $storedSex = strtolower((string) ($profile['sex'] ?? $profile['gender'] ?? ''));

        return $storedSex === strtolower($sex);
    }

    /** @param list<string> $extensions */
    private function matchesExtensions(string $email, array $extensions): bool
    {
        if ($extensions === []) {
            return true;
        }
        $domain = strtolower((string) str($email)->afterLast('@'));

        return in_array($domain, array_map('strtolower', $extensions), true);
    }

    /** @param list<string> $indexes */
    private function constrainToIndexedValues(Builder $query, string $column, array $indexes): void
    {
        $version = $this->filterIndexer->version();
        $query->where(function (Builder $matches) use ($column, $indexes, $version): void {
            $matches->where(function (Builder $current) use ($column, $indexes, $version): void {
                $current->where('attribute_index_version', $version)->whereIn($column, $indexes);
            })->orWhere(function (Builder $legacy) use ($version): void {
                $legacy->whereNull('attribute_index_version')
                    ->orWhere('attribute_index_version', '<>', $version);
            });
        });
    }

    private function snapshotName(mixed $name, User $user, int $count): string
    {
        $friendlyName = trim((string) $name);

        return mb_substr(sprintf('mix_%s_%s', $friendlyName !== '' ? $friendlyName : ($user->username ?: $user->getKey()), $count), 0, 100);
    }
}
