<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self' data:; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; form-action 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'">
    <title>@yield('title', 'Servers - DMS')</title>
    <link rel="stylesheet" href="{{ asset('dms-reference/fonts/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('dms-reference/plugins/bootstrap_select/bootstrap-select.css') }}">
    @if (request()->routeIs('console.infrastructure.servers.index'))
        <link rel="stylesheet" href="{{ asset('dms-reference/plugins/datatables/datatables.min.css') }}">
    @endif
    <link rel="stylesheet" href="{{ asset('dms-reference/assets/css/theme.css') }}">
    @stack('styles')
</head>
<body>
    @php
        $serversUrl = route('console.infrastructure.servers.index', $workspace->id);
        $bulkInstallUrl = route('console.infrastructure.bulk-install', $workspace->id);
        $isServersPage = request()->routeIs('console.infrastructure.servers.index');
        $navigation = [
            ['label' => 'DASHBOARDS', 'icon' => 'fas fa-tachometer-alt', 'items' => [
                ['label' => 'MAIN', 'icon' => 'fas fa-tachometer-alt'],
                ['label' => 'ALL STATS', 'icon' => 'fas fa-hospital-alt'],
                ['label' => 'ISP STATS', 'icon' => 'fas fa-envelope-open'],
                ['label' => 'MAILERS REV STATS', 'icon' => 'fas fa-user-alt'],
            ]],
            ['label' => 'USERS MANAGEMENT', 'icon' => 'fas fa-users-cog', 'items' => [
                ['label' => 'USERS', 'icon' => 'fas fa-user-friends'],
            ]],
            ['label' => 'ISPS MANAGEMENT', 'icon' => 'far fa-envelope', 'items' => [
                ['label' => 'ISPS', 'icon' => 'fas fa-envelope-open'],
                ['label' => 'LISTS', 'icon' => 'fas fa-file'],
                ['label' => 'LIST HISTORY', 'icon' => 'fas fa-chart-area'],
                ['label' => 'SEARCH EMAIL', 'icon' => 'fas fa-search'],
                ['label' => 'BLACKLIST CHECKER', 'icon' => 'fas fa-trash-alt'],
            ]],
            ['label' => 'DOMAINS', 'icon' => 'fas fa-cloud', 'items' => [
                ['label' => 'DOMAINS PROVIDERS', 'icon' => 'fas fa-tasks'],
                ['label' => 'DOMAINS', 'icon' => 'fas fa-cloud'],
            ]],
            ['label' => 'SERVERS', 'icon' => 'fas fa-server', 'items' => [
                ['label' => 'SERVERS PROVIDERS', 'icon' => 'fas fa-tasks'],
                ['label' => 'SERVERS', 'icon' => 'fas fa-server', 'href' => $serversUrl, 'active' => true],
                ['label' => 'INSTALL', 'icon' => 'fas fa-hammer'],
                ['label' => 'BULK INSTALL', 'icon' => 'fas fa-tools'],
                ['label' => 'CHANGE DOMAIN', 'icon' => 'fas fa-exchange-alt'],
                ['label' => 'REINSTALL SERVER', 'icon' => 'fas fa-redo'],
                ['label' => 'CLOUD PROVIDERS', 'icon' => 'fas fa-cloud-sun'],
            ]],
            ['label' => 'SPONSORS', 'icon' => 'fas fa-hand-holding-usd', 'href' => '#', 'disabled' => true],
            ['label' => 'OFFERS', 'icon' => 'fas fa-shopping-bag', 'href' => '#', 'disabled' => true],
            ['label' => 'MISCELLANEOUS', 'icon' => 'fas fa-ellipsis-h', 'items' => [
                ['label' => 'SUBSCRIBERS', 'icon' => 'fas fa-newspaper'],
                ['label' => 'CONTACTS', 'icon' => 'fas fa-address-book'],
                ['label' => 'HORIZON', 'icon' => 'fas fa-balance-scale'],
                ['label' => 'APP HEALTH', 'icon' => 'fas fa-heartbeat'],
                ['label' => 'APP SETTINGS', 'icon' => 'fas fa-cogs'],
                ['label' => 'APP ERRORS', 'icon' => 'fas fa-exclamation-circle'],
            ]],
            ['label' => 'CAMPAIGNS', 'icon' => 'fas fa-envelope', 'items' => [
                ['label' => 'SEND', 'icon' => 'fas fa-envelope'],
                ['label' => 'GSEND', 'icon' => 'fab fa-google', 'open' => true, 'items' => [
                    ['label' => 'PROJECTS', 'icon' => 'fas fa-plus'],
                    ['label' => 'ACCOUNTS', 'icon' => 'fas fa-at'],
                    ['label' => 'SEND', 'icon' => 'fas fa-envelope'],
                ]],
                ['label' => 'NEGATIVES', 'icon' => 'fas fa-sticky-note'],
                ['label' => 'MIXS', 'icon' => 'fas fa-blender'],
                ['label' => 'STATISTICS', 'icon' => 'fas fa-chart-line'],
                ['label' => 'LOGS', 'icon' => 'fas fa-eye'],
            ]],
            ['label' => 'DROPS', 'icon' => 'fas fa-paper-plane', 'items' => [
                ['label' => 'RECENT DROP', 'icon' => 'fas fa-paper-plane'],
                ['label' => 'FRESH DROP', 'icon' => 'fas fa-plus'],
                ['label' => 'MONITORING', 'icon' => 'fas fa-eye'],
                ['label' => 'SUPPRESSION', 'icon' => 'fas fa-times'],
                ['label' => 'STATISTICS', 'icon' => 'fas fa-chart-line'],
            ]],
            ['label' => "WARMUP'S", 'icon' => 'fas fa-burn', 'items' => [
                ['label' => 'SEND', 'icon' => 'fas fa-burn'],
                ['label' => 'SEEDS', 'icon' => 'fas fa-book'],
                ['label' => 'STATISTICS', 'icon' => 'fas fa-chart-line'],
            ]],
            ['label' => 'GLOBAL TESTS', 'icon' => 'fas fa-map-signs', 'items' => [
                ['label' => 'SEND', 'icon' => 'fas fa-map-signs'],
                ['label' => 'HISTORIES', 'icon' => 'fas fa-eye'],
            ]],
            ['label' => 'SERVERS', 'icon' => 'fas fa-server', 'items' => [
                ['label' => 'MY SERVERS', 'icon' => 'fas fa-server'],
                ['label' => 'MY IPS', 'icon' => 'fas fa-server'],
                ['label' => 'PMTA', 'icon' => 'fas fa-desktop'],
                ['label' => 'PMTA SCHEDULER', 'icon' => 'fas fa-clock'],
                ['label' => 'PMTA SETTINGS', 'icon' => 'fas fa-cog'],
                ['label' => 'PMTA VMTA', 'icon' => 'fas fa-bus'],
                ['label' => 'PMTA SMTP', 'icon' => 'fas fa-bus-alt'],
                ['label' => 'PMTA ERRORS', 'icon' => 'fas fa-exclamation'],
                ['label' => 'PMTA ACTION HISTORY', 'icon' => 'fas fa-eye'],
                ['label' => 'PMTA CONFIG HISTORY', 'icon' => 'fas fa-eye'],
            ]],
            ['label' => 'NETWORKS', 'icon' => 'fas fa-network-wired', 'items' => [
                ['label' => 'SPONSORS', 'icon' => 'fas fa-shopping-cart'],
                ['label' => 'OFFERS', 'icon' => 'fas fa-shopping-bag'],
            ]],
            ['label' => 'TOOLS', 'icon' => 'fas fa-toolbox', 'items' => [
                ['label' => 'BLACKLIST CHECKER', 'icon' => 'fas fa-circle'],
                ['label' => 'ENCODING', 'icon' => 'fas fa-circle'],
                ['label' => 'SPF SCANNER', 'icon' => 'fas fa-circle'],
                ['label' => 'OUTLOOK', 'icon' => 'fas fa-circle'],
                ['label' => 'WORKSPACE', 'icon' => 'fas fa-circle'],
                ['label' => 'SHORTLINKS', 'icon' => 'fas fa-circle'],
                ['label' => 'REPLY EXTRACTOR', 'icon' => 'fas fa-circle'],
                ['label' => 'SPAM CHECKER', 'icon' => 'fas fa-circle'],
                ['label' => 'IMGUR', 'icon' => 'fas fa-circle'],
                ['label' => 'AFRAID', 'icon' => 'fas fa-circle'],
                ['label' => 'FIREBASE', 'icon' => 'fas fa-fire'],
                ['label' => 'TEXT MECHANIC', 'icon' => 'fas fa-circle'],
            ]],
            ['label' => 'CLOUDS', 'icon' => 'fas fa-cloud-sun', 'items' => [
                ['label' => 'LINODE', 'icon' => 'fab fa-linode'],
                ['label' => 'SERVER SPACE', 'icon' => 'fas fa-circle'],
                ['label' => 'DIGITAL OCEAN', 'icon' => 'fab fa-digital-ocean'],
                ['label' => 'VULTR', 'icon' => 'fas fa-circle'],
                ['label' => 'REG.RU', 'icon' => 'fas fa-circle'],
                ['label' => 'AMAZON EC2', 'icon' => 'fab fa-amazon'],
                ['label' => 'AZURE COMPUTE', 'icon' => 'fab fa-microsoft'],
                ['label' => 'HETZNER', 'icon' => 'fas fa-circle'],
                ['label' => 'INSTALL', 'icon' => 'fas fa-hammer'],
                ['label' => 'BULK INSTALL', 'icon' => 'fas fa-tools', 'href' => $bulkInstallUrl, 'active' => request()->routeIs('console.infrastructure.bulk-install')],
            ]],
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
                            @if ($groupIndex === 8)
                                <li class="nav-main-heading">SEND SECTION</li>
                            @endif
                            @if (isset($group['href']))
                                <li class="nav-main-item">
                                    <a class="nav-main-link" href="{{ $group['href'] }}" @if ($group['disabled'] ?? false) aria-disabled="true" tabindex="-1" @endif>
                                        <i class="nav-main-link-icon {{ $group['icon'] }}"></i><span class="nav-main-link-name">{{ $group['label'] }}</span>
                                    </a>
                                </li>
                            @else
                                @php($groupIsOpen = collect($group['items'])->contains(fn ($item) => ($item['active'] ?? false)))
                                <li class="nav-main-item {{ $groupIsOpen ? 'open' : '' }}">
                                    <a class="nav-main-link nav-main-link-submenu" href="#" data-toggle="submenu" aria-expanded="{{ $groupIsOpen ? 'true' : 'false' }}">
                                        <i class="nav-main-link-icon {{ $group['icon'] }}"></i><span class="nav-main-link-name">{{ $group['label'] }}</span>
                                    </a>
                                    @if ($group['items'] !== [])
                                        <ul class="nav-main-submenu">
                                            @foreach ($group['items'] as $item)
                                                @php($itemIsOpen = ($item['open'] ?? false) || collect($item['items'] ?? [])->contains(fn ($nestedItem) => ($nestedItem['active'] ?? false)))
                                                <li class="nav-main-item {{ $itemIsOpen ? 'open' : '' }}">
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
                    <button type="button" class="btn btn-sm btn-dual mr-2 d-none d-lg-inline-block" data-toggle="layout" data-action="sidebar_mini_toggle" aria-label="Toggle navigation width"><i class="fa fa-fw fa-ellipsis-v"></i></button>
                    <a class="btn btn-sm btn-dual mr-2" href="#" title="CAMPAIGNS"><i class="fas fa-envelope"></i></a>
                    <a class="btn btn-sm btn-dual mr-2" href="#" title="DROPS"><i class="fas fa-paper-plane"></i></a>
                    <a class="btn btn-sm btn-dual mr-2" href="#" title="WARMUP'S"><i class="fas fa-burn"></i></a>
                    <a class="btn btn-sm btn-dual mr-2" href="#" title="PMTA ADMIN"><i class="fas fa-desktop"></i></a>
                    <a class="btn btn-sm btn-dual mr-2" href="#" title="GLOBAL TEST"><i class="fas fa-map-signs"></i></a>
                </div>
                <div class="d-flex align-items-center">
                    <div class="dropdown d-inline-block ml-2">
                        <button type="button" class="btn btn-sm btn-dual d-flex align-items-center" id="page-header-user-dropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fa fa-user d-sm-none"></i><span class="d-none d-sm-inline-block ml-1">{{ auth()->user()->name ?: auth()->user()->email }}</span><i class="fa fa-angle-down ml-1"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-md dropdown-menu-right p-0 border-0" aria-labelledby="page-header-user-dropdown">
                            <div class="p-3 text-center bg-primary-dark rounded-top"><p class="text-white font-w600 mb-0">{{ auth()->user()->name ?: auth()->user()->email }}</p></div>
                            <div class="p-2">
                                <a class="dropdown-item d-flex align-items-center justify-content-between" href="#">PROFILE</a>
                                <form method="post" action="{{ route('logout') }}">@csrf<button class="dropdown-item d-flex align-items-center justify-content-between w-100" type="submit">LOGOUT</button></form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>
        <main id="main-container">
            <div class="content content-full p-0"></div>
            <div class="content" id="main_content">
                @yield('content')
            </div>
        </main>
        <footer id="page-footer" class="bg-body-light">
            <div class="content py-1">
                <div class="row font-size-sm">
                    <div class="col-sm-6 order-sm-2 py-1 text-center text-sm-right"><i class="fa fa-heart text-danger" title="With Love"></i></div>
                    <div class="col-sm-6 order-sm-1 py-1 text-center text-sm-left"><a class="font-w600" href="#">PXLRBLDLNK</a> <span>© DMS {{ now()->year }}</span></div>
                </div>
            </div>
        </footer>
    </div>
    <script src="{{ asset('dms-reference/assets/js/script.js') }}"></script>
    <script src="{{ asset('dms-reference/assets/js/app.js') }}"></script>
    @if ($isServersPage)
        <script src="{{ asset('dms-reference/plugins/datatables/datatables.min.js') }}"></script>
        <script src="{{ asset('dms-reference/plugins/datatables/natural.js') }}"></script>
        <script src="{{ asset('dms-reference/plugins/datatables/ip-address.js') }}"></script>
        <script src="{{ asset('dms-reference/plugins/datatables/jquery.mark.min.js') }}"></script>
        <script src="{{ asset('dms-reference/plugins/datatables/datatables.mark.js') }}"></script>
        <script src="{{ asset('dms-reference/assets/js/tables_datatables.js') }}"></script>
        <script src="{{ asset('dms-reference/server-index.js') }}" defer></script>
    @endif
    @stack('scripts')
</body>
</html>
