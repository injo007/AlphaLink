<!doctype html>
<html lang="en">
<head>
    @php
        $routeIs = static function (string ...$patterns): bool {
            return request()->routeIs(...$patterns)
                || request()->routeIs(...array_map(static fn (string $pattern): string => 'clean.'.$pattern, $patterns));
        };
        $isDropVisitorsPage = $routeIs('console.drops.visitors');
        $usesGoogleCharts = $isDropVisitorsPage || $routeIs('console.campaigns.visitors');
        $usesAnalyticsCharts = $routeIs('console.analytics.*', 'console.dashboard.main', 'console.campaign-statistics.*');
        $usesAnalyticsPivot = $routeIs(
            'console.analytics.all-stats',
            'console.analytics.mailer-revenue',
            'console.campaign-statistics.index',
        );
        $googleChartScriptSources = $usesGoogleCharts ? ' https://www.gstatic.com https://www.google.com' : '';
        $googleChartStyleSources = $usesGoogleCharts ? ' https://www.gstatic.com' : '';
        $googleChartConnectSources = $usesGoogleCharts ? ' https://www.gstatic.com https://www.google.com https://maps.googleapis.com' : '';
        $googleChartImageSources = $usesGoogleCharts ? ' https://www.gstatic.com https://www.google.com' : '';
        $analyticsPivotSources = $usesAnalyticsPivot ? ' https://cdn3.devexpress.com' : '';
    @endphp
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self' data:; script-src 'self' 'unsafe-inline' 'unsafe-eval'{{ $googleChartScriptSources }}{{ $analyticsPivotSources }}; style-src 'self' 'unsafe-inline'{{ $googleChartStyleSources }}{{ $analyticsPivotSources }}; img-src 'self' data:{{ $googleChartImageSources }}; font-src 'self' data:; connect-src 'self'{{ $googleChartConnectSources }}; form-action 'self'; frame-src 'self' {{ implode(' ', $pmtaFrameSources ?? []) }}; object-src 'none'; base-uri 'self'">
    <title>@yield('title', 'Servers - DMS')</title>
    <link rel="stylesheet" href="{{ asset('dms-reference/fonts/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('dms-reference/plugins/bootstrap_select/bootstrap-select.css') }}">
    @if ($routeIs('console.infrastructure.servers.index', 'console.infrastructure.server-providers.index', 'console.infrastructure.my-ips', 'console.my-servers.index', 'console.infrastructure.isps.index', 'console.infrastructure.lists.*', 'console.infrastructure.emails.*', 'console.infrastructure.blacklist-checker', 'console.infrastructure.domains.index', 'console.infrastructure.domains.records', 'console.infrastructure.domain-providers.index', 'console.infrastructure.cloud-providers.index', 'console.pmta-admin.history', 'console.pmta-config-history.*', 'console.pmta-scheduler.*', 'console.pmta-vmta.*', 'console.pmta-smtp.*', 'console.miscellaneous.subscribers', 'console.miscellaneous.contacts', 'console.sponsors.index', 'console.networks.sponsors.index', 'console.offers.*', 'console.networks.offers.index', 'console.drops.monitoring', 'console.drops.visitors', 'console.drops.suppression*', 'console.warmups.seeds.*', 'console.warmups.seed-emails.*', 'console.global-users.index', 'console.tools.spf.*', 'console.gcampaigns.projects', 'console.gcampaigns.accounts', 'console.campaigns.visitors'))
        <link rel="stylesheet" href="{{ asset('dms-reference/plugins/datatables/datatables.min.css') }}">
    @endif
    <link rel="stylesheet" href="{{ asset('dms-reference/assets/css/theme.css') }}">
    @if ($usesAnalyticsCharts)
        @vite('resources/css/analytics-charts.css')
    @endif
    @stack('styles')
    <style>
        .alphalink-toast-stack { bottom: 1rem; display: flex; flex-direction: column; gap: .5rem; max-width: min(28rem, calc(100vw - 2rem)); position: fixed; right: 1rem; z-index: 1200; }
        .alphalink-toast { box-shadow: 0 .4rem 1.25rem rgba(0, 0, 0, .2); margin: 0; }
        @media (prefers-reduced-motion: no-preference) { .alphalink-toast { animation: alphalink-toast-in .16s ease-out; } }
        @keyframes alphalink-toast-in { from { opacity: 0; transform: translateY(.4rem); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    @php
        $serversUrl = route('clean.console.infrastructure.servers.index');
        $serverProvidersUrl = route('clean.console.infrastructure.server-providers.index');
        $serverInstallUrl = route('clean.console.infrastructure.install.index');
        $bulkInstallUrl = route('clean.console.infrastructure.bulk-install');
        $changeDomainUrl = route('clean.console.infrastructure.change-domain.index');
        $reinstallServerUrl = route('clean.console.infrastructure.reinstall.index');
        $cloudProvidersUrl = route('clean.console.infrastructure.cloud-providers.index');
        $cloudTypes = [
            'linode' => ['LINODE', 'fab fa-linode'],
            'digitalocean' => ['DIGITAL OCEAN', 'fab fa-digital-ocean'],
        ];
        $cloudNavigation = collect($cloudTypes)->map(function (array $entry, string $type) use ($workspace, $routeIs): array {
            $active = $routeIs('console.clouds.*') && request()->route('type') === $type;

            return [
                'label' => $entry[0],
                'icon' => $entry[1],
                'href' => route('clean.console.clouds.index', $type),
                'active' => $active,
            ];
        })->all();
        $appSettingsUrl = route('clean.console.app-settings.index');
        $appHealthUrl = route('clean.console.miscellaneous.app-health');
        $appErrorsUrl = route('clean.console.miscellaneous.app-errors');
        $subscribersUrl = route('clean.console.miscellaneous.subscribers');
        $contactsUrl = route('clean.console.miscellaneous.contacts');
        $horizonUrl = route('clean.console.miscellaneous.horizon');
        $campaignsUrl = route('console.mailer.campaigns');
        $dropsMonitoringUrl = route('clean.console.drops.monitoring');
        $globalTestsUrl = route('clean.console.global-tests.index');
        $globalTestHistoriesUrl = route('clean.console.global-tests.histories');
        $seedListsUrl = route('clean.console.warmups.seeds.index');
        $pmtaAdminUrl = route('clean.console.pmta-admin.index');
        $pmtaSchedulerUrl = route('clean.console.pmta-scheduler.index');
        $pmtaSettingsUrl = route('clean.console.pmta-settings.index');
        $pmtaVmtaUrl = route('clean.console.pmta-vmta.index');
        $pmtaSmtpUrl = route('clean.console.pmta-smtp.index');
        $pmtaErrorsUrl = route('clean.console.pmta-errors.index');
        $pmtaConfigHistoryUrl = route('clean.console.pmta-config-history.index');
        $campaignNegativesUrl = route('clean.console.campaign-negatives.index');
        // Server and PMTA controls are available to every active workspace member by product policy.
        $canManageInfrastructure = true;
        $canManagePmta = true;
        $canManageSponsors = in_array($workspace->pivot->role, ['owner', 'admin'], true);
        $canManageUsers = in_array($workspace->pivot->role, ['owner', 'admin'], true);
        $canManageGlobalUsers = auth()->user()->hasDmsRole('manage_users');
        $sponsorsUrl = route('clean.console.sponsors.index');
        $networkSponsorsUrl = route('clean.console.networks.sponsors.index');
        $offersUrl = route('clean.console.offers.index');
        $networkOffersUrl = route('clean.console.networks.offers.index');
        $ispsUrl = route('clean.console.infrastructure.isps.index');
        $listsUrl = route('clean.console.infrastructure.lists.index');
        $mixesUrl = route('clean.console.audience-mixes.history');
        $domainsUrl = route('clean.console.infrastructure.domains.index');
        $domainProvidersUrl = route('clean.console.infrastructure.domain-providers.index');
        $isServersPage = $routeIs('console.infrastructure.servers.*');
        $isMyServersPage = $routeIs('console.my-servers.index');
        $isMyIpsPage = $routeIs('console.infrastructure.my-ips');
        $isServerProvidersPage = $routeIs('console.infrastructure.server-providers.*');
        $isPmtaAdminPage = $routeIs('console.pmta-admin.index');
        $isPmtaHistoryPage = $routeIs('console.pmta-admin.history');
        $isPmtaSchedulerPage = $routeIs('console.pmta-scheduler.*');
        $isPmtaSettingsPage = $routeIs('console.pmta-settings.*');
        $isPmtaVmtaPage = $routeIs('console.pmta-vmta.*');
        $isPmtaSmtpPage = $routeIs('console.pmta-smtp.*');
        $isPmtaErrorsPage = $routeIs('console.pmta-errors.*');
        $isPmtaConfigHistoryPage = $routeIs('console.pmta-config-history.*');
        $isPmtaPage = $isPmtaAdminPage || $isPmtaSchedulerPage || $isPmtaSettingsPage || $isPmtaVmtaPage || $isPmtaSmtpPage || $isPmtaErrorsPage || $isPmtaHistoryPage || $isPmtaConfigHistoryPage;
        $isIspPage = $routeIs('console.infrastructure.isps.index');
        $isListsPage = $routeIs('console.infrastructure.lists.*');
        $isEmailManagementPage = $routeIs('console.infrastructure.emails.*');
        $isBlacklistCheckerPage = $routeIs('console.infrastructure.blacklist-checker');
        $isListHistoryPage = $routeIs('console.infrastructure.list-history.*');
        $isIspManagementPage = $isIspPage || $isListsPage || $isListHistoryPage || $isEmailManagementPage || $isBlacklistCheckerPage;
        $isDomainsPage = $routeIs('console.infrastructure.domains.index', 'console.infrastructure.domains.records');
        $isDomainProvidersPage = $routeIs('console.infrastructure.domain-providers.index');
        $isBulkInstallPage = $routeIs('console.infrastructure.bulk-install*');
        $isServerInstallPage = $routeIs('console.infrastructure.install.*', 'console.infrastructure.install-settings.*');
        $isChangeDomainPage = $routeIs('console.infrastructure.change-domain.*');
        $isReinstallPage = $routeIs('console.infrastructure.reinstall.*');
        $isCloudProvidersPage = $routeIs('console.infrastructure.cloud-providers.*');
        $isCloudWorkflowPage = $routeIs('console.clouds.*');
        $isAppSettingsPage = $routeIs('console.app-settings.*');
        $isAppHealthPage = $routeIs('console.miscellaneous.app-health');
        $isAppErrorsPage = $routeIs('console.miscellaneous.app-errors');
        $isSubscribersPage = $routeIs('console.miscellaneous.subscribers');
        $isContactsPage = $routeIs('console.miscellaneous.contacts');
        $isHorizonPage = $routeIs('console.miscellaneous.horizon');
        $isMiscellaneousPage = $isAppSettingsPage || $isAppHealthPage || $isAppErrorsPage || $isSubscribersPage || $isContactsPage || $isHorizonPage;
        $isCampaignsPage = $routeIs('console.campaigns.*', 'console.mailer.campaigns');
        $isAudienceMixesPage = $routeIs('console.audience-mixes.*');
        $isUsersPage = $routeIs('console.users.*');
        $isGlobalUsersPage = $routeIs('console.global-users.*');
        $isCampaignNegativesPage = $routeIs('console.campaign-negatives.*');
        $isDropsPage = $routeIs('console.drops.monitoring', 'console.drops.visitors', 'console.drops.suppression*');
        $isSeedListsPage = $routeIs('console.warmups.seeds.*', 'console.warmups.seed-emails.*');
        $isGlobalTestsPage = $routeIs('console.global-tests.index', 'console.global-tests.catalog', 'console.global-tests.test');
        $isGlobalTestHistoriesPage = $routeIs('console.global-tests.histories', 'console.global-tests.history');
        $isWorkspaceProjectsPage = $routeIs('console.gcampaigns.projects');
        $isWorkspaceAccountsPage = $routeIs('console.gcampaigns.accounts');
        $isGsendPage = $isWorkspaceProjectsPage || $isWorkspaceAccountsPage;
        $isTextMechanicPage = $routeIs('console.tools.text-mechanic');
        $isEncodingPage = $routeIs('console.tools.encoding.*');
        $isSpfScannerPage = $routeIs('console.tools.spf.*');
        $isWorkspaceToolPage = $routeIs('console.tools.workspace.*');
        $isDashboardPage = $routeIs('console.analytics.*', 'console.dashboard.main');
        $isDashboardAllStatsPage = $routeIs('console.analytics.all-stats');
        $isDashboardIspStatsPage = $routeIs('console.analytics.isp-stats');
        $isDashboardMailerRevenuePage = $routeIs('console.analytics.mailer-revenue');
        $isCampaignStatisticsPage = $routeIs('console.campaign-statistics.*');
        $isSponsorManagementPage = $routeIs('console.sponsors.*');
        $isNetworkSponsorsPage = $routeIs('console.networks.sponsors.*');
        $isOfferManagementPage = $routeIs('console.offers.*');
        $isNetworkOffersPage = $routeIs('console.networks.offers.*');
        $isServerNavigationPage = $isServerProvidersPage || $isBulkInstallPage || $isServerInstallPage
            || $isChangeDomainPage || $isReinstallPage || $isCloudProvidersPage;
        $isPmtaNavigationPage = $isMyServersPage || $isServersPage || $isMyIpsPage || $isPmtaPage;
        $serverNavigation = [
            ['label' => 'SERVERS PROVIDERS', 'icon' => 'fas fa-tasks', 'href' => $serverProvidersUrl, 'active' => $isServerProvidersPage, 'section' => 'servers'],
            ['label' => 'SERVERS', 'icon' => 'fas fa-server', 'href' => $serversUrl, 'active' => $isServersPage, 'section' => 'servers'],
            ['label' => 'INSTALL', 'icon' => 'fas fa-hammer', 'href' => $serverInstallUrl, 'active' => $isServerInstallPage, 'section' => 'servers'],
            ['label' => 'BULK INSTALL', 'icon' => 'fas fa-tools', 'href' => $bulkInstallUrl, 'active' => $isBulkInstallPage, 'section' => 'servers'],
            ['label' => 'CHANGE DOMAIN', 'icon' => 'fas fa-exchange-alt', 'href' => $changeDomainUrl, 'active' => $isChangeDomainPage, 'section' => 'servers'],
            ['label' => 'REINSTALL SERVER', 'icon' => 'fas fa-redo', 'href' => $reinstallServerUrl, 'active' => $isReinstallPage, 'section' => 'servers'],
            ['label' => 'CLOUD PROVIDERS', 'icon' => 'fas fa-cloud-sun', 'href' => $cloudProvidersUrl, 'active' => $isCloudProvidersPage, 'section' => 'servers'],
        ];
        $pmtaNavigation = [
            ['label' => 'MY SERVERS', 'icon' => 'fas fa-server', 'href' => route('clean.console.my-servers.index'), 'active' => $isMyServersPage, 'section' => 'pmta'],
            ['label' => 'MY IPS', 'icon' => 'fas fa-server', 'href' => route('clean.console.infrastructure.my-ips'), 'active' => $isMyIpsPage, 'section' => 'pmta'],
            ['label' => 'PMTA', 'icon' => 'fas fa-desktop', 'href' => $pmtaAdminUrl, 'active' => $isPmtaAdminPage, 'section' => 'pmta'],
            ['label' => 'PMTA SCHEDULER', 'icon' => 'fas fa-clock', 'href' => $pmtaSchedulerUrl, 'active' => $isPmtaSchedulerPage, 'section' => 'pmta'],
            ['label' => 'PMTA SETTINGS', 'icon' => 'fas fa-cog', 'href' => $pmtaSettingsUrl, 'active' => $isPmtaSettingsPage, 'section' => 'pmta'],
            ['label' => 'PMTA VMTA', 'icon' => 'fas fa-bus', 'href' => $pmtaVmtaUrl, 'active' => $isPmtaVmtaPage, 'section' => 'pmta'],
            ['label' => 'PMTA SMTP', 'icon' => 'fas fa-bus-alt', 'href' => $pmtaSmtpUrl, 'active' => $isPmtaSmtpPage, 'section' => 'pmta'],
            ['label' => 'PMTA ERRORS', 'icon' => 'fas fa-exclamation', 'href' => $pmtaErrorsUrl, 'active' => $isPmtaErrorsPage, 'section' => 'pmta'],
            ['label' => 'PMTA ACTION HISTORY', 'icon' => 'fas fa-eye', 'href' => route('clean.console.pmta-admin.history'), 'active' => $isPmtaHistoryPage, 'section' => 'pmta'],
            ['label' => 'PMTA CONFIG HISTORY', 'icon' => 'fas fa-eye', 'href' => $pmtaConfigHistoryUrl, 'active' => $isPmtaConfigHistoryPage, 'section' => 'pmta'],
        ];
        if (! $canManageInfrastructure) {
            $serverNavigation = [$serverNavigation[1], $serverNavigation[2]];
            if ($canManagePmta) {
                $serverNavigation[] = ['label' => 'PMTA', 'icon' => 'fas fa-desktop', 'href' => route('clean.console.pmta-admin.index'), 'active' => $isPmtaAdminPage, 'section' => 'servers'];
            }
        }
        $navigation = [
            ['label' => 'DASHBOARDS', 'icon' => 'fas fa-tachometer-alt', 'open' => $isDashboardPage, 'items' => [
                ['label' => 'MAIN', 'icon' => 'fas fa-tachometer-alt', 'href' => route('console.dashboard.main'), 'active' => $isDashboardPage],
                ['label' => 'ALL STATS', 'icon' => 'fas fa-hospital-alt', 'href' => route('clean.console.analytics.all-stats'), 'active' => $isDashboardAllStatsPage],
                ['label' => 'ISP STATS', 'icon' => 'fas fa-envelope-open', 'href' => route('clean.console.analytics.isp-stats'), 'active' => $isDashboardIspStatsPage],
                ['label' => 'MAILERS REV STATS', 'icon' => 'fas fa-user-alt', 'href' => route('clean.console.analytics.mailer-revenue'), 'active' => $isDashboardMailerRevenuePage],
            ]],
            ['label' => 'USERS MANAGEMENT', 'icon' => 'fas fa-users-cog', 'items' => array_values(array_filter([
                $canManageGlobalUsers ? ['label' => 'USERS', 'icon' => 'fas fa-user-friends', 'href' => route('console.global-users.index'), 'active' => $isGlobalUsersPage] : null,
                $canManageUsers ? ['label' => 'WORKSPACE USERS', 'icon' => 'fas fa-users', 'href' => route('clean.console.users.index'), 'active' => $isUsersPage] : null,
            ]))],
            ['label' => 'ISPS MANAGEMENT', 'icon' => 'far fa-envelope', 'open' => $isIspManagementPage, 'items' => [
                ['label' => 'ISPS', 'icon' => 'fas fa-envelope-open', 'href' => $ispsUrl, 'active' => $routeIs('console.infrastructure.isps.*')],
                ['label' => 'LISTS', 'icon' => 'fas fa-file', 'href' => $listsUrl, 'active' => $isListsPage],
                ['label' => 'LIST HISTORY', 'icon' => 'fas fa-chart-area', 'href' => route('clean.console.infrastructure.list-history.index'), 'active' => $isListHistoryPage],
                ['label' => 'SEARCH EMAIL', 'icon' => 'fas fa-search', 'href' => route('clean.console.infrastructure.emails.index'), 'active' => $isEmailManagementPage],
                ['label' => 'BLACKLIST CHECKER', 'icon' => 'fas fa-trash-alt', 'href' => route('clean.console.infrastructure.blacklist-checker'), 'active' => $isBlacklistCheckerPage],
            ]],
            ['label' => 'DOMAINS', 'icon' => 'fas fa-cloud', 'items' => [
                ['label' => 'DOMAINS PROVIDERS', 'icon' => 'fas fa-tasks', 'href' => $domainProvidersUrl, 'active' => $routeIs('console.infrastructure.domain-providers.*')],
                ['label' => 'DOMAINS', 'icon' => 'fas fa-cloud', 'href' => $domainsUrl, 'active' => $isDomainsPage],
            ]],
            ['label' => 'SERVERS', 'icon' => 'fas fa-server', 'items' => $serverNavigation, 'open' => $isServerNavigationPage],
            ['label' => 'SPONSORS', 'icon' => 'fas fa-hand-holding-usd', 'href' => $sponsorsUrl, 'active' => $isSponsorManagementPage, 'requires_management' => true],
            ['label' => 'OFFERS', 'icon' => 'fas fa-shopping-bag', 'href' => $offersUrl, 'active' => $isOfferManagementPage, 'requires_management' => true],
            ['label' => 'MISCELLANEOUS', 'id' => 'nav-submenu-miscellaneous', 'icon' => 'fas fa-ellipsis-h', 'items' => [
                ['label' => 'SUBSCRIBERS', 'icon' => 'fas fa-newspaper', 'href' => $subscribersUrl, 'active' => $isSubscribersPage],
                ['label' => 'CONTACTS', 'icon' => 'fas fa-address-book', 'href' => $contactsUrl, 'active' => $isContactsPage],
                ['label' => 'HORIZON', 'icon' => 'fas fa-balance-scale', 'href' => $horizonUrl, 'active' => $isHorizonPage],
                ['label' => 'APP HEALTH', 'icon' => 'fas fa-heartbeat', 'href' => $appHealthUrl, 'active' => $isAppHealthPage],
                ['label' => 'SETTINGS', 'icon' => 'fas fa-cogs', 'href' => $appSettingsUrl, 'active' => $isAppSettingsPage],
                ['label' => 'APP ERRORS', 'icon' => 'fas fa-exclamation-circle', 'href' => $appErrorsUrl, 'active' => $isAppErrorsPage],
            ]],
            ['label' => 'CAMPAIGNS', 'id' => 'nav-submenu-campaigns', 'icon' => 'fas fa-envelope', 'open' => $isCampaignsPage || $isCampaignNegativesPage || $isAudienceMixesPage || $isGsendPage || $isCampaignStatisticsPage, 'items' => [
                ['label' => 'SEND', 'icon' => 'fas fa-envelope', 'href' => $campaignsUrl, 'active' => $isCampaignsPage],
                ['label' => 'GSEND', 'icon' => 'fab fa-google', 'open' => $isGsendPage, 'items' => [
                    ['label' => 'PROJECTS', 'icon' => 'fas fa-plus', 'href' => route('clean.console.gcampaigns.projects'), 'active' => $isWorkspaceProjectsPage],
                    ['label' => 'ACCOUNTS', 'icon' => 'fas fa-at', 'href' => route('clean.console.gcampaigns.accounts'), 'active' => $isWorkspaceAccountsPage],
                    ['label' => 'SEND', 'icon' => 'fas fa-envelope'],
                ]],
                ['label' => 'NEGATIVES', 'icon' => 'fas fa-sticky-note', 'href' => $campaignNegativesUrl, 'active' => $isCampaignNegativesPage],
                ['label' => 'MIXS', 'icon' => 'fas fa-blender', 'href' => $mixesUrl, 'active' => $isAudienceMixesPage],
                ['label' => 'STATISTICS', 'icon' => 'fas fa-chart-line', 'href' => route('clean.console.campaign-statistics.index'), 'active' => $isCampaignStatisticsPage],
                ['label' => 'LOGS', 'icon' => 'fas fa-eye'],
            ]],
            ['label' => 'DROPS', 'icon' => 'fas fa-paper-plane', 'open' => $isDropsPage, 'items' => [
                ['label' => 'RECENT DROP', 'icon' => 'fas fa-paper-plane'],
                ['label' => 'FRESH DROP', 'icon' => 'fas fa-plus'],
                ['label' => 'MONITORING', 'icon' => 'fas fa-eye', 'href' => $dropsMonitoringUrl, 'active' => $isDropsPage],
                ['label' => 'SUPPRESSION', 'icon' => 'fas fa-times', 'href' => route('clean.console.drops.suppression'), 'active' => $routeIs('console.drops.suppression*')],
                ['label' => 'STATISTICS', 'icon' => 'fas fa-chart-line'],
            ]],
            ['label' => "WARMUP'S", 'icon' => 'fas fa-burn', 'open' => $isSeedListsPage, 'items' => [
                ['label' => 'SEND', 'icon' => 'fas fa-burn'],
                ['label' => 'SEEDS', 'icon' => 'fas fa-book', 'href' => $seedListsUrl, 'active' => $isSeedListsPage],
                ['label' => 'STATISTICS', 'icon' => 'fas fa-chart-line'],
            ]],
            ['label' => 'GLOBAL TESTS', 'icon' => 'fas fa-map-signs', 'open' => $isGlobalTestsPage || $isGlobalTestHistoriesPage, 'items' => [
                ['label' => 'SEND', 'icon' => 'fas fa-map-signs', 'href' => $globalTestsUrl, 'active' => $isGlobalTestsPage],
                ['label' => 'HISTORIES', 'icon' => 'fas fa-eye', 'href' => $globalTestHistoriesUrl, 'active' => $isGlobalTestHistoriesPage],
            ]],
            ['label' => 'SERVERS', 'icon' => 'fas fa-server', 'items' => $pmtaNavigation, 'open' => $isPmtaNavigationPage],
            ['label' => 'NETWORKS', 'icon' => 'fas fa-network-wired', 'open' => $isNetworkSponsorsPage || $isNetworkOffersPage, 'items' => [
                ['label' => 'SPONSORS', 'icon' => 'fas fa-shopping-cart', 'href' => $networkSponsorsUrl, 'active' => $isNetworkSponsorsPage],
                ['label' => 'OFFERS', 'icon' => 'fas fa-shopping-bag', 'href' => $networkOffersUrl, 'active' => $isNetworkOffersPage],
            ]],
            ['label' => 'TOOLS', 'icon' => 'fas fa-toolbox', 'open' => $isTextMechanicPage || $isEncodingPage || $isSpfScannerPage || $isWorkspaceToolPage, 'items' => [
                ['label' => 'BLACKLIST CHECKER', 'icon' => 'fas fa-circle'],
                ['label' => 'ENCODING', 'icon' => 'fas fa-pencil-ruler', 'href' => route('clean.console.tools.encoding.index'), 'active' => $isEncodingPage],
                ['label' => 'SPF SCANNER', 'icon' => 'fas fa-search', 'href' => route('clean.console.tools.spf.index'), 'active' => $isSpfScannerPage],
                ['label' => 'OUTLOOK', 'icon' => 'fas fa-circle'],
                ['label' => 'WORKSPACE', 'icon' => 'fas fa-th-large', 'href' => route('clean.console.tools.workspace.index'), 'active' => $isWorkspaceToolPage],
                ['label' => 'SHORTLINKS', 'icon' => 'fas fa-circle'],
                ['label' => 'REPLY EXTRACTOR', 'icon' => 'fas fa-circle'],
                ['label' => 'SPAM CHECKER', 'icon' => 'fas fa-circle'],
                ['label' => 'IMGUR', 'icon' => 'fas fa-circle'],
                ['label' => 'AFRAID', 'icon' => 'fas fa-circle'],
                ['label' => 'FIREBASE', 'icon' => 'fas fa-fire'],
                ['label' => 'TEXT MECHANIC', 'icon' => 'fas fa-circle', 'href' => route('clean.console.tools.text-mechanic'), 'active' => $isTextMechanicPage],
            ]],
            ['label' => 'CLOUDS', 'icon' => 'fas fa-cloud-sun', 'items' => $cloudNavigation, 'open' => $isCloudWorkflowPage],
        ];
    @endphp
    <div id="page-container" class="sidebar-o enable-page-overlay sidebar-dark side-scroll page-header-fixed sidebar-mini">
        <nav id="sidebar" aria-label="Main Navigation">
            <div class="content-header bg-white-5">
                <a class="font-w600 text-dual" href="{{ route('console.index') }}" aria-label="PXLRBLDLNK - DMS">
                    <span class="smini-visible"><i class="fa fa-envelope text-primary"></i></span>
                    <span class="smini-hide font-size-h5 tracking-wider font-w400 text-uppercase">PXLRBLDLNK - DMS</span>
                </a>
                <div><button type="button" class="d-lg-none btn btn-sm btn-dual ml-1" data-toggle="layout" data-action="sidebar_close" aria-label="Close navigation"><i class="fa fa-fw fa-times"></i></button></div>
            </div>
            <div class="js-sidebar-scroll">
                <div class="content-side">
                    <ul class="nav-main">
                        @foreach ($navigation as $groupIndex => $group)
                            @if (($group['requires_management'] ?? false) && ! $canManageSponsors)
                                @continue
                            @endif
                            @if ($group['label'] === 'CAMPAIGNS')
                                <li class="nav-main-heading">SEND SECTION</li>
                            @endif
                            @if (isset($group['href']))
                                <li class="nav-main-item {{ ($group['section'] ?? null) === 'servers' ? 'nav-server-item' : '' }}">
                                    <a class="nav-main-link {{ ($group['active'] ?? false) ? 'active' : '' }}" href="{{ $group['href'] }}" @if ($group['disabled'] ?? false) aria-disabled="true" tabindex="-1" @endif @if (($group['active'] ?? false)) aria-current="page" @endif>
                                        <i class="nav-main-link-icon {{ $group['icon'] }}"></i><span class="nav-main-link-name">{{ $group['label'] }}</span>
                                    </a>
                                </li>
                            @else
                                @php
                                    $groupIsOpen = ($group['open'] ?? false)
                                        || collect($group['items'])->contains(fn ($item) => ($item['active'] ?? false));
                                    $groupIsActive = ($group['active'] ?? false) || collect($group['items'])->contains(fn ($item) => ($item['active'] ?? false));
                                    $groupSubmenuId = $group['id'] ?? 'nav-submenu-'.\Illuminate\Support\Str::slug($group['label']).'-'.$groupIndex;
                                @endphp
                                <li class="nav-main-item {{ $groupIsOpen ? 'open' : '' }}">
                                    <a class="nav-main-link nav-main-link-submenu {{ $groupIsActive ? 'active' : '' }}" href="#" data-toggle="submenu" aria-expanded="{{ $groupIsOpen ? 'true' : 'false' }}" aria-controls="{{ $groupSubmenuId }}">
                                        <i class="nav-main-link-icon {{ $group['icon'] }}"></i><span class="nav-main-link-name">{{ $group['label'] }}</span>
                                    </a>
                                    @if ($group['items'] !== [])
                                        <ul class="nav-main-submenu" id="{{ $groupSubmenuId }}">
                                            @foreach ($group['items'] as $item)
                                                @php($itemIsOpen = ($item['open'] ?? false) || collect($item['items'] ?? [])->contains(fn ($nestedItem) => ($nestedItem['active'] ?? false)))
                                                <li class="nav-main-item {{ ($item['section'] ?? null) === 'servers' ? 'nav-server-item' : '' }} {{ $itemIsOpen ? 'open' : '' }}">
                                                    @if (isset($item['items']))
                                                        <a class="nav-main-link nav-main-link-submenu" href="#" data-toggle="submenu" aria-expanded="{{ $itemIsOpen ? 'true' : 'false' }}">
                                                            <i class="nav-main-link-icon {{ $item['icon'] }}"></i><span class="nav-main-link-name">{{ $item['label'] }}</span>
                                                        </a>
                                                        <ul class="nav-main-submenu">
                                                            @foreach ($item['items'] as $nestedItem)
                                                                <li class="nav-main-item">
                                                                    <a class="nav-main-link {{ ($nestedItem['active'] ?? false) ? 'active' : '' }}" href="{{ $nestedItem['href'] ?? '#' }}" @if (! isset($nestedItem['href'])) aria-disabled="true" tabindex="-1" @endif @if (($nestedItem['active'] ?? false)) aria-current="page" @endif>
                                                                        <i class="nav-main-link-icon {{ $nestedItem['icon'] }}"></i><span class="nav-main-link-name">{{ $nestedItem['label'] }}</span>
                                                                    </a>
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    @else
                                                        <a class="nav-main-link {{ ($item['active'] ?? false) ? 'active' : '' }}" href="{{ $item['href'] ?? '#' }}" @if (! isset($item['href'])) aria-disabled="true" tabindex="-1" @endif @if (($item['active'] ?? false)) aria-current="page" @endif>
                                                            <i class="nav-main-link-icon {{ $item['icon'] }}"></i><span class="nav-main-link-name">{{ $item['label'] }}</span>
                                                        </a>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            </div>
        </nav>
        <header id="page-header">
            <div class="content-header">
                <div class="d-flex align-items-center">
                    <button type="button" class="btn btn-sm btn-dual mr-2 d-lg-none" data-toggle="layout" data-action="sidebar_open" aria-label="Open navigation"><i class="fa fa-fw fa-ellipsis-v"></i></button>
                    <button type="button" class="btn btn-sm btn-dual mr-2 d-none d-lg-inline-block" data-toggle="layout" data-action="sidebar_mini_toggle" aria-label="Toggle navigation width" aria-pressed="true"><i class="fa fa-fw fa-ellipsis-v"></i></button>
                    <a class="btn btn-sm btn-dual mr-2" href="{{ $campaignsUrl }}" title="CAMPAIGNS" aria-label="Campaigns"><i class="fas fa-envelope"></i></a>
                    @if ($canManagePmta)
                        <a class="btn btn-sm btn-dual mr-2" href="{{ $pmtaAdminUrl }}" title="PMTA ADMIN" aria-label="PMTA Admin"><i class="fas fa-desktop"></i></a>
                    @endif
                    <a class="btn btn-sm btn-dual mr-2" href="{{ $globalTestsUrl }}" title="GLOBAL TEST" aria-label="Global Test"><i class="fas fa-map-signs"></i></a>
                </div>
                <div class="d-flex align-items-center">
                    @if (session()->has('alphalink.impersonator_id'))
                        <form method="post" action="{{ route('console.global-users.impersonation.leave') }}" class="mr-2">@csrf<button class="btn btn-sm btn-warning" type="submit">STOP IMPERSONATION</button></form>
                    @endif
                    <div class="dropdown d-inline-block ml-2">
                        <button type="button" class="btn btn-sm btn-dual d-flex align-items-center" id="page-header-user-dropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fa fa-user d-sm-none"></i><span class="d-none d-sm-inline-block ml-1">{{ auth()->user()->name ?: auth()->user()->email }}</span><i class="fa fa-angle-down ml-1"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-md dropdown-menu-right p-0 border-0" aria-labelledby="page-header-user-dropdown">
                            <div class="p-3 text-center bg-primary-dark rounded-top"><p class="text-white font-w600 mb-0">{{ auth()->user()->name ?: auth()->user()->email }}</p></div>
                            <div class="p-2">
                                <a class="dropdown-item d-flex align-items-center justify-content-between" href="{{ route('auth.profile') }}">PROFILE</a>
                                <form method="post" action="{{ route('logout') }}">@csrf<button class="dropdown-item d-flex align-items-center justify-content-between w-100" type="submit">LOGOUT</button></form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <main id="main-container">
            <div class="content content-full p-0"></div>
            <div class="content" id="main_content" @if ($isPmtaPage) style="padding: 1rem 1rem 1px !important" @endif>
                @yield('content')
            </div>
        </main>
        <footer id="page-footer" class="bg-body-light">
            <div class="content py-1">
                <div class="row font-size-sm">
                    <div class="col-sm-6 order-sm-2 py-1 text-center text-sm-right"><i class="fa fa-heart text-danger" title="With Love"></i></div>
                    <div class="col-sm-6 order-sm-1 py-1 text-center text-sm-left"><a class="font-w600" href="{{ route('console.index') }}">PXLRBLDLNK</a> <span>© DMS {{ now()->year }}</span></div>
                </div>
            </div>
        </footer>
    </div>
    <script src="{{ asset('dms-reference/assets/js/script.js') }}"></script>
    <script src="{{ asset('dms-reference/assets/js/app.js') }}"></script>
    @if ($isListsPage || $isListHistoryPage || $isSponsorManagementPage || $isNetworkSponsorsPage || $isOfferManagementPage || $isNetworkOffersPage || $isServerInstallPage || $isReinstallPage || $isChangeDomainPage || $isCloudProvidersPage || $isDomainProvidersPage || $isPmtaPage || $isDropsPage || $isWorkspaceToolPage || $routeIs('console.drops.suppression*', 'console.campaigns.create', 'console.campaigns.edit', 'console.campaigns.show'))
        <script src="{{ asset('dms-reference/plugins/bootstrap_select/bootstrap-select.js') }}"></script>
    @endif
    @if ($isServersPage || $isMyServersPage || $isMyIpsPage || $isServerProvidersPage || $isPmtaPage || $isDropsPage || $routeIs('console.drops.suppression*', 'console.tools.spf.*', 'console.campaigns.visitors') || $isSeedListsPage || $isPmtaHistoryPage || $isIspPage || $isListsPage || $isEmailManagementPage || $isDomainsPage || $isDomainProvidersPage || $isCloudProvidersPage || $isSubscribersPage || $isContactsPage || $isSponsorManagementPage || $isNetworkSponsorsPage || $isOfferManagementPage || $isNetworkOffersPage || $isGlobalUsersPage || $isWorkspaceProjectsPage || $isWorkspaceAccountsPage)
        <script src="{{ asset('dms-reference/plugins/datatables/datatables.min.js') }}"></script>
        <script src="{{ asset('dms-reference/plugins/datatables/jquery.mark.min.js') }}"></script>
        <script src="{{ asset('dms-reference/plugins/datatables/datatables.mark.js') }}"></script>
        <script src="{{ asset('dms-reference/assets/js/tables_datatables.js') }}"></script>
    @endif
    @if ($isPmtaPage)
        <script src="{{ asset('dms-reference/pmta-controls.js') }}"></script>
        <style>
            dialog.pmta-control-dialog { border: 0; border-radius: .25rem; box-shadow: 0 1rem 3rem rgba(0, 0, 0, .28); max-width: calc(100vw - 2rem); padding: 1.25rem; width: 32rem; }
            dialog.pmta-control-dialog::backdrop { background: rgba(0, 0, 0, .58); }
        </style>
    @endif
    @if ($isServersPage)
        <script src="{{ asset('dms-reference/plugins/datatables/natural.js') }}"></script>
        <script src="{{ asset('dms-reference/plugins/datatables/ip-address.js') }}"></script>
        <script src="{{ asset('dms-reference/server-index.js') }}?v={{ substr(sha1_file(public_path('dms-reference/server-index.js')), 0, 12) }}" defer></script>
    @elseif ($isMyServersPage)
        <script src="{{ asset('dms-reference/plugins/datatables/natural.js') }}"></script>
        <script src="{{ asset('dms-reference/plugins/datatables/ip-address.js') }}"></script>
        <script src="{{ asset('dms-reference/my-servers.js') }}?v={{ substr(sha1_file(public_path('dms-reference/my-servers.js')), 0, 12) }}" defer></script>
    @elseif ($isMyIpsPage)
        <script src="{{ asset('dms-reference/plugins/datatables/natural.js') }}"></script>
    @endif
    @stack('scripts')
    <script>
        (() => {
            const pageContainer = document.getElementById('page-container');
            const sidebarToggle = document.querySelector('[data-toggle="layout"][data-action="sidebar_mini_toggle"]');
            if (!pageContainer || !sidebarToggle) return;

            sidebarToggle.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();

                if (window.innerWidth > 991) {
                    if (window.App && typeof window.App.layout === 'function') {
                        window.App.layout('sidebar_mini_toggle');
                    } else {
                        pageContainer.classList.toggle('sidebar-mini');
                    }
                }

                sidebarToggle.setAttribute('aria-pressed', String(pageContainer.classList.contains('sidebar-mini')));
            });
        })();
    </script>
    <script>
        (() => {
            const toneClasses = { info: 'alert-info', success: 'alert-success', warning: 'alert-warning', danger: 'alert-danger' };
            const stack = () => {
                let region = document.getElementById('alphalink-toast-stack');
                if (!region) {
                    region = document.createElement('div');
                    region.id = 'alphalink-toast-stack';
                    region.className = 'alphalink-toast-stack';
                    region.setAttribute('aria-live', 'polite');
                    region.setAttribute('aria-relevant', 'additions');
                    region.setAttribute('aria-label', 'Notifications');
                    document.body.append(region);
                }

                return region;
            };

            window.AlphaLinkToast = {
                show(message, tone = 'info') {
                    const toast = document.createElement('div');
                    toast.className = `alert ${toneClasses[tone] || toneClasses.info} alphalink-toast d-flex align-items-start justify-content-between`;
                    toast.setAttribute('role', tone === 'danger' ? 'alert' : 'status');
                    const text = document.createElement('span');
                    text.textContent = String(message || 'Action completed.');
                    const close = document.createElement('button');
                    close.type = 'button';
                    close.className = 'close ml-3';
                    close.setAttribute('aria-label', 'Dismiss notification');
                    close.innerHTML = '<span aria-hidden="true">&times;</span>';
                    const remove = () => toast.remove();
                    close.addEventListener('click', remove, { once: true });
                    toast.append(text, close);
                    stack().append(toast);
                    window.setTimeout(remove, 5000);
                },
            };
        })();
    </script>
    <script>
        if (typeof window.jQuery !== 'function') {
            document.addEventListener('click', (event) => {
                const toggle = event.target.closest('#sidebar a[data-toggle="submenu"][aria-controls]');
                if (!toggle) {
                    return;
                }

                event.preventDefault();
                const submenu = document.getElementById(toggle.getAttribute('aria-controls'));
                if (!submenu) {
                    return;
                }

                const expanded = toggle.getAttribute('aria-expanded') === 'true';
                toggle.setAttribute('aria-expanded', String(!expanded));
                toggle.closest('.nav-main-item')?.classList.toggle('open', !expanded);
            });
        }
    </script>
</body>
</html>
