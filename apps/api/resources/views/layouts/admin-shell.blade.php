<!doctype html>
<html lang="en">
<head>
    <script>
        (function() {
            var theme = 'light';
            try {
                var stored = localStorage.getItem('alphalink-command-os-theme');
                if (stored === 'light' || stored === 'dark') {
                    theme = stored;
                }
            } catch(e) {}
            document.documentElement.setAttribute('data-command-theme', theme);
            document.documentElement.dataset.commandTheme = theme;
        })();
    </script>
    @php
        $routeIs = static function (string ...$patterns): bool {
            return request()->routeIs(...$patterns)
                || request()->routeIs(...array_map(static fn (string $pattern): string => 'clean.'.$pattern, $patterns));
        };
        $isDropVisitorsPage = $routeIs('console.drops.visitors');
        $commandOsDashboard = $routeIs('console.dashboard.main', 'console.workspace.dashboard.main', 'console.analytics.index');
        $isCampaignComposerPage = $routeIs('console.campaigns.create', 'console.campaigns.edit');
        $commandOsShell = true;
        $canUseDashboardOperations = $commandOsDashboard && in_array(auth()->user()?->access_role, ['owner', 'admin', 'operator', 'viewer'], true);
        $hideDashboardForbiddenDestinations = $commandOsDashboard && ! $canUseDashboardOperations;
        $usesGoogleCharts = $isDropVisitorsPage || $routeIs('console.campaigns.visitors');
        $usesAnalyticsCharts = $routeIs('console.analytics.*', 'console.dashboard.main', 'console.campaign-statistics.*');
        $usesAnalyticsPivot = $routeIs(
            'console.analytics.all-stats',
            'console.analytics.mailer-revenue',
            'console.campaign-statistics.index',
            'console.drops.statistics',
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
    <title>@yield('title', 'Servers')</title>
    <link rel="stylesheet" href="{{ asset('console-assets/fonts/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('console-assets/plugins/bootstrap_select/bootstrap-select.css') }}">
    @if ($routeIs('console.infrastructure.servers.index', 'console.infrastructure.server-providers.index', 'console.infrastructure.my-ips', 'console.my-servers.index', 'console.infrastructure.isps.index', 'console.infrastructure.lists.*', 'console.infrastructure.emails.*', 'console.infrastructure.blacklist-checker', 'console.infrastructure.domains.index', 'console.infrastructure.domains.records', 'console.infrastructure.domain-providers.index', 'console.infrastructure.cloud-providers.index', 'console.pmta-admin.history', 'console.pmta-config-history.*', 'console.pmta-scheduler.*', 'console.pmta-vmta.*', 'console.pmta-smtp.*', 'console.miscellaneous.subscribers', 'console.miscellaneous.contacts', 'console.sponsors.index', 'console.networks.sponsors.index', 'console.offers.*', 'console.networks.offers.index', 'console.drops.monitoring', 'console.drops.visitors', 'console.drops.suppression*', 'console.warmups.seeds.*', 'console.warmups.seed-emails.*', 'console.global-users.index', 'console.tools.spf.*', 'console.gcampaigns.projects', 'console.gcampaigns.accounts', 'console.campaigns.visitors', 'console.mailer.campaign-logs', 'console.campaign-logs.*'))
        <link rel="stylesheet" href="{{ asset('console-assets/plugins/datatables/datatables.min.css') }}">
    @endif
    <link rel="stylesheet" href="{{ asset('console-assets/assets/css/theme.css') }}">
    @if ($commandOsShell)
        @vite(['resources/css/command-os.css', 'resources/js/command-os.tsx'])
    @endif
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
<body class="command-os-shell {{ $commandOsDashboard ? 'command-os-dashboard' : '' }} {{ $isCampaignComposerPage ? 'command-os-campaign-composer' : '' }}">
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
        $headerUserLabel = auth()->user()->name ?: auth()->user()->email;
        $headerUserParts = preg_split('/\s+/', trim($headerUserLabel)) ?: [];
        $headerUserFirst = $headerUserParts[0] ?? 'U';
        $headerUserSecondInitial = count($headerUserParts) > 1
            ? mb_substr($headerUserParts[count($headerUserParts) - 1], 0, 1)
            : mb_substr($headerUserFirst, 1, 1);
        $headerUserInitials = mb_strtoupper(mb_substr($headerUserFirst, 0, 1).$headerUserSecondInitial);
        $subscribersUrl = route('clean.console.miscellaneous.subscribers');
        $contactsUrl = route('clean.console.miscellaneous.contacts');
        $horizonUrl = route('clean.console.miscellaneous.horizon');
        $operatorCampaignViewQuery = [];
        if (auth()->user()->access_role === \App\Models\User::ACCESS_ROLE_ADMIN && request()->boolean('view_operator_campaigns')) {
            $operatorCampaignViewQuery = ['view_operator_campaigns' => 1];
            if (filled(request()->query('operator_id'))) {
                $operatorCampaignViewQuery['operator_id'] = request()->query('operator_id');
            }
        }
        $campaignsUrl = route('console.mailer.campaigns', $operatorCampaignViewQuery);
        $campaignLogsUrl = route('console.mailer.campaign-logs', $operatorCampaignViewQuery);
        $dropsMonitoringUrl = route('clean.console.drops.monitoring');
        $dropsStatisticsUrl = route('clean.console.drops.statistics');
        $dropDraftsUrl = route('clean.console.drops.drafts.index');
        $globalTestsUrl = route('clean.console.global-tests.index');
        $globalTestHistoriesUrl = route('clean.console.global-tests.histories');
        $seedListsUrl = route('clean.console.warmups.seeds.index');
        $dropDraftCreateUrl = route('clean.console.drops.drafts.create');
        $warmupsUrl = route('clean.console.warmups.index');
        $warmupStatisticsUrl = route('clean.console.warmups.statistics');
        $pmtaAdminUrl = route('clean.console.pmta-admin.index');
        $pmtaSchedulerUrl = route('clean.console.pmta-scheduler.index');
        $pmtaSettingsUrl = route('clean.console.pmta-settings.index');
        $pmtaVmtaUrl = route('clean.console.pmta-vmta.index');
        $pmtaSmtpUrl = route('clean.console.pmta-smtp.index');
        $pmtaErrorsUrl = route('clean.console.pmta-errors.index');
        $pmtaConfigHistoryUrl = route('clean.console.pmta-config-history.index');
        $campaignNegativesUrl = route('clean.console.campaign-negatives.index');
        // Server and PMTA controls are available to every active workspace member by product policy.
        $canManageInfrastructure = auth()->user()?->hasFullAccess() ?? false;
        $canManagePmta = auth()->user()?->hasFullAccess() ?? false;
        $canManageSponsors = auth()->user()?->hasFullAccess() ?? false;
        $canManageUsers = auth()->user()?->hasFullAccess() ?? false;
        $canManageGlobalUsers = auth()->user()->hasRole('manage_users');
        $sponsorsUrl = route('clean.console.sponsors.index');
        $networkSponsorsUrl = route('clean.console.networks.sponsors.index');
        $offersUrl = route('clean.console.offers.index');
        $networkOffersUrl = route('clean.console.networks.offers.index');
        $ispsUrl = route('clean.console.infrastructure.isps.index');
        $listsUrl = route('clean.console.infrastructure.lists.index');
        $mixesUrl = route('clean.console.audience-mixes.history');
        $createMixUrl = route('clean.console.audience-mixes.index');
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
        $isWorkspaceCommunicationsPage = $routeIs('console.workspace-communications.*');
        $isAppHealthPage = $routeIs('console.miscellaneous.app-health');
        $isAppErrorsPage = $routeIs('console.miscellaneous.app-errors');
        $isSubscribersPage = $routeIs('console.miscellaneous.subscribers');
        $isContactsPage = $routeIs('console.miscellaneous.contacts');
        $isHorizonPage = $routeIs('console.miscellaneous.horizon');
        $isMiscellaneousPage = $isAppSettingsPage || $isAppHealthPage || $isAppErrorsPage || $isSubscribersPage || $isContactsPage || $isHorizonPage;
        $isCampaignsPage = $routeIs('console.campaigns.*', 'console.mailer.campaigns');
        $isCampaignLogsPage = $routeIs('console.campaign-logs.*', 'console.mailer.campaign-logs');
        $isAudienceMixesPage = $routeIs('console.audience-mixes.*');
        $isAudienceMixHistoryPage = $routeIs('console.audience-mixes.history');
        $isAudienceMixBuilderPage = $routeIs('console.audience-mixes.index');
        $isUsersPage = $routeIs('console.users.*');
        $isGlobalUsersPage = $routeIs('console.global-users.*');
        $isCampaignNegativesPage = $routeIs('console.campaign-negatives.*');
        $isDropStatisticsPage = $routeIs('console.drops.statistics');
        $isDropsMonitoringPage = $routeIs('console.drops.monitoring', 'console.drops.visitors');
        $isDropDraftPage = $routeIs('console.drops.drafts.*');
        $isDropDraftIndexPage = $routeIs('console.drops.drafts.index');
        $isDropDraftEditorPage = $routeIs('console.drops.drafts.create', 'console.drops.drafts.edit', 'console.drops.drafts.update', 'console.drops.drafts.store');
        $isDropsPage = $isDropsMonitoringPage || $isDropStatisticsPage || $isDropDraftPage || $routeIs('console.drops.suppression*');
        $isSeedListsPage = $routeIs('console.warmups.seeds.*', 'console.warmups.seed-emails.*');
        $isWarmupDraftPage = $routeIs('console.warmups.index', 'console.warmups.create', 'console.warmups.edit', 'console.warmups.update', 'console.warmups.store');
        $isWarmupStatisticsPage = $routeIs('console.warmups.statistics');
        $isWarmupsPage = $isSeedListsPage || $isWarmupDraftPage || $isWarmupStatisticsPage;
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
        $navigation = array_values(array_filter([
            ['label' => 'DASHBOARDS', 'icon' => 'fas fa-tachometer-alt', 'open' => $isDashboardPage, 'items' => [
                ['label' => 'MAIN', 'icon' => 'fas fa-tachometer-alt', 'href' => route('console.dashboard.main'), 'active' => $isDashboardPage],
                ['label' => 'ALL STATS', 'icon' => 'fas fa-hospital-alt', 'href' => route('clean.console.analytics.all-stats'), 'active' => $isDashboardAllStatsPage],
                ['label' => 'ISP STATS', 'icon' => 'fas fa-envelope-open', 'href' => route('clean.console.analytics.isp-stats'), 'active' => $isDashboardIspStatsPage],
                ['label' => 'MAILERS REV STATS', 'icon' => 'fas fa-user-alt', 'href' => route('clean.console.analytics.mailer-revenue'), 'active' => $isDashboardMailerRevenuePage],
            ]],
            ['label' => 'USERS MANAGEMENT', 'icon' => 'fas fa-users-cog', 'items' => array_values(array_filter([
                $canManageGlobalUsers ? ['label' => 'GLOBAL USER ACCOUNTS', 'icon' => 'fas fa-user-friends', 'href' => route('console.global-users.index'), 'active' => $isGlobalUsersPage] : null,
                $canManageUsers ? ['label' => 'WORKSPACE MEMBERS', 'icon' => 'fas fa-users', 'href' => route('clean.console.users.index'), 'active' => $isUsersPage] : null,
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
            ['label' => 'CAMPAIGNS', 'id' => 'nav-submenu-campaigns', 'icon' => 'fas fa-envelope', 'open' => $isCampaignsPage || $isCampaignLogsPage || $isCampaignNegativesPage || $isAudienceMixesPage || $isGsendPage || $isCampaignStatisticsPage, 'items' => array_values(array_filter([
                $hideDashboardForbiddenDestinations ? null : ['label' => 'SEND', 'icon' => 'fas fa-envelope', 'href' => $campaignsUrl, 'active' => $isCampaignsPage],
                ['label' => 'GSEND', 'icon' => 'fab fa-google', 'open' => $isGsendPage, 'items' => [
                    ['label' => 'PROJECTS', 'icon' => 'fas fa-plus', 'href' => route('clean.console.gcampaigns.projects'), 'active' => $isWorkspaceProjectsPage],
                    ['label' => 'ACCOUNTS', 'icon' => 'fas fa-at', 'href' => route('clean.console.gcampaigns.accounts'), 'active' => $isWorkspaceAccountsPage],
                    ['label' => 'SEND', 'icon' => 'fas fa-envelope'],
                ]],
                $hideDashboardForbiddenDestinations ? null : ['label' => 'NEGATIVES', 'icon' => 'fas fa-sticky-note', 'href' => $campaignNegativesUrl, 'active' => $isCampaignNegativesPage],
                $hideDashboardForbiddenDestinations ? null : ['label' => 'MIXS', 'icon' => 'fas fa-blender', 'href' => $mixesUrl, 'active' => $isAudienceMixesPage],
                ['label' => 'STATISTICS', 'icon' => 'fas fa-chart-line', 'href' => route('clean.console.campaign-statistics.index'), 'active' => $isCampaignStatisticsPage],
                ['label' => 'LOGS', 'icon' => 'fas fa-history', 'href' => $campaignLogsUrl, 'active' => $isCampaignLogsPage],
            ]))],
            ['label' => 'DROPS', 'icon' => 'fas fa-paper-plane', 'open' => $isDropsPage, 'items' => [
                ['label' => 'DROP DRAFTS', 'icon' => 'fas fa-paper-plane', 'href' => $dropDraftsUrl, 'active' => $isDropDraftIndexPage],
                ['label' => 'NEW DROP DRAFT', 'icon' => 'fas fa-plus', 'href' => $dropDraftCreateUrl, 'active' => $isDropDraftEditorPage],
                ['label' => 'MONITORING', 'icon' => 'fas fa-eye', 'href' => $dropsMonitoringUrl, 'active' => $isDropsMonitoringPage],
                ['label' => 'SUPPRESSION', 'icon' => 'fas fa-times', 'href' => route('clean.console.drops.suppression'), 'active' => $routeIs('console.drops.suppression*')],
                ['label' => 'STATISTICS', 'icon' => 'fas fa-chart-line', 'href' => $dropsStatisticsUrl, 'active' => $isDropStatisticsPage],
            ]],
            ['label' => "WARMUP'S", 'icon' => 'fas fa-burn', 'open' => $isWarmupsPage, 'items' => [
                ['label' => 'SEND', 'icon' => 'fas fa-burn', 'href' => $warmupsUrl, 'active' => $isWarmupDraftPage],
                ['label' => 'SEEDS', 'icon' => 'fas fa-book', 'href' => $seedListsUrl, 'active' => $isSeedListsPage],
                ['label' => 'STATISTICS', 'icon' => 'fas fa-chart-line', 'href' => $warmupStatisticsUrl, 'active' => $isWarmupStatisticsPage],
            ]],
            $hideDashboardForbiddenDestinations ? null : ['label' => 'GLOBAL TESTS', 'id' => 'nav-submenu-global-tests', 'icon' => 'fas fa-map-signs', 'open' => $isGlobalTestsPage || $isGlobalTestHistoriesPage, 'items' => array_values(array_filter([
                $hideDashboardForbiddenDestinations ? null : ['label' => 'SEND', 'icon' => 'fas fa-map-signs', 'href' => $globalTestsUrl, 'active' => $isGlobalTestsPage],
                $hideDashboardForbiddenDestinations ? null : ['label' => 'HISTORIES', 'icon' => 'fas fa-eye', 'href' => $globalTestHistoriesUrl, 'active' => $isGlobalTestHistoriesPage],
            ]))],
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
        ]));
        $accessRolePolicy = app(\App\Services\Authorization\AccessRolePolicy::class);
        $navigation = $accessRolePolicy->filterNavigation($navigation, auth()->user(), app('router'));
        $findNavigationGroup = static function (string $label, ?string $section = null) use ($navigation): ?array {
            return collect($navigation)->first(static fn (array $group): bool => $group['label'] === $label
                && ($section === null || data_get($group, 'items.0.section') === $section));
        };
        $flattenNavigationItems = static function (array $items, string $prefix = '') use (&$flattenNavigationItems): array {
            $flattened = [];
            foreach ($items as $item) {
                if (isset($item['items'])) {
                    $nestedPrefix = trim($prefix.' '.$item['label']);
                    array_push($flattened, ...$flattenNavigationItems($item['items'], $nestedPrefix));
                    continue;
                }
                $item['label'] = trim($prefix.' '.$item['label']);
                $flattened[] = $item;
            }

            return $flattened;
        };
        $filterNavigationItems = static fn (array $items, array $excluded): array => array_values(array_filter(
            $items,
            static fn (array $item): bool => ! in_array($item['label'], $excluded, true),
        ));
        $dashboardGroup = $findNavigationGroup('DASHBOARDS');
        $usersGroup = $findNavigationGroup('USERS MANAGEMENT');
        $listsGroup = $findNavigationGroup('ISPS MANAGEMENT');
        $domainsGroup = $findNavigationGroup('DOMAINS');
        $serversGroup = $findNavigationGroup('SERVERS', 'servers');
        $pmtaGroup = $findNavigationGroup('SERVERS', 'pmta');
        $sponsorsGroup = $findNavigationGroup('SPONSORS');
        $offersGroup = $findNavigationGroup('OFFERS');
        $miscellaneousGroup = $findNavigationGroup('MISCELLANEOUS');
        $campaignsGroup = $findNavigationGroup('CAMPAIGNS');
        $dropsGroup = $findNavigationGroup('DROPS');
        $warmupsGroup = $findNavigationGroup("WARMUP'S");
        $globalTestsGroup = $findNavigationGroup('GLOBAL TESTS');
        $networksGroup = $findNavigationGroup('NETWORKS');
        $toolsGroup = $findNavigationGroup('TOOLS');
        $cloudsGroup = $findNavigationGroup('CLOUDS');
        $makeCommandOsNavGroup = static function (string $label, string $icon, ?string $href, bool $active, array $items = [], bool $open = false, ?string $id = null): array {
            return array_filter([
                'label' => $label,
                'icon' => $icon,
                'href' => $href,
                'active' => $active,
                'page_active' => $href !== null && parse_url($href, PHP_URL_PATH) === parse_url(request()->url(), PHP_URL_PATH),
                'items' => $items,
                'open' => $open,
                'id' => $id,
            ], static fn (mixed $value): bool => $value !== null);
        };
        $dashboardStats = array_slice($dashboardGroup['items'] ?? [], 1);
        $campaignItems = $filterNavigationItems($campaignsGroup['items'] ?? [], ['STATISTICS', 'MIXS']);
        $campaignItems = array_map(static function (array $item): array {
            if (($item['label'] ?? null) === 'NEGATIVES') {
                $item['label'] = 'NEGATIVE TOKEN FILES';
                $item['icon'] = 'fas fa-file-alt';
            }

            return $item;
        }, $campaignItems);
        $dropItems = $filterNavigationItems($dropsGroup['items'] ?? [], ['SUPPRESSION', 'STATISTICS']);
        $warmupItems = $filterNavigationItems($warmupsGroup['items'] ?? [], ['STATISTICS']);
        $listItems = $filterNavigationItems($listsGroup['items'] ?? [], ['ISPS']);
        $ispItem = collect($listsGroup['items'] ?? [])->firstWhere('label', 'ISPS');
        $dropSuppression = collect($dropsGroup['items'] ?? [])->firstWhere('label', 'SUPPRESSION');
        $mixListItem = collect($campaignsGroup['items'] ?? [])->firstWhere('label', 'MIXS');
        if ($mixListItem) {
            $mixListItem['label'] = 'MIX LISTS';
            $mixListItem['active'] = $isAudienceMixHistoryPage;
        }
        $campaignStatItem = collect($campaignsGroup['items'] ?? [])->firstWhere('label', 'STATISTICS');
        if ($campaignStatItem) {
            $campaignStatItem['label'] = 'CAMPAIGN STATS';
        }
        $dropStatItem = collect($dropsGroup['items'] ?? [])->firstWhere('label', 'STATISTICS');
        if ($dropStatItem) {
            $dropStatItem['label'] = 'DROP STATS';
        }
        $warmupStatItem = collect($warmupsGroup['items'] ?? [])->firstWhere('label', 'STATISTICS');
        if ($warmupStatItem) {
            $warmupStatItem['label'] = 'WARMUP STATS';
        }
        $trackingItems = array_values(array_filter([
            ...$dashboardStats,
            $campaignStatItem,
            $dropStatItem,
            $warmupStatItem,
        ]));
        $suppressionItems = array_values(array_filter([$dropSuppression]));
        $offerItems = array_values(array_filter([
            $canManageSponsors && $offersGroup ? ['label' => 'OFFERS', 'icon' => $offersGroup['icon'], 'href' => $offersGroup['href'], 'active' => $offersGroup['active']] : null,
            ...$flattenNavigationItems($networksGroup['items'] ?? [], 'NETWORKS'),
        ]));
        $audienceItems = array_values(array_filter([
            $ispItem ? ['label' => 'ISP & GEO', 'icon' => 'fas fa-map-marker-alt', 'href' => $ispsUrl, 'active' => $isIspPage] : null,
            ...$flattenNavigationItems($listItems),
            $mixListItem,
            $hideDashboardForbiddenDestinations ? null : ['label' => 'CREATE MIX', 'icon' => 'fas fa-blender', 'href' => $createMixUrl, 'active' => $isAudienceMixBuilderPage],
        ]));
        $operationItems = array_merge(
            $flattenNavigationItems($toolsGroup['items'] ?? []),
            $flattenNavigationItems($globalTestsGroup['items'] ?? [], 'GLOBAL TESTS'),
            $filterNavigationItems($miscellaneousGroup['items'] ?? [], ['SETTINGS']),
        );
        $settingsItems = array_merge(
            $flattenNavigationItems($usersGroup['items'] ?? []),
            array_values(array_filter($miscellaneousGroup['items'] ?? [], static fn (array $item): bool => $item['label'] === 'SETTINGS')),
            auth()->user()->hasFullAccess() && $workspace ? [[
                'label' => 'TEAM TASKS & MESSAGES',
                'icon' => 'fas fa-inbox',
                'href' => route('console.workspace-communications.index', ['workspace' => $workspace->getKey()]),
                'active' => $isWorkspaceCommunicationsPage,
            ]] : [],
        );
        $serverProviderItem = collect($serversGroup['items'] ?? [])->firstWhere('label', 'SERVERS PROVIDERS');
        $cloudProviderItem = collect($serversGroup['items'] ?? [])->firstWhere('label', 'CLOUD PROVIDERS');
        $domainProviderItem = collect($domainsGroup['items'] ?? [])->firstWhere('label', 'DOMAINS PROVIDERS');
        $providerNetworkItems = array_values(array_filter([
            $serverProviderItem,
            $cloudProviderItem,
            $domainProviderItem,
            $canManageSponsors && $sponsorsGroup ? ['label' => 'SPONSORS', 'icon' => $sponsorsGroup['icon'], 'href' => $sponsorsGroup['href'], 'active' => $sponsorsGroup['active']] : null,
            ...($cloudsGroup['items'] ?? []),
        ]));
        $mailServerItems = array_values(array_filter($serversGroup['items'] ?? [], static fn (array $item): bool => ! in_array($item['label'] ?? '', ['SERVERS PROVIDERS', 'CLOUD PROVIDERS'], true) && ! str_starts_with($item['label'] ?? '', 'PMTA')));
        $serverResourceItems = array_values(array_filter($pmtaGroup['items'] ?? [], static fn (array $item): bool => in_array($item['label'] ?? '', ['MY SERVERS', 'MY IPS'], true)));
        $pmtaItems = array_values(array_filter($pmtaGroup['items'] ?? [], static fn (array $item): bool => ! in_array($item['label'] ?? '', ['MY SERVERS', 'MY IPS'], true)));
        $mailServerItems = array_merge(
            $flattenNavigationItems($mailServerItems),
            $flattenNavigationItems($serverResourceItems),
        );
        $domainManagementItems = array_values(array_filter($domainsGroup['items'] ?? [], static fn (array $item): bool => ($item['label'] ?? '') !== 'DOMAINS PROVIDERS'));
        $offerSuppressionItems = array_values(array_filter(array_map(static function (array $item): array {
            if (($item['label'] ?? null) === 'SUPPRESSION') {
                $item['label'] = 'OFFER/LIST EXCLUSIONS';
                $item['icon'] = 'fas fa-shield-alt';
            }

            return $item;
        }, $suppressionItems)));
        $offerItems = array_merge($offerItems, $offerSuppressionItems);
        $offerGroupHref = $canManageSponsors ? $offersUrl : (collect($offerSuppressionItems)->first(static fn (array $item): bool => isset($item['href']))['href'] ?? null);
        $offersOrSuppressionActive = $isOfferManagementPage || $isNetworkSponsorsPage || $isNetworkOffersPage || $isCampaignNegativesPage || $routeIs('console.drops.suppression*');
        $analyticsActive = $isDashboardAllStatsPage || $isDashboardIspStatsPage || $isDashboardMailerRevenuePage || $isCampaignStatisticsPage || $isDropStatisticsPage || $isWarmupStatisticsPage;
        $mailInfrastructureActive = $isServersPage || $isServerInstallPage || $isBulkInstallPage || $isChangeDomainPage || $isReinstallPage || $isMyServersPage || $isMyIpsPage;
        $providerNetworkActive = $isServerProvidersPage || $isCloudProvidersPage || $isDomainProvidersPage || $isSponsorManagementPage || $isCloudWorkflowPage;
        $redirectsGroup = $makeCommandOsNavGroup('REDIRECTS & ROTATORS', 'fas fa-link', null, false, [], false, 'nav-v2-redirects-rotators');
        $redirectsGroup['disabled'] = true;
        $redirectsGroup['reason'] = 'No redirect or rotator page is connected yet.';
        $commandOsNavigation = [
            $makeCommandOsNavGroup('OPERATIONS OVERVIEW', 'fas fa-tachometer-alt', route('console.dashboard.main'), $routeIs('console.dashboard.main', 'console.workspace.dashboard.main', 'console.analytics.index'), [], false, 'nav-v2-operations-overview'),
            $makeCommandOsNavGroup('ANALYTICS & INSIGHTS', 'fas fa-chart-bar', route('clean.console.analytics.all-stats'), $analyticsActive, $flattenNavigationItems($trackingItems), $analyticsActive, 'nav-v2-analytics-insights'),
            $makeCommandOsNavGroup('CAMPAIGN STUDIO', 'fas fa-envelope', $hideDashboardForbiddenDestinations ? null : $campaignsUrl, $isCampaignsPage || $isCampaignNegativesPage || $isGsendPage, $flattenNavigationItems($campaignItems), $isCampaignsPage || $isCampaignNegativesPage || $isGsendPage, 'nav-v2-campaign-studio'),
            $makeCommandOsNavGroup('WARMUP OPERATIONS', 'fas fa-fire', $warmupsUrl, $isWarmupDraftPage || $isSeedListsPage, $flattenNavigationItems($warmupItems), $isWarmupDraftPage || $isSeedListsPage, 'nav-v2-warmup-operations'),
            $makeCommandOsNavGroup('MAIL INFRASTRUCTURE', 'fas fa-server', $serversUrl, $mailInfrastructureActive, $mailServerItems, $mailInfrastructureActive, 'nav-v2-mail-infrastructure'),
            $makeCommandOsNavGroup('PROVIDER NETWORK', 'fas fa-network-wired', $serverProvidersUrl, $providerNetworkActive, $flattenNavigationItems($providerNetworkItems), $providerNetworkActive, 'nav-v2-provider-network'),
            $makeCommandOsNavGroup('DOMAIN MANAGEMENT', 'fas fa-globe', $domainsUrl, $isDomainsPage, $flattenNavigationItems($domainManagementItems), $isDomainsPage, 'nav-v2-domain-management'),
            $makeCommandOsNavGroup('OFFERS & SUPPRESSIONS', 'fas fa-tag', $offerGroupHref, $offersOrSuppressionActive, $offerItems, $offersOrSuppressionActive, 'nav-v2-offers-suppressions'),
            $makeCommandOsNavGroup('PMTA MANAGEMENT', 'fas fa-desktop', $pmtaAdminUrl, $isPmtaPage, $flattenNavigationItems($pmtaItems), $isPmtaPage, 'nav-v2-pmta-management'),
            $makeCommandOsNavGroup('AUDIENCE INTELLIGENCE', 'fas fa-users', $listsUrl, $isIspManagementPage || $isAudienceMixesPage, $audienceItems, $isIspManagementPage || $isAudienceMixesPage, 'nav-v2-audience-intelligence'),
            $makeCommandOsNavGroup('OPERATIONS TOOLKIT', 'fas fa-toolbox', route('clean.console.tools.workspace.index'), $isTextMechanicPage || $isEncodingPage || $isSpfScannerPage || $isWorkspaceToolPage || $isGlobalTestsPage || $isGlobalTestHistoriesPage || $isSubscribersPage || $isContactsPage || $isHorizonPage || $isAppHealthPage || $isAppErrorsPage, $flattenNavigationItems($operationItems), $isGlobalTestsPage || $isGlobalTestHistoriesPage || $isTextMechanicPage || $isEncodingPage || $isSpfScannerPage || $isWorkspaceToolPage || $isSubscribersPage || $isContactsPage || $isHorizonPage || $isAppHealthPage || $isAppErrorsPage, 'nav-v2-operations-toolkit'),
            $makeCommandOsNavGroup('DROP CAMPAIGNS (V1)', 'fas fa-layer-group', $dropDraftsUrl, $isDropDraftPage || $isDropsMonitoringPage, $flattenNavigationItems($dropItems), $isDropDraftPage || $isDropsMonitoringPage, 'nav-v2-drop-campaigns'),
            $makeCommandOsNavGroup('WORKSPACE SETTINGS', 'fas fa-cog', $appSettingsUrl, $isAppSettingsPage || $isUsersPage || $isGlobalUsersPage || $isWorkspaceCommunicationsPage, $flattenNavigationItems($settingsItems), $isAppSettingsPage || $isUsersPage || $isGlobalUsersPage || $isWorkspaceCommunicationsPage, 'nav-v2-workspace-settings'),
            $redirectsGroup,
        ];
        $commandOsNavigation = array_values(array_filter($commandOsNavigation, static fn (array $group): bool => $group !== []));
        $commandOsNavigation = $accessRolePolicy->filterNavigation($commandOsNavigation, auth()->user(), app('router'));
        $navigationPreference = \App\Models\AppGlobalPreference::query()->find('navigation_order')?->value ?? [];
        $commandOsNavigation = app(\App\Services\Navigation\GlobalNavigationOrder::class)->apply($commandOsNavigation, $navigationPreference);
        $groupWithActiveChild = collect($commandOsNavigation)
            ->first(static fn (array $group): bool => collect($group['items'] ?? [])->contains(static fn (array $item): bool => ($item['active'] ?? false)));
        $groupWithActivePage = collect($commandOsNavigation)->firstWhere('page_active', true);
        $activeNavigationGroupKey = $groupWithActiveChild['navigation_group_key'] ?? $groupWithActivePage['navigation_group_key'] ?? null;
        foreach ($commandOsNavigation as &$navigationGroup) {
            $isCurrentNavigationGroup = ($navigationGroup['navigation_group_key'] ?? null) === $activeNavigationGroupKey;
            $navigationGroup['active'] = $isCurrentNavigationGroup;
            $navigationGroup['open'] = $isCurrentNavigationGroup && ($navigationGroup['items'] ?? []) !== [];
            unset($navigationGroup['page_active']);
        }
        unset($navigationGroup);
        $canEditGlobalNavigation = auth()->user()?->isOwner() ?? false;
    @endphp
    <div id="page-container" class="sidebar-o enable-page-overlay sidebar-dark side-scroll page-header-fixed {{ $commandOsShell ? 'command-os-page' : 'sidebar-mini' }}">
        @if ($commandOsShell)
            <script>
                (() => {
                    try {
                        if (localStorage.getItem('alphalink-command-os-sidebar-mode') === 'mini') {
                            document.currentScript?.parentElement?.classList.add('sidebar-mini');
                        }
                    } catch (error) {}
                })();
            </script>
        @endif
        <nav id="sidebar" aria-label="Main Navigation" tabindex="-1">
            <div class="content-header bg-white-5">
                <a class="font-w600 text-dual" href="{{ route('console.index') }}" aria-label="AlphaLink">
                    @if ($commandOsShell)
                        <span class="command-os-brand">
                            <img src="{{ asset('console-assets/alphalink-command-os-mark.png') }}" alt="" width="32" height="32">
                            <span class="command-os-brand-copy"><strong>AlphaLink</strong><small>COMMAND OS</small></span>
                            <span class="command-os-version">v2.0</span>
                        </span>
                    @else
                        <span class="smini-visible"><i class="fa fa-envelope text-primary"></i></span>
                        <span class="smini-hide font-size-h5 tracking-wider font-w400 text-uppercase">AlphaLink</span>
                    @endif
                </a>
                <div><button type="button" class="d-lg-none btn btn-sm btn-dual ml-1" data-toggle="layout" data-action="sidebar_close" aria-label="Close navigation"><i class="fa fa-fw fa-times"></i></button></div>
            </div>
            <div class="js-sidebar-scroll">
                <div class="content-side">
                    @php
                        $commandOsNavLabel = static function (string $label) use ($commandOsShell): string {
                            if (! $commandOsShell) {
                                return $label;
                            }

                            $acronyms = [
                                'api' => 'API', 'geo' => 'GEO', 'gsend' => 'GSend', 'ip' => 'IP', 'ips' => 'IPs',
                                'isp' => 'ISP', 'isps' => 'ISPs', 'pmta' => 'PMTA', 'spf' => 'SPF', 'smtp' => 'SMTP', 'vmta' => 'VMTA',
                            ];
                            $words = preg_split('/\s+/u', mb_strtolower(trim($label)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

                            return collect($words)
                                ->map(fn (string $word) => $acronyms[$word] ?? \Illuminate\Support\Str::title($word))
                                ->implode(' ');
                        };
                    @endphp
                    @if ($commandOsShell)
                        <ul class="command-os-navigation">
                            @foreach ($commandOsNavigation as $group)
                                @php
                                    $groupHasChildren = ($group['items'] ?? []) !== [];
                                    $groupIsDisabled = $group['disabled'] ?? false;
                                    $submenuId = $group['id'] ?? 'nav-submenu-'.\Illuminate\Support\Str::slug($group['label']);
                                    $submenuIsOpen = ($group['open'] ?? false) || ($group['active'] ?? false);
                                @endphp
                                <li class="command-os-navigation-item {{ ($group['active'] ?? false) ? 'is-active' : '' }} {{ $groupIsDisabled ? 'is-disabled' : '' }}" data-nav-group="{{ $group['navigation_group_key'] ?? $group['label'] ?? '' }}">
                                    <div class="command-os-navigation-row{{ $groupHasChildren ? '' : ' is-standalone' }}">
                                        @if (isset($group['href']) && ! $groupIsDisabled)
                                            <a class="command-os-navigation-link {{ ($group['active'] ?? false) ? 'active' : '' }}" href="{{ $group['href'] }}" @if (($group['active'] ?? false)) aria-current="page" @endif>
                                                <i class="command-os-navigation-icon {{ $group['icon'] }}" aria-hidden="true"></i><span>{{ $commandOsNavLabel($group['label']) }}</span>
                                            </a>
                                        @else
                                            <span class="command-os-navigation-link {{ $groupIsDisabled ? 'is-disabled' : '' }}" @if ($groupIsDisabled) aria-disabled="true" title="{{ $group['reason'] }}" @endif>
                                                <i class="command-os-navigation-icon {{ $group['icon'] }}" aria-hidden="true"></i><span>{{ $commandOsNavLabel($group['label']) }}</span>
                                            </span>
                                        @endif
                                        @if ($groupHasChildren)
                                            <details class="command-os-navigation-disclosure" name="command-os-navigation"{{ $submenuIsOpen ? ' open' : '' }}>
                                                <summary aria-label="Toggle {{ $commandOsNavLabel($group['label']) }} pages" aria-controls="{{ $submenuId }}" title="Toggle {{ $commandOsNavLabel($group['label']) }} pages">
                                                    <i class="fas fa-chevron-down" aria-hidden="true"></i>
                                                </summary>
                                                <ul class="command-os-navigation-submenu" id="{{ $submenuId }}">
                                                    @foreach ($group['items'] as $item)
                                                        <li data-nav-item="{{ $item['navigation_item_key'] ?? $item['label'] ?? '' }}">
                                                            @if (isset($item['href']) && ! ($item['disabled'] ?? false))
                                                                <a class="{{ ($item['active'] ?? false) ? 'active' : '' }}" href="{{ $item['href'] }}" @if (($item['active'] ?? false)) aria-current="page" @endif>
                                                                    <i class="{{ $item['icon'] ?? 'fas fa-circle' }}" aria-hidden="true"></i><span>{{ $commandOsNavLabel($item['label']) }}</span>
                                                                </a>
                                                            @else
                                                                <span aria-disabled="true" title="This destination is not connected in the current release.">
                                                                    <i class="{{ $item['icon'] ?? 'fas fa-circle' }}" aria-hidden="true"></i><span>{{ $commandOsNavLabel($item['label']) }}</span>
                                                                </span>
                                                            @endif
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </details>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
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
                                            <i class="nav-main-link-icon {{ $group['icon'] }}"></i><span class="nav-main-link-name">{{ $commandOsNavLabel($group['label']) }}</span>
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
                                            <i class="nav-main-link-icon {{ $group['icon'] }}"></i><span class="nav-main-link-name">{{ $commandOsNavLabel($group['label']) }}</span>
                                        </a>
                                        @if ($group['items'] !== [])
                                            <ul class="nav-main-submenu" id="{{ $groupSubmenuId }}">
                                                @foreach ($group['items'] as $item)
                                                    @php($itemIsOpen = ($item['open'] ?? false) || collect($item['items'] ?? [])->contains(fn ($nestedItem) => ($nestedItem['active'] ?? false)))
                                                    <li class="nav-main-item {{ ($item['section'] ?? null) === 'servers' ? 'nav-server-item' : '' }} {{ $itemIsOpen ? 'open' : '' }}">
                                                        @if (isset($item['items']))
                                                            <a class="nav-main-link nav-main-link-submenu" href="#" data-toggle="submenu" aria-expanded="{{ $itemIsOpen ? 'true' : 'false' }}">
                                                                <i class="nav-main-link-icon {{ $item['icon'] }}"></i><span class="nav-main-link-name">{{ $commandOsNavLabel($item['label']) }}</span>
                                                            </a>
                                                            <ul class="nav-main-submenu">
                                                                @foreach ($item['items'] as $nestedItem)
                                                                    <li class="nav-main-item">
                                                                        <a class="nav-main-link {{ ($nestedItem['active'] ?? false) ? 'active' : '' }}" href="{{ $nestedItem['href'] ?? '#' }}" @if (! isset($nestedItem['href'])) aria-disabled="true" tabindex="-1" @endif @if (($nestedItem['active'] ?? false)) aria-current="page" @endif>
                                                                            <i class="nav-main-link-icon {{ $nestedItem['icon'] }}"></i><span class="nav-main-link-name">{{ $commandOsNavLabel($nestedItem['label']) }}</span>
                                                                        </a>
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        @else
                                                            <a class="nav-main-link {{ ($item['active'] ?? false) ? 'active' : '' }}" href="{{ $item['href'] ?? '#' }}" @if (! isset($item['href'])) aria-disabled="true" tabindex="-1" @endif @if (($item['active'] ?? false)) aria-current="page" @endif>
                                                                <i class="nav-main-link-icon {{ $item['icon'] }}"></i><span class="nav-main-link-name">{{ $commandOsNavLabel($item['label']) }}</span>
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
                    @endif
                </div>
            </div>
            @if ($commandOsShell && $canEditGlobalNavigation)
                <div class="command-os-nav-editor" data-navigation-editor data-save-url="{{ route('console.global-navigation.order.update') }}">
                    <button type="button" class="command-os-nav-editor-toggle" data-navigation-toggle aria-label="Arrange navigation" title="Arrange navigation" aria-pressed="false">
                        <i class="fas fa-arrows-alt" aria-hidden="true"></i><span>Arrange navigation</span>
                    </button>
                    <p data-navigation-editor-status role="status" aria-live="polite">Enable to reorder pages and move subpages between sections.</p>
                </div>
            @endif
        </nav>
        @if ($commandOsShell && $canEditGlobalNavigation)
            <dialog class="command-os-nav-save-dialog" id="command-os-nav-save-dialog" aria-labelledby="command-os-nav-save-title">
                <form method="dialog">
                    <h2 id="command-os-nav-save-title">Save navigation changes?</h2>
                    <p>Your page order and section assignments will become the default for everyone in AlphaLink.</p>
                    <p class="command-os-nav-save-error" data-navigation-save-error role="alert" hidden></p>
                    <div>
                        <button type="button" class="command-os-nav-save-cancel" data-navigation-save-cancel>Keep arranging</button>
                        <button type="button" class="command-os-nav-save-discard" data-navigation-save-discard>Discard changes</button>
                        <button type="button" class="command-os-nav-save-submit" data-navigation-save-submit>Save for everyone</button>
                    </div>
                </form>
            </dialog>
        @endif
        @if ($commandOsShell)
            <button type="button" class="command-os-sidebar-backdrop" data-action="sidebar_backdrop" aria-label="Close navigation" hidden tabindex="-1"></button>
        @endif
        <header id="page-header">
            <div class="content-header">
                <div class="d-flex align-items-center command-os-header-leading">
                    <button type="button" class="btn btn-sm btn-dual command-os-header-control d-lg-none" data-toggle="layout" data-action="sidebar_open" aria-label="Open navigation" @if ($commandOsShell) aria-controls="sidebar" aria-expanded="false" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
                    </button>
                    <button type="button" class="btn btn-sm btn-dual command-os-header-control d-none d-lg-inline-block" data-action="sidebar_mini_toggle" aria-label="Toggle navigation width" aria-pressed="{{ $commandOsShell ? 'false' : 'true' }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M9 4v16M14 9l-3 3 3 3" /></svg>
                    </button>
                    @if ($commandOsShell)
                        <button type="button" class="command-os-search-trigger" data-action="command_os_search_open" aria-label="Search pages and modules" aria-haspopup="dialog" aria-controls="command-os-search-dialog" title="Search pages and modules (Ctrl or ⌘ K)">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></svg><span>Search pages and modules…</span><kbd data-command-os-search-shortcut>Ctrl K</kbd>
                        </button>
                    @endif
                    @if (! $commandOsDashboard || $canUseDashboardOperations)
                        <a class="btn btn-sm btn-dual command-os-header-control command-os-header-shortcut" href="{{ $campaignsUrl }}" title="Campaigns" aria-label="Campaigns">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m22 2-7 20-4-9-9-4Z" /><path d="M22 2 11 13" /></svg>
                        </a>
                    @endif
                    @if ($canManagePmta)
                        <a class="btn btn-sm btn-dual command-os-header-control command-os-header-shortcut" href="{{ $pmtaAdminUrl }}" title="PMTA management" aria-label="PMTA management">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="7" rx="2" /><rect x="3" y="13" width="18" height="7" rx="2" /><path d="M7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6" /></svg>
                        </a>
                    @endif
                    @if (! $commandOsDashboard || $canUseDashboardOperations)
                        <a class="btn btn-sm btn-dual command-os-header-control command-os-header-shortcut" href="{{ $globalTestsUrl }}" title="Global test" aria-label="Global test">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M9 3h6M10 3v7l-5.2 8.3A2 2 0 0 0 6.5 21h11a2 2 0 0 0 1.7-2.7L14 10V3" /><path d="M8 15h8" /></svg>
                        </a>
                    @endif
                </div>
                <div class="d-flex align-items-center command-os-header-trailing">
                    @if ($commandOsShell)
                        @if ($workspace && in_array(auth()->user()->access_role, ['owner', 'admin', 'operator'], true))
                            <div class="command-os-communications" id="command-os-communications"
                                data-feed-url="{{ route('console.workspace-communications.feed', ['workspace' => $workspace->getKey()]) }}"
                                data-read-url-template="{{ route('console.workspace-communications.read', ['workspace' => $workspace->getKey(), 'communication' => '__COMMUNICATION__']) }}"
                                data-complete-url-template="{{ route('console.workspace-communications.complete', ['workspace' => $workspace->getKey(), 'communication' => '__COMMUNICATION__']) }}">
                                <div class="command-os-update-ticker" data-update-ticker data-paused="false" role="region" aria-label="Recent tasks and messages">
                                    <div class="command-os-ticker-viewport" data-ticker-viewport><div class="command-os-ticker-track" data-ticker-track aria-live="off"><div class="command-os-ticker-set" data-ticker-primary><span class="command-os-ticker-empty">Loading tasks and messages…</span></div><div class="command-os-ticker-set" data-ticker-copy aria-hidden="true"></div></div></div>
                                    <button type="button" class="command-os-ticker-pause" data-ticker-pause aria-pressed="false" aria-label="Pause the message ticker" title="Pause the message ticker"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M8 5v14M16 5v14"/></svg></button>
                                </div>
                                <button type="button" class="btn btn-sm btn-dual command-os-header-control command-os-inbox-trigger" data-communication-open="inbox" aria-haspopup="dialog" aria-expanded="false" aria-controls="command-os-inbox-popover" aria-label="Open tasks and messages" title="Open tasks and messages">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 13h4l2 3h6l2-3h4" /></svg>
                                    <span class="command-os-header-badge" data-open-task-count hidden>0</span>
                                </button>
                                <div class="command-os-communication-popover command-os-inbox-popover" id="command-os-inbox-popover" role="dialog" aria-labelledby="command-os-inbox-title" hidden>
                                    <div class="command-os-communication-heading"><div><span class="command-os-communication-kicker">WORKSPACE</span><h2 id="command-os-inbox-title">Tasks &amp; messages</h2></div><button type="button" class="command-os-communication-close" data-communication-close aria-label="Close tasks and messages">×</button></div>
                                    <div class="command-os-communication-list" data-inbox-list><p class="command-os-communication-empty">Loading your workspace inbox…</p></div>
                                    @if (auth()->user()->hasFullAccess())
                                        <a class="command-os-communication-footer" href="{{ route('console.workspace-communications.index', ['workspace' => $workspace->getKey()]) }}">Manage tasks and messages <span aria-hidden="true">→</span></a>
                                    @endif
                                </div>
                                <button type="button" class="btn btn-sm btn-dual command-os-header-icon command-os-header-control command-os-notification-link" data-communication-open="notifications" aria-haspopup="dialog" aria-expanded="false" aria-controls="command-os-notification-popover" aria-label="Notifications" title="Notifications">
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg><span class="command-os-header-badge command-os-notification-badge" data-unread-count hidden>0</span>
                                </button>
                                <div class="command-os-communication-popover command-os-notification-popover" id="command-os-notification-popover" role="dialog" aria-labelledby="command-os-notification-title" hidden>
                                    <div class="command-os-communication-heading"><div><span class="command-os-communication-kicker">WORKSPACE</span><h2 id="command-os-notification-title">Notifications</h2></div><button type="button" class="command-os-communication-close" data-communication-close aria-label="Close notifications">×</button></div>
                                    <div class="command-os-communication-list" data-notification-list><p class="command-os-communication-empty">Loading your notifications…</p></div>
                                    @if (auth()->user()->hasFullAccess())
                                        <a class="command-os-communication-footer" href="{{ $appHealthUrl }}"><span>Application health</span><span aria-hidden="true">→</span></a>
                                    @endif
                                </div>
                            </div>
                        @elseif (auth()->user()->hasFullAccess())
                            <a class="btn btn-sm btn-dual command-os-header-icon command-os-header-control command-os-notification-link" href="{{ $appHealthUrl }}" aria-label="Application health" title="Application health">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" /></svg>
                            </a>
                        @endif
                        <div id="command-os-theme-toggle"></div>
                    @endif
                    @if (session()->has('alphalink.impersonator_id'))
                        <form method="post" action="{{ route('console.global-users.impersonation.leave') }}" class="mr-2">@csrf<button class="btn btn-sm btn-warning" type="submit">STOP IMPERSONATION</button></form>
                    @endif
                    <div class="dropdown d-inline-block ml-2 command-os-account-control">
                        <button type="button" class="btn btn-sm btn-dual d-flex align-items-center command-os-account-button" id="page-header-user-dropdown" data-toggle="dropdown" aria-label="Account menu" title="Account menu" aria-haspopup="true" aria-expanded="false">
                            @if (auth()->user()->avatar_path)
                                <img class="command-os-profile-avatar command-os-profile-avatar-image" src="{{ route('console.global-users.avatar', auth()->id()) }}" alt="" aria-hidden="true">
                            @else
                                <span class="command-os-profile-avatar" aria-hidden="true">{{ $headerUserInitials }}</span>
                            @endif
                            <span class="d-none d-sm-inline-block command-os-account-name">{{ $headerUserLabel }}</span><svg class="command-os-account-chevron" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m7 10 5 5 5-5" /></svg>
                        </button>
                        <div class="dropdown-menu dropdown-menu-md dropdown-menu-right p-0 border-0 command-os-account-menu" aria-labelledby="page-header-user-dropdown">
                            <div class="command-os-account-heading">
                                @if (auth()->user()->avatar_path)
                                    <img class="command-os-account-avatar command-os-account-avatar-image" src="{{ route('console.global-users.avatar', auth()->id()) }}" alt="" aria-hidden="true">
                                @else
                                    <span class="command-os-account-avatar" aria-hidden="true"><i class="fas fa-user"></i></span>
                                @endif
                                <div class="command-os-account-identity">
                                    <span class="command-os-account-eyebrow">Signed in</span>
                                    <p>{{ auth()->user()->name ?: auth()->user()->email }}</p>
                                </div>
                            </div>
                            <div class="command-os-account-actions">
                                <a class="dropdown-item command-os-account-action" href="{{ route('auth.profile') }}">
                                    <span class="command-os-account-action-icon" aria-hidden="true"><i class="fas fa-id-card"></i></span>
                                    <span>Profile</span>
                                    <i class="fas fa-arrow-right command-os-account-action-arrow" aria-hidden="true"></i>
                                </a>
                                <form method="post" action="{{ route('logout') }}" class="command-os-account-form">
                                    @csrf
                                    <button class="dropdown-item command-os-account-action is-logout" type="submit">
                                        <span class="command-os-account-action-icon" aria-hidden="true"><i class="fas fa-sign-out-alt"></i></span>
                                        <span>Logout</span>
                                        <i class="fas fa-arrow-right command-os-account-action-arrow" aria-hidden="true"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            @if ($commandOsShell)
                <dialog id="command-os-search-dialog" class="command-os-search-dialog" aria-labelledby="command-os-search-title" aria-describedby="command-os-search-help">
                    <div class="command-os-search-panel">
                        <div class="command-os-search-heading">
                            <div><span class="command-os-search-eyebrow">COMMAND PALETTE</span><h2 id="command-os-search-title">Find a page</h2></div>
                            <button type="button" class="command-os-search-close" data-action="command_os_search_close" aria-label="Close search"><i class="fas fa-times" aria-hidden="true"></i></button>
                        </div>
                        <label class="command-os-search-input-wrap" for="command-os-search-input">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <input id="command-os-search-input" type="search" autocomplete="off" spellcheck="false" placeholder="Search pages and modules…" aria-controls="command-os-search-results" aria-describedby="command-os-search-help" role="combobox" aria-autocomplete="list" aria-expanded="true" aria-activedescendant="">
                            <kbd>ESC</kbd>
                        </label>
                        <p id="command-os-search-help" class="command-os-search-help">Search destinations available to your account.</p>
                        <div id="command-os-search-results" class="command-os-search-results" role="listbox" aria-label="Available pages"></div>
                        <div class="command-os-search-footer"><span><kbd>↑</kbd><kbd>↓</kbd> to navigate</span><span><kbd>↵</kbd> to open</span></div>
                    </div>
                </dialog>
            @endif
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
                    <div class="col-12 py-1 text-center"><a class="font-w600" href="{{ route('console.index') }}">AlphaLink</a> <span>© AlphaLink {{ now()->year }}</span></div>
                </div>
            </div>
        </footer>
    </div>
    <script src="{{ asset('console-assets/assets/js/script.js') }}"></script>
    <script src="{{ asset('console-assets/assets/js/app.js') }}"></script>
    @if ($commandOsShell)
        <script src="{{ asset('console-assets/command-os-drawer.js') }}?v={{ substr(sha1_file(public_path('console-assets/command-os-drawer.js')), 0, 12) }}" defer></script>
        <script src="{{ asset('console-assets/command-os-search.js') }}?v={{ substr(sha1_file(public_path('console-assets/command-os-search.js')), 0, 12) }}" defer></script>
        @if ($workspace && in_array(auth()->user()->access_role, ['owner', 'admin', 'operator'], true) && file_exists(public_path('console-assets/team-communications.js')))
            <script src="{{ asset('console-assets/team-communications.js') }}?v={{ substr(sha1_file(public_path('console-assets/team-communications.js')), 0, 12) }}" defer></script>
        @endif
    @endif
    @if ($isListsPage || $isListHistoryPage || $isSponsorManagementPage || $isNetworkSponsorsPage || $isOfferManagementPage || $isNetworkOffersPage || $isServerInstallPage || $isReinstallPage || $isChangeDomainPage || $isCloudProvidersPage || $isDomainProvidersPage || $isPmtaPage || $isDropsPage || $isWarmupDraftPage || $isWorkspaceToolPage || $isAppSettingsPage || $routeIs('console.drops.suppression*', 'console.campaigns.create', 'console.campaigns.edit', 'console.campaigns.show', 'console.campaigns.clone', 'console.campaigns.index', 'console.mailer.campaigns'))
        <script src="{{ asset('console-assets/plugins/bootstrap_select/bootstrap-select.js') }}"></script>
    @endif
    @if ($isServersPage || $isMyServersPage || $isMyIpsPage || $isServerProvidersPage || $isPmtaPage || $isDropsPage || $routeIs('console.drops.suppression*', 'console.tools.spf.*', 'console.campaigns.visitors') || $isSeedListsPage || $isPmtaHistoryPage || $isIspPage || $isListsPage || $isEmailManagementPage || $isDomainsPage || $isDomainProvidersPage || $isCloudProvidersPage || $isSubscribersPage || $isContactsPage || $isSponsorManagementPage || $isNetworkSponsorsPage || $isOfferManagementPage || $isNetworkOffersPage || $isGlobalUsersPage || $isWorkspaceProjectsPage || $isWorkspaceAccountsPage)
        <script src="{{ asset('console-assets/plugins/datatables/datatables.min.js') }}"></script>
        <script src="{{ asset('console-assets/plugins/datatables/jquery.mark.min.js') }}"></script>
        <script src="{{ asset('console-assets/plugins/datatables/datatables.mark.js') }}"></script>
        <script src="{{ asset('console-assets/assets/js/tables_datatables.js') }}"></script>
    @endif
    @if ($isPmtaPage)
        <script src="{{ asset('console-assets/pmta-controls.js') }}"></script>
        <style>
            dialog.pmta-control-dialog { border: 0; border-radius: .25rem; box-shadow: 0 1rem 3rem rgba(0, 0, 0, .28); max-width: calc(100vw - 2rem); padding: 1.25rem; width: 32rem; }
            dialog.pmta-control-dialog::backdrop { background: rgba(0, 0, 0, .58); }
        </style>
    @endif
    @if ($isServersPage)
        <script src="{{ asset('console-assets/plugins/datatables/natural.js') }}"></script>
        <script src="{{ asset('console-assets/plugins/datatables/ip-address.js') }}"></script>
        <script src="{{ asset('console-assets/server-index.js') }}?v={{ substr(sha1_file(public_path('console-assets/server-index.js')), 0, 12) }}" defer></script>
    @elseif ($isMyServersPage)
        <script src="{{ asset('console-assets/plugins/datatables/natural.js') }}"></script>
        <script src="{{ asset('console-assets/plugins/datatables/ip-address.js') }}"></script>
        <script src="{{ asset('console-assets/my-servers.js') }}?v={{ substr(sha1_file(public_path('console-assets/my-servers.js')), 0, 12) }}" defer></script>
    @elseif ($isMyIpsPage)
        <script src="{{ asset('console-assets/plugins/datatables/natural.js') }}"></script>
    @endif
    @if ($commandOsShell && $canEditGlobalNavigation)
        <script src="{{ asset('console-assets/command-os-navigation-reorder.js') }}?v={{ substr(sha1_file(public_path('console-assets/command-os-navigation-reorder.js')), 0, 12) }}" defer></script>
    @endif
    @stack('scripts')
    <script>
        (() => {
            const pageContainer = document.getElementById('page-container');
            const sidebarToggle = document.querySelector('[data-action="sidebar_mini_toggle"]');
            if (!pageContainer || !sidebarToggle) return;

            const storageKey = 'alphalink-command-os-sidebar-mode';
            const syncSidebarWidth = () => {
                const isMini = pageContainer.classList.contains('sidebar-mini');
                const actionLabel = isMini ? 'Expand navigation' : 'Minimize navigation';
                sidebarToggle.setAttribute('aria-pressed', String(isMini));
                sidebarToggle.setAttribute('aria-label', actionLabel);
                sidebarToggle.title = actionLabel;
            };

            try {
                const savedMode = localStorage.getItem(storageKey);
                if (savedMode === 'mini') pageContainer.classList.add('sidebar-mini');
                if (savedMode === 'expanded') pageContainer.classList.remove('sidebar-mini');
            } catch (error) {}

            syncSidebarWidth();

            sidebarToggle.addEventListener('click', (event) => {
                event.preventDefault();
                if (window.innerWidth > 991) {
                    pageContainer.classList.toggle('sidebar-mini');
                    try {
                        localStorage.setItem(storageKey, pageContainer.classList.contains('sidebar-mini') ? 'mini' : 'expanded');
                    } catch (error) {}
                }

                syncSidebarWidth();
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
