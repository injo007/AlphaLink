<?php

namespace App\Services\Campaigns;

use App\Models\AudienceMixSnapshot;
use App\Models\ContactListMembership;
use App\Models\ManagedServer;
use App\Models\ManagedServerIp;
use App\Models\ManagedServerMta;
use App\Models\SendingDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceCampaignNegative;
use App\Models\WorkspaceOffer;
use App\Models\WorkspaceOfferAsset;
use App\Models\WorkspaceSponsor;
use App\Services\PmtaVirtualMtaConfigBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DmsCampaignSendCatalog
{
    public function __construct(
        private readonly DmsOfferSuppressionFile $suppressionFiles,
        private readonly PmtaVirtualMtaConfigBuilder $virtualMtaConfigBuilder,
    ) {}

    /**
     * Load the source-mapped choices for DMS Campaigns → Send.
     *
     * DMS exposes sponsor, offer, ISP, list, installed server, and IP/MTA
     * selectors before campaign creation. This catalog returns only AlphaLink
     * records in the active workspace and applies the same access and readiness
     * filters where AlphaLink has an equivalent field.
     *
     * @param  array{sponsor_id?: int|null, offer_country?: string|null, isp_id?: int|null, country?: string|null, server_ids?: list<string>, route?: string|null, ip_type?: string|null}  $filters
     * @return array<string, mixed>
     */
    public function options(Workspace $workspace, User $user, string $role, array $filters = []): array
    {
        $operator = $role === 'operator';
        $sponsorId = $filters['sponsor_id'] ?? null;
        $offerCountry = strtoupper((string) ($filters['offer_country'] ?? 'ALL'));
        $ispId = $filters['isp_id'] ?? null;
        $listCountry = strtoupper((string) ($filters['country'] ?? 'ALL'));
        $selectedServerIds = array_values(array_unique($filters['server_ids'] ?? []));
        $route = $filters['route'] ?? 'ip';
        $ipType = strtolower((string) ($filters['ip_type'] ?? 'all'));
        // DMS loads only the current user's negative files on Campaign → Send.
        $negatives = WorkspaceCampaignNegative::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('created_by', $user->getKey())
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name']);

        $sponsors = $workspace->sponsors()
            ->where('is_active', true)
            ->whereHas('offers')
            ->when($operator, fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $user->getKey()))
            ->orderBy('name')
            ->get(['id', 'name']);
        $selectedSponsorIsAvailable = $sponsorId !== null
            && $sponsors->contains(fn (WorkspaceSponsor $sponsor): bool => (int) $sponsor->getKey() === (int) $sponsorId);

        $offers = WorkspaceOffer::query()
            ->where('workspace_id', $workspace->getKey())
            ->when(! $selectedSponsorIsAvailable, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('status', 'live')
            ->where('is_active', true)
            ->whereJsonContains('allowed_days', now()->format('D'))
            ->when($sponsorId !== null, fn (Builder $query) => $query->where('sponsor_id', $sponsorId))
            ->whereHas('sponsor', function (Builder $query) use ($workspace, $operator, $user): void {
                $query->where('workspace_id', $workspace->getKey())
                    ->where('is_active', true)
                    ->when($operator, fn (Builder $sponsors) => $sponsors->whereJsonContains('allowed_users', (int) $user->getKey()));
            })
            ->when($operator, fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $user->getKey()))
            ->when($offerCountry !== 'ALL', fn (Builder $query) => $query->whereJsonContains('geo_target', $offerCountry))
            ->with(['assets' => fn (HasMany $query) => $query->where('is_active', true)->orderBy('type')->orderBy('id')])
            ->orderBy('name')
            ->get(['id', 'sponsor_id', 'offer_id', 'name', 'type', 'vertical', 'geo_target', 'allowed_days', 'suppression_path', 'suppression_sha256', 'suppression_format', 'suppression_updated_at'])
            ->filter(fn (WorkspaceOffer $offer): bool => $this->suppressionFiles->hasFreshStoredReference($offer))
            ->values();

        $isps = $workspace->isps()
            ->where('is_active', true)
            ->whereHas('lists')
            ->when(! $selectedSponsorIsAvailable, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($operator, fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $user->getKey()))
            ->when($sponsorId !== null, fn (Builder $query) => $query->whereJsonContains('allowed_sponsors', (int) $sponsorId))
            ->orderBy('name')
            ->get(['id', 'name', 'tracked_countries']);
        $selectedIspIsAvailable = $ispId !== null
            && $isps->contains(fn ($isp): bool => (int) $isp->getKey() === (int) $ispId);

        $lists = $workspace->audienceLists()
            ->where('status', 'active')
            ->when(! $selectedSponsorIsAvailable || ! $selectedIspIsAvailable, fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($ispId !== null, fn (Builder $query) => $query->where('isp_id', $ispId))
            ->when($listCountry !== 'ALL', fn (Builder $query) => $query->where('country', $listCountry))
            ->when($sponsorId !== null, fn (Builder $query) => $query->whereJsonContains('allowed_sponsors', (int) $sponsorId))
            ->when($operator, fn (Builder $query) => $query->whereJsonContains('allowed_users', (int) $user->getKey()))
            ->whereHas('memberships', fn (Builder $query) => $query->where('status', 'active'))
            ->withCount(['memberships as active_members_count' => fn (Builder $query) => $query->where('status', 'active')])
            ->orderBy('name')
            ->get(['id', 'isp_id', 'name', 'country', 'is_fresh_locked', 'is_delivery_locked', 'is_open_locked', 'is_click_locked', 'is_lead_locked', 'is_unsubscribe_locked']);

        $segmentCounts = $lists->isEmpty()
            ? collect()
            : DB::table('contact_list_memberships')
                ->where('workspace_id', $workspace->getKey())
                ->whereIn('audience_list_id', $lists->pluck('id'))
                ->where('status', 'active')
                ->whereIn('email_type', ContactListMembership::CAMPAIGN_TYPES)
                ->select(['audience_list_id', 'email_type'])
                ->selectRaw('COUNT(*) as recipient_count')
                ->groupBy(['audience_list_id', 'email_type'])
                ->get()
                ->keyBy(fn ($row): string => $row->audience_list_id.'_'.$row->email_type);

        $mixSegments = $ispId === null || ! $selectedIspIsAvailable
            ? collect()
            : AudienceMixSnapshot::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('created_by', $user->getKey())
                ->where('isp_id', $ispId)
                ->where('status', 'active')
                ->where('created_at', '>=', now()->subDay()->endOfDay())
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'recipient_count', 'criteria'])
                ->map(fn (AudienceMixSnapshot $mix): array => [
                    'id' => $mix->getKey().'_mix',
                    'list_id' => null,
                    'list_ids' => array_values(array_map('strval', $mix->criteria['source_list_ids'] ?? [])),
                    'name' => $mix->name,
                    'country' => 'MIX',
                    'email_type' => 'mix',
                    'recipient_count' => (int) $mix->recipient_count,
                    'locked' => false,
                    'is_mix' => true,
                ])->values();

        $servers = $this->eligibleServers($workspace, $user, $operator)->get([
            'id', 'name', 'ip_address', 'country', 'install_parameters',
        ]);

        $redirectDomains = SendingDomain::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', 'redirect')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['name'])
            ->map(fn (SendingDomain $domain): array => [
                'value' => $domain->name,
                'name' => 'REDIRECT',
                'dns' => $domain->name,
            ]);

        $serverDomains = ManagedServerIp::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_active', true)
            ->whereRaw('LOWER(type) = ?', ['ipv4'])
            ->whereNotNull('dns')
            ->where('dns', '<>', '')
            ->whereHas('server', function (Builder $query) use ($workspace, $operator, $user): void {
                $query->where('workspace_id', $workspace->getKey())
                    ->where('is_active', true)
                    ->where('is_installed', true)
                    ->when($operator, fn (Builder $servers) => $servers->whereHas(
                        'allowedUsers',
                        fn (Builder $users) => $users->whereKey($user->getKey()),
                    ));
            })
            ->with('server:id,name')
            ->orderBy('managed_server_id')
            ->orderBy('id')
            ->get(['id', 'managed_server_id', 'dns'])
            ->map(fn (ManagedServerIp $ip): array => [
                'value' => $ip->dns,
                'name' => $ip->server?->name ?? 'SERVER',
                'dns' => $ip->dns,
            ]);

        $serverIds = $this->eligibleServers($workspace, $user, $operator)
            ->whereKey($selectedServerIds)
            ->pluck('id')
            ->all();

        $ips = collect();
        if ($route === 'ip') {
            $ips = ManagedServerIp::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('is_active', true)
                ->whereIn('managed_server_id', $serverIds)
                ->when($ipType !== 'all', fn (Builder $query) => $query->whereRaw('LOWER(type) = ?', [$ipType]))
                ->orderBy('managed_server_id')
                ->orderBy('id')
                ->get(['id', 'managed_server_id', 'sending_domain_id', 'ip', 'type', 'dns']);
        } elseif (in_array($route, ['vmta', 'smtp'], true)) {
            $ips = ManagedServerMta::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('is_active', true)
                ->where('mta_type', $route)
                ->whereIn('managed_server_id', $serverIds)
                ->when($ipType !== 'all', fn (Builder $query) => $query->whereRaw('LOWER(ip_type) = ?', [$ipType]))
                ->when($operator, fn (Builder $query) => $query->where('user_id', $user->getKey()))
                ->when($route === 'vmta', fn (Builder $query) => $query->whereHas('virtualMta', fn (Builder $vmtas) => $vmtas
                    ->where('workspace_id', $workspace->getKey())
                    ->where('is_active', true)))
                ->with('virtualMta:id,workspace_id,managed_server_id,managed_server_ip_id,ip,domain,duplicate_index,is_active')
                ->orderBy('managed_server_id')
                ->orderBy('id')
                ->get(['id', 'managed_server_id', 'managed_server_ip_id', 'ip', 'ip_type as type', 'dns', 'username', 'name', 'pmta_virtual_mta_id']);
        }

        $settings = $workspace->infrastructureSettings;
        $configuredAdminPort = $settings?->pmta_admin_port;
        $pmtaAdminPort = is_numeric($configuredAdminPort) && (int) $configuredAdminPort >= 1 && (int) $configuredAdminPort <= 65535
            ? (int) $configuredAdminPort
            : (int) config('infrastructure.pmta_admin_port', 1213);

        $vmtaDomains = $route === 'vmta'
            ? $ips->map(fn (ManagedServerMta $mta): ?array => filled($mta->virtualMta?->domain)
                ? [
                    'value' => trim((string) $mta->virtualMta->domain),
                    'name' => $mta->name ?: 'VMTA',
                    'dns' => trim((string) $mta->virtualMta->domain),
                ]
                : null)->filter()->values()
            : collect();

        return [
            'sponsors' => $sponsors->map(fn (WorkspaceSponsor $sponsor): array => [
                'id' => $sponsor->getKey(),
                'name' => $sponsor->name,
            ])->values(),
            'negatives' => $negatives->map(fn (WorkspaceCampaignNegative $negative): array => [
                'id' => $negative->getKey(),
                'name' => $negative->name,
            ])->values(),
            'offers' => $offers->map(fn (WorkspaceOffer $offer): array => [
                'id' => $offer->getKey(),
                'sponsor_id' => $offer->sponsor_id,
                'offer_id' => $offer->offer_id,
                'name' => $offer->name,
                'type' => $offer->type,
                'vertical' => $offer->vertical,
                'geo_target' => $offer->geo_target,
                'allowed_days' => $offer->allowed_days,
                'suppression_updated_at' => $offer->suppression_updated_at?->toDateString(),
                'assets' => $offer->assets->map(fn ($asset): array => [
                    'id' => $asset->getKey(),
                    'type' => $asset->type,
                    'value' => $asset->value,
                    'public_url' => in_array($asset->type, ['creative', 'unsubscribe'], true)
                        ? route('public.offer-assets.file', [$workspace->getKey(), $offer->getKey(), $asset->getKey()], true)
                        : null,
                ])->values(),
            ])->values(),
            'isps' => $isps->map(fn ($isp): array => [
                'id' => $isp->getKey(),
                'name' => $isp->name,
                'tracked_countries' => $isp->tracked_countries,
            ])->values(),
            'lists' => $lists->map(fn ($list): array => [
                'id' => $list->getKey(),
                'isp_id' => $list->isp_id,
                'name' => $list->name,
                'country' => $list->country,
                'active_members_count' => $list->active_members_count,
                'locks' => [
                    'fresh' => $list->is_fresh_locked,
                    'delivery' => $list->is_delivery_locked,
                    'open' => $list->is_open_locked,
                    'click' => $list->is_click_locked,
                    'lead' => $list->is_lead_locked,
                    'unsubscribe' => $list->is_unsubscribe_locked,
                ],
            ])->values(),
            'segments' => $lists->flatMap(function ($list) use ($segmentCounts): array {
                $locks = [
                    'fresh' => (bool) $list->is_fresh_locked,
                    'delivery' => (bool) $list->is_delivery_locked,
                    'open' => (bool) $list->is_open_locked,
                    'click' => (bool) $list->is_click_locked,
                    'lead' => (bool) $list->is_lead_locked,
                    'unsubscribe' => (bool) $list->is_unsubscribe_locked,
                ];

                return array_values(array_filter(array_map(function (string $type) use ($list, $segmentCounts, $locks): ?array {
                    $key = $list->getKey().'_'.$type;
                    $count = (int) ($segmentCounts->get($key)?->recipient_count ?? 0);
                    if ($count < 1 || $locks[$type]) {
                        return null;
                    }

                    return [
                        'id' => $key,
                        'list_id' => $list->getKey(),
                        'isp_id' => $list->isp_id,
                        'name' => $list->name,
                        'country' => $list->country,
                        'email_type' => $type,
                        'recipient_count' => $count,
                    ];
                }, ContactListMembership::CAMPAIGN_TYPES)));
            })->concat($mixSegments)->values(),
            'servers' => $servers->map(function (ManagedServer $server) use ($pmtaAdminPort): array {
                return [
                    'id' => $server->getKey(),
                    'name' => $server->name,
                    'ip' => $server->ip_address,
                    'country' => $server->country,
                    'pmta_panel_url' => PmtaPanelUrl::forServer($server, $pmtaAdminPort),
                ];
            })->values(),
            'campaign_domains' => $redirectDomains->concat($serverDomains)->concat($vmtaDomains)->unique('value')->values(),
            'ips' => $ips->map(fn ($ip): array => [
                'id' => $ip->getKey(),
                'server_id' => $ip->managed_server_id,
                'ip' => $ip->ip,
                'type' => $ip->type,
                'dns' => $ip->dns,
                'vmta_domain' => $route === 'vmta' ? ($ip->virtualMta?->domain ?? null) : null,
                'sending_domain_id' => $ip->sending_domain_id ?? null,
                'managed_server_ip_id' => $ip->managed_server_ip_id ?? null,
                'username' => $ip->username ?? null,
                'name' => $ip->name ?? ($route === 'vmta' ? ($ip->username ?? null) : null),
                'pmta_name' => $route === 'vmta' && $ip->virtualMta
                    ? $this->virtualMtaConfigBuilder->virtualMtaName(
                        (int) $ip->virtualMta->getKey(),
                        (string) $ip->virtualMta->ip,
                        (int) $ip->virtualMta->duplicate_index,
                    )
                    : null,
            ])->values(),
            'settings' => ['pmta_admin_port' => $pmtaAdminPort],
        ];
    }

    /**
     * Load the active assets for the offer already saved on a campaign.
     * DMS edit loads assets by the campaign's offer ID without applying the
     * current live-offer filters used by Create Campaign.
     *
     * @return list<array{id: int, type: string, value: string, public_url: string|null}>
     */
    public function savedOfferAssets(Workspace $workspace, ?int $offerId): array
    {
        if ($offerId === null || $offerId < 1) {
            return [];
        }

        $offer = WorkspaceOffer::withTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->whereKey($offerId)
            ->with(['assets' => fn (HasMany $query) => $query
                ->where('workspace_id', $workspace->getKey())
                ->where(fn (Builder $assets) => $assets->where('is_active', true)->orWhere('type', 'unsubscribe'))
                ->orderBy('type')
                ->orderBy('id')])
            ->first();

        if (! $offer) {
            return [];
        }

        return $offer->assets->map(fn (WorkspaceOfferAsset $asset): array => [
            'id' => $asset->getKey(),
            'type' => $asset->type,
            'value' => $asset->value,
            'public_url' => in_array($asset->type, ['creative', 'unsubscribe'], true)
                ? route('public.offer-assets.file', [$workspace->getKey(), $offer->getKey(), $asset->getKey()], true)
                : null,
        ])->values()->all();
    }

    /**
     * Validate the editable asset controls against the campaign's saved offer.
     * Existing revisions keep their original targeting eligibility; Edit does
     * not expose Sponsor, Offer, ISP, or List selectors in DMS.
     *
     * @param  list<int|string>  $fromAssetIds
     * @param  list<int|string>  $subjectAssetIds
     * @return array{from_asset_ids: list<int>, subject_asset_ids: list<int>, creative_asset_id: int|null, html_asset_id: int|null}
     */
    public function validateRevisionOfferAssets(
        Workspace $workspace,
        int $offerId,
        array $fromAssetIds,
        array $subjectAssetIds,
        mixed $creativeAssetId,
        mixed $htmlAssetId,
    ): array {
        $assets = collect($this->savedOfferAssets($workspace, $offerId))->keyBy('id')->all();
        $fromIds = array_values(array_unique(array_map('intval', $fromAssetIds)));
        $subjectIds = array_values(array_unique(array_map('intval', $subjectAssetIds)));

        if (! $this->containsOfferAssetIds($assets, $fromIds, 'from')) {
            throw ValidationException::withMessages(['send_setup.from_asset_ids' => 'Choose active From values from the campaign’s saved offer.']);
        }
        if (! $this->containsOfferAssetIds($assets, $subjectIds, 'subject')) {
            throw ValidationException::withMessages(['send_setup.subject_asset_ids' => 'Choose active Subjects from the campaign’s saved offer.']);
        }

        return [
            'from_asset_ids' => $fromIds,
            'subject_asset_ids' => $subjectIds,
            'creative_asset_id' => $this->validatedOfferAssetId($assets, $creativeAssetId, 'creative', 'send_setup.creative_asset_id'),
            'html_asset_id' => $this->validatedOfferAssetId($assets, $htmlAssetId, 'html', 'send_setup.html_asset_id'),
        ];
    }

    /**
     * Validate only the routing controls exposed on Edit Campaign.
     *
     * @param  list<string>  $serverIds
     * @param  list<int|string>  $ipIds
     * @return array{route_option: string, ip_type: string, server_ids: list<string>, ip_ids: list<string>}
     */
    public function validateRevisionRouting(
        Workspace $workspace,
        User $user,
        string $role,
        string $route,
        string $ipType,
        array $serverIds,
        array $ipIds,
    ): array {
        $options = $this->options($workspace, $user, $role, [
            'server_ids' => array_values(array_map('strval', $serverIds)),
            'route' => $route,
            'ip_type' => $ipType,
        ]);
        $normalizedServerIds = array_values(array_unique(array_map('strval', $serverIds)));
        $normalizedIpIds = array_values(array_unique(array_map('strval', $ipIds)));

        if (! $this->containsAllIds($options['servers'], $normalizedServerIds)) {
            throw ValidationException::withMessages(['send_setup.server_ids' => 'Choose active installed servers available to this user.']);
        }
        if (! $this->containsAllIds($options['ips'], $normalizedIpIds)) {
            throw ValidationException::withMessages(['send_setup.ip_ids' => 'Choose at least one active IP or MTA from the Selected IPs list before saving. Test Campaign does not require a separate IP selection; it uses the current Header and Email Test addresses with the selected server inventory.']);
        }

        return [
            'route_option' => $route,
            'ip_type' => $ipType,
            'server_ids' => $normalizedServerIds,
            'ip_ids' => $normalizedIpIds,
        ];
    }

    /**
     * Resolve the saved Domain for an Edit revision after its route controls
     * have been validated. VMTA campaigns use the pushed VMTA's domain; IP and
     * SMTP campaigns retain the operator-selected catalog domain.
     *
     * @param  list<string>  $serverIds
     * @param  list<int|string>  $ipIds
     */
    public function validateRevisionDomain(
        Workspace $workspace,
        User $user,
        string $role,
        string $route,
        string $ipType,
        array $serverIds,
        array $ipIds,
        ?string $submittedDomain,
    ): ?string {
        $options = $this->options($workspace, $user, $role, [
            'server_ids' => array_values(array_map('strval', $serverIds)),
            'route' => $route,
            'ip_type' => $ipType,
        ]);
        $normalizedServerIds = array_values(array_unique(array_map('strval', $serverIds)));
        $normalizedIpIds = array_values(array_unique(array_map('strval', $ipIds)));

        if (! $this->containsAllIds($options['servers'], $normalizedServerIds)) {
            throw ValidationException::withMessages(['send_setup.server_ids' => 'Choose active installed servers available to this user.']);
        }
        if (! $this->containsAllIds($options['ips'], $normalizedIpIds)) {
            throw ValidationException::withMessages(['send_setup.ip_ids' => 'Choose at least one active IP or MTA from the Selected IPs list before saving. Test Campaign does not require a separate IP selection; it uses the current Header and Email Test addresses with the selected server inventory.']);
        }

        if ($route === 'vmta') {
            return $this->selectedVmtaDomain($options['ips'], $normalizedIpIds);
        }

        $domain = trim((string) $submittedDomain);
        if ($domain !== '' && ! collect($options['campaign_domains'])->contains(fn (array $option): bool => hash_equals((string) $option['value'], $domain))) {
            throw ValidationException::withMessages(['domain' => 'Choose an active redirect domain or an active IPv4 domain on an installed server available to this workspace.']);
        }

        return $domain !== '' ? $domain : null;
    }

    /**
     * Confirm every selected campaign dependency against the same scoped catalog used by the UI.
     *
     * @param  array<string, mixed>  $selection
     * @return array<string, mixed>
     */
    public function validateSelection(Workspace $workspace, User $user, string $role, array $selection): array
    {
        $options = $this->options($workspace, $user, $role, [
            'sponsor_id' => (int) $selection['sponsor_id'],
            'offer_country' => (string) $selection['offer_country'],
            'isp_id' => (int) $selection['isp_id'],
            'country' => (string) $selection['country'],
            'server_ids' => array_map('strval', $selection['server_ids']),
            'route' => (string) $selection['route_option'],
            'ip_type' => (string) $selection['ip_type'],
        ]);

        $selectedSegments = array_values(array_unique(array_map('strval', $selection['audience_segments'])));
        $availableSegments = collect($options['segments'])->keyBy('id');
        $selectedOffer = collect($options['offers'])->first(fn (array $offer): bool => (string) $offer['id'] === (string) $selection['offer_id']);
        $availableOfferAssets = collect($selectedOffer['assets'] ?? [])->keyBy('id')->all();
        $fromAssetIds = array_values(array_unique(array_map('intval', $selection['from_asset_ids'] ?? [])));
        $subjectAssetIds = array_values(array_unique(array_map('intval', $selection['subject_asset_ids'] ?? [])));
        $creativeAssetId = filled($selection['creative_asset_id'] ?? null) ? (int) $selection['creative_asset_id'] : null;
        $htmlAssetId = filled($selection['html_asset_id'] ?? null) ? (int) $selection['html_asset_id'] : null;
        $selectedListIds = [];
        foreach ($selectedSegments as $segmentId) {
            $segment = $availableSegments->get($segmentId);
            if (! $segment) {
                throw ValidationException::withMessages(['send_setup.audience_segments' => 'One or more selected DMS list segments are locked, empty, inactive, or unavailable.']);
            }
            $listIds = $segment['list_ids'] ?? [$segment['list_id'] ?? null];
            foreach ($listIds as $listId) {
                if (filled($listId)) {
                    $selectedListIds[] = (string) $listId;
                }
            }
        }
        $selectedListIds = array_values(array_unique($selectedListIds));
        $selectedServerIds = array_map('strval', $selection['server_ids']);
        $selectedIpIds = array_map('strval', $selection['ip_ids']);

        if (! $this->containsAllIds($options['servers'], $selectedServerIds)) {
            throw ValidationException::withMessages(['send_setup.server_ids' => 'One or more selected servers are not active, installed, or assigned to this user.']);
        }
        if (! $this->containsAllIds($options['ips'], $selectedIpIds)) {
            throw ValidationException::withMessages(['send_setup.ip_ids' => 'One or more selected IPs or MTAs are inactive, outside the selected servers, or unavailable to this user.']);
        }

        $selectedDomain = trim((string) ($selection['domain'] ?? ''));
        if ((string) $selection['route_option'] === 'vmta') {
            $selectedDomain = $this->selectedVmtaDomain($options['ips'], $selectedIpIds);
        }

        if (! $this->containsId($options['sponsors'], $selection['sponsor_id'])) {
            throw ValidationException::withMessages(['send_setup.sponsor_id' => 'Choose an active sponsor available to this workspace.']);
        }
        $negativeId = $this->validateNegativeSelection($workspace, $user, $selection['negative_id'] ?? null);
        if ($selectedDomain !== '' && ! collect($options['campaign_domains'])->contains(fn (array $domain): bool => hash_equals((string) $domain['value'], $selectedDomain))) {
            throw ValidationException::withMessages(['domain' => 'Choose an active redirect domain or an active IPv4 domain on an installed server available to this workspace.']);
        }
        if (! $this->containsId($options['offers'], $selection['offer_id'])) {
            throw ValidationException::withMessages(['send_setup.offer_id' => 'The offer is no longer eligible. Reload the send choices and select a current active offer.']);
        }
        if (! $this->containsOfferAssetIds($availableOfferAssets, $fromAssetIds, 'from')) {
            throw ValidationException::withMessages(['send_setup.from_asset_ids' => 'One or more selected From assets do not belong to the selected offer or are inactive.']);
        }
        if (! $this->containsOfferAssetIds($availableOfferAssets, $subjectAssetIds, 'subject')) {
            throw ValidationException::withMessages(['send_setup.subject_asset_ids' => 'One or more selected Subject assets do not belong to the selected offer or are inactive.']);
        }
        if ($creativeAssetId !== null && ! $this->containsOfferAssetIds($availableOfferAssets, [$creativeAssetId], 'creative')) {
            throw ValidationException::withMessages(['send_setup.creative_asset_id' => 'Choose an active creative image from the selected offer, or leave it empty.']);
        }
        if ($htmlAssetId !== null && ! $this->containsOfferAssetIds($availableOfferAssets, [$htmlAssetId], 'html')) {
            throw ValidationException::withMessages(['send_setup.html_asset_id' => 'Choose an active HTML template from the selected offer, or leave it empty.']);
        }
        if (! $this->containsId($options['isps'], $selection['isp_id'])) {
            throw ValidationException::withMessages(['send_setup.isp_id' => 'Choose an active ISP available to the selected sponsor.']);
        }

        return [
            'sponsor_id' => (int) $selection['sponsor_id'],
            'offer_id' => (int) $selection['offer_id'],
            'negative_id' => $negativeId,
            'from_asset_ids' => $fromAssetIds,
            'subject_asset_ids' => $subjectAssetIds,
            'creative_asset_id' => $creativeAssetId,
            'html_asset_id' => $htmlAssetId,
            'offer_country' => strtoupper((string) $selection['offer_country']),
            'isp_id' => (int) $selection['isp_id'],
            'country' => strtoupper((string) $selection['country']),
            'audience_list_ids' => $selectedListIds,
            'audience_segments' => $selectedSegments,
            'targeting_basis' => 'dms_email_type_segments',
            'order_by' => (string) $selection['order_by'],
            'start_from' => (int) $selection['start_from'],
            'route_option' => (string) $selection['route_option'],
            'ip_type' => (string) $selection['ip_type'],
            'server_ids' => $selectedServerIds,
            'ip_ids' => $selectedIpIds,
            'send_type' => (string) $selection['send_type'],
            'domain' => $selectedDomain !== '' ? $selectedDomain : null,
            'parameters' => [
                'total_send' => (int) $selection['total_send'],
                'fraction_ip' => (int) $selection['fraction_ip'],
                'sleep_email' => (int) $selection['sleep_email'],
                'sleep_ip' => (int) $selection['sleep_ip'],
                'seed_every' => (int) $selection['seed_every'],
                'bounce_limit' => (int) $selection['bounce_limit'],
                'duplicate_data' => (int) $selection['duplicate_data'],
            ],
        ];
    }

    public function validateNegativeSelection(Workspace $workspace, User $user, mixed $negativeId): ?int
    {
        if ($negativeId === null || $negativeId === '') {
            return null;
        }

        $id = filter_var($negativeId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $available = $id === false ? null : WorkspaceCampaignNegative::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('created_by', $user->getKey())
            ->whereKey($id)
            ->value('id');

        if ($available === null) {
            throw ValidationException::withMessages(['send_setup.negative_id' => 'Choose one of your uploaded negative files in this workspace, or leave the field empty.']);
        }

        return (int) $available;
    }

    /**
     * Validate the single creative and HTML asset selectors against the same
     * active, offer-scoped assets DMS loads for its campaign editor.
     *
     * @return array{creative_asset_id: int|null, html_asset_id: int|null}
     */
    public function validateOfferAssetSelection(
        Workspace $workspace,
        User $user,
        string $role,
        int $sponsorId,
        string $offerCountry,
        int $offerId,
        mixed $creativeAssetId,
        mixed $htmlAssetId,
    ): array {
        $options = $this->options($workspace, $user, $role, [
            'sponsor_id' => $sponsorId,
            'offer_country' => $offerCountry,
        ]);
        $offer = collect($options['offers'])->first(fn (array $candidate): bool => (int) $candidate['id'] === $offerId);
        if (! $offer) {
            throw ValidationException::withMessages(['send_setup.offer_id' => 'The selected offer is no longer eligible. Reload the send choices before changing its assets.']);
        }

        $assets = collect($offer['assets'])->keyBy('id')->all();

        return [
            'creative_asset_id' => $this->validatedOfferAssetId($assets, $creativeAssetId, 'creative', 'send_setup.creative_asset_id'),
            'html_asset_id' => $this->validatedOfferAssetId($assets, $htmlAssetId, 'html', 'send_setup.html_asset_id'),
        ];
    }

    private function eligibleServers(Workspace $workspace, User $user, bool $operator): HasMany
    {
        return $workspace->managedServers()
            ->where('is_active', true)
            ->where('is_installed', true)
            ->when($operator, fn (Builder $query) => $query->whereHas('allowedUsers', fn (Builder $users) => $users->whereKey($user->getKey())))
            ->orderBy('id');
    }

    /** @param iterable<array{id: int|string}> $records */
    private function containsId(iterable $records, int|string $id): bool
    {
        foreach ($records as $record) {
            if ((string) $record['id'] === (string) $id) {
                return true;
            }
        }

        return false;
    }

    /** @param iterable<array{id: int|string}> $records
     * @param  list<string>  $ids
     */
    private function containsAllIds(iterable $records, array $ids): bool
    {
        $available = [];
        foreach ($records as $record) {
            $available[(string) $record['id']] = true;
        }

        return $ids !== [] && array_diff($ids, array_keys($available)) === [];
    }

    /**
     * @param  iterable<array<string, mixed>>  $options
     * @param  list<string>  $selectedIpIds
     */
    private function selectedVmtaDomain(iterable $options, array $selectedIpIds): string
    {
        $availableVmtas = collect($options)->keyBy(fn (array $choice): string => (string) $choice['id']);
        $selectedVmtas = collect($selectedIpIds)->map(fn (string $id): ?array => $availableVmtas->get($id));

        if ($selectedVmtas->isEmpty() || $selectedVmtas->contains(fn (?array $choice): bool => ! $choice || blank($choice['vmta_domain'] ?? null))) {
            throw ValidationException::withMessages(['send_setup.ip_ids' => 'Every selected VMTA must have a configured domain before it can be used by a campaign.']);
        }

        return trim((string) $selectedVmtas->first()['vmta_domain']);
    }

    /**
     * @param  array<int|string, array{id: int|string, type: string}>  $assets
     * @param  list<int>  $ids
     */
    private function containsOfferAssetIds(array $assets, array $ids, string $type): bool
    {
        foreach ($ids as $id) {
            $asset = $assets[$id] ?? $assets[(string) $id] ?? null;
            if (! $asset || $asset['type'] !== $type) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int|string, array{id: int|string, type: string}>  $assets
     */
    private function validatedOfferAssetId(array $assets, mixed $assetId, string $type, string $field): ?int
    {
        if ($assetId === null || $assetId === '') {
            return null;
        }

        $id = filter_var($assetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || ! $this->containsOfferAssetIds($assets, [(int) $id], $type)) {
            throw ValidationException::withMessages([$field => 'Choose an active '.$type.' asset from the selected offer, or leave it empty.']);
        }

        return (int) $id;
    }
}
