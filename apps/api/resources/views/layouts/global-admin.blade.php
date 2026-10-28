<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Users - DMS')</title>
    <link rel="stylesheet" href="{{ asset('dms-reference/fonts/inter.css') }}">
    <link rel="stylesheet" href="{{ asset('dms-reference/assets/css/theme.css') }}">
    @if (request()->routeIs('console.global-users.index'))
        <link rel="stylesheet" href="{{ asset('dms-reference/plugins/datatables/datatables.min.css') }}">
    @endif
    @stack('styles')
</head>
<body>
    <div id="page-container" class="page-header-fixed page-header-dark sidebar-o sidebar-dark">
        <nav id="sidebar">
            <div class="sidebar-content">
                <div class="content-header content-header-fullrow px-3">
                    <div class="content-header-section text-center align-parent">
                        <a class="font-w600 text-white" href="{{ route('console.global-users.index') }}">ALPHALINK</a>
                    </div>
                </div>
                <div class="content-side">
                    <ul class="nav-main">
                        <li class="nav-main-heading">USERS MANAGEMENT</li>
                        <li class="nav-main-item"><a class="nav-main-link active" href="{{ route('console.global-users.index') }}" aria-current="page"><i class="nav-main-link-icon fas fa-user-friends"></i><span class="nav-main-link-name">USERS</span></a></li>
                    </ul>
                </div>
            </div>
        </nav>
        <header id="page-header">
            <div class="content-header">
                <div class="d-flex align-items-center"><strong>GLOBAL ACCOUNT ADMINISTRATION</strong></div>
                <div class="d-flex align-items-center">
                    @if (session()->has('alphalink.impersonator_id'))
                        <form method="post" action="{{ route('console.global-users.impersonation.leave') }}" class="mr-2">@csrf<button class="btn btn-sm btn-warning" type="submit">STOP IMPERSONATION</button></form>
                    @endif
                    <span class="mr-3">{{ auth()->user()->name ?: auth()->user()->username }}</span>
                    <a class="btn btn-sm btn-dual mr-2" href="{{ route('auth.profile') }}">PROFILE</a>
                    <form method="post" action="{{ route('logout') }}">@csrf<button class="btn btn-sm btn-dual" type="submit">LOGOUT</button></form>
                </div>
            </div>
        </header>
        <main id="main-container">
            <div class="content" id="main_content">@yield('content')</div>
        </main>
        <footer id="page-footer" class="bg-body-light"><div class="content py-1"><span>ALPHALINK · ACCOUNT DIRECTORY</span></div></footer>
    </div>
    <script src="{{ asset('dms-reference/assets/js/script.js') }}"></script>
    <script src="{{ asset('dms-reference/assets/js/app.js') }}"></script>
    @if (request()->routeIs('console.global-users.index'))
        <script src="{{ asset('dms-reference/plugins/datatables/datatables.min.js') }}"></script>
        <script src="{{ asset('dms-reference/plugins/datatables/jquery.mark.min.js') }}"></script>
        <script src="{{ asset('dms-reference/plugins/datatables/datatables.mark.js') }}"></script>
        <script src="{{ asset('dms-reference/assets/js/tables_datatables.js') }}"></script>
    @endif
    @stack('scripts')
</body>
</html>
