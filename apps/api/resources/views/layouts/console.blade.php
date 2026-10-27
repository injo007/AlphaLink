@extends('layouts.base')

@section('body')
    <a class="skip-link" href="#main-content">Skip to content</a>
    <div class="console-shell">
        <aside class="sidebar" aria-label="Workspace navigation">
            <a class="brand" href="{{ route('console.workspace', $workspace->id) }}" aria-label="AlphaLink workspace home">
                <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
                <span class="brand-name">alpha<span>link</span></span>
            </a>

            <div class="workspace-switcher">
                <span class="eyebrow">WORKSPACE</span>
                <a href="{{ route('console.index') }}" class="workspace-switcher-link">
                    <span class="workspace-glyph" aria-hidden="true">{{ mb_strtoupper(mb_substr($workspace->name, 0, 1)) }}</span>
                    <span class="workspace-switcher-copy">
                        <strong>{{ $workspace->name }}</strong>
                        <small>{{ $workspace->slug }}</small>
                    </span>
                    <span class="switcher-arrow" aria-hidden="true">↗</span>
                </a>
            </div>

            <nav class="primary-nav" aria-label="Primary">
                <span class="eyebrow nav-caption">OPERATIONS</span>
                <a class="nav-link {{ request()->routeIs('console.workspace') ? 'is-active' : '' }}" href="{{ route('console.workspace', $workspace->id) }}" @if (request()->routeIs('console.workspace')) aria-current="page" @endif>
                    <span class="nav-icon" aria-hidden="true">◫</span><span>Overview</span>
                </a>
                @if (in_array($workspace->pivot->role, ['owner', 'admin', 'operator', 'privacy_officer'], true))
                    <a class="nav-link {{ request()->routeIs('console.analytics.*') ? 'is-active' : '' }}" href="{{ route('console.analytics.index', $workspace->id) }}" @if (request()->routeIs('console.analytics.*')) aria-current="page" @endif>
                        <span class="nav-icon" aria-hidden="true">▥</span><span>Dashboard</span>
                    </a>
                @endif
                @if (in_array($workspace->pivot->role, ['owner', 'admin', 'operator'], true))
                    <a class="nav-link {{ request()->routeIs('console.campaigns.*') ? 'is-active' : '' }}" href="{{ route('console.campaigns.index', $workspace->id) }}" @if (request()->routeIs('console.campaigns.*')) aria-current="page" @endif>
                        <span class="nav-icon" aria-hidden="true">↗</span><span>Campaigns</span>
                    </a>
                @endif
                @if (in_array($workspace->pivot->role, ['owner', 'admin', 'operator', 'privacy_officer'], true))
                    <a class="nav-link {{ request()->routeIs('console.audiences.*') ? 'is-active' : '' }}" href="{{ route('console.audiences.index', $workspace->id) }}" @if (request()->routeIs('console.audiences.*')) aria-current="page" @endif>
                        <span class="nav-icon" aria-hidden="true">▤</span><span>Audience lists</span>
                    </a>
                @endif
                @if (in_array($workspace->pivot->role, ['owner', 'admin'], true))
                    <a class="nav-link {{ request()->routeIs('console.infrastructure.*') ? 'is-active' : '' }}" href="{{ route('console.infrastructure.servers.index', $workspace->id) }}" @if (request()->routeIs('console.infrastructure.*')) aria-current="page" @endif>
                        <span class="nav-icon" aria-hidden="true">▦</span><span>Servers</span>
                    </a>
                @endif
            </nav>

            <div class="sidebar-foot">
                <span class="connection-dot" aria-hidden="true"></span>
                <span>Campaign &amp; audience</span>
                <span class="read-only-tag">READ ONLY</span>
            </div>
        </aside>

        <main class="main-column" id="main-content">
            <header class="topbar">
                <div class="topbar-context">
                    <span class="eyebrow">ALPHALINK <span class="crumb-slash">/</span> {{ $workspace->name }}</span>
                    <span class="topbar-subtitle">Permissioned email operations</span>
                </div>
                <div class="topbar-actions">
                    <span class="role-chip">{{ str_replace('_', ' ', $workspace->pivot->role) }}</span>
                    <div class="user-chip">
                        <span class="user-initial" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()->name ?: auth()->user()->email, 0, 1)) }}</span>
                        <span class="user-name">{{ auth()->user()->name ?: auth()->user()->email }}</span>
                    </div>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button class="signout-button" type="submit">Sign out</button>
                    </form>
                </div>
            </header>

            <div class="content-wrap">
                @yield('content')
            </div>

            <footer class="page-footer">
                <span>ALPHALINK <span class="footer-dot">·</span> OPERATIONS LEDGER</span>
                <span>Workspace-scoped operations. Sending controls are disabled.</span>
            </footer>
        </main>
    </div>
@endsection
