@extends('layouts.base')

@section('title', 'Login')

@section('body')
    <main class="auth-login-page">
        <header class="auth-login-header">
            <a class="auth-login-brand" href="{{ route('login') }}" aria-label="AlphaLink Command OS sign in">
                <img src="{{ asset('console-assets/alphalink-command-os-mark.png') }}" alt="" width="38" height="38">
                <span class="auth-login-brand-copy">
                    <strong>AlphaLink</strong>
                    <small>COMMAND OS</small>
                </span>
                <span class="auth-login-version">v2.0</span>
            </a>

            <button class="auth-login-theme-toggle" id="auth-theme-toggle" type="button" aria-label="Switch to dark theme" title="Switch theme">
                <svg class="auth-login-sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
                <svg class="auth-login-moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20.3 15.2A8.5 8.5 0 0 1 8.8 3.7 8.6 8.6 0 1 0 20.3 15.2Z"/><path d="M16 3v4m-2-2h4"/></svg>
                <span class="auth-login-theme-label">Theme</span>
            </button>
        </header>

        <section class="auth-login-shell" aria-label="Sign in to AlphaLink">
            <div class="auth-login-intro">
                <div class="auth-login-intro-copy">
                    <div class="auth-login-kicker"><span aria-hidden="true"></span> EMAIL OPERATIONS, CONNECTED</div>
                    <h1>Your command center<br>for every campaign.</h1>
                    <p>Bring campaigns, audiences, deliverability and infrastructure together in one focused workspace.</p>
                </div>

                <div class="auth-login-network" aria-hidden="true">
                    <svg class="auth-login-network-lines" viewBox="0 0 520 250" preserveAspectRatio="none" focusable="false">
                        <path d="M260 125 260 28M260 125 93 83M260 125 427 83M260 125 132 212M260 125 388 212" />
                        <path d="M93 83 132 212M427 83 388 212" class="auth-login-network-line-soft" />
                    </svg>
                    <span class="auth-login-network-node auth-login-node-campaigns"><i></i>Campaigns</span>
                    <span class="auth-login-network-node auth-login-node-drops"><i></i>Drops</span>
                    <span class="auth-login-network-node auth-login-node-warmups"><i></i>Warmups</span>
                    <span class="auth-login-network-node auth-login-node-lists"><i></i>Lists</span>
                    <span class="auth-login-network-node auth-login-node-domains"><i></i>Domains</span>
                    <span class="auth-login-network-hub"><img src="{{ asset('console-assets/alphalink-command-os-mark.png') }}" alt=""></span>
                    <span class="auth-login-network-orbit auth-login-orbit-one"></span>
                    <span class="auth-login-network-orbit auth-login-orbit-two"></span>
                </div>

                <div class="auth-login-module-list" aria-label="AlphaLink modules">
                    <span>Campaigns</span><span>Audiences</span><span>Deliverability</span><span>Infrastructure</span>
                </div>
            </div>

            <div class="auth-login-panel">
                <div class="auth-login-panel-heading">
                    <span class="auth-login-panel-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" focusable="false"><path d="M12 3 4.5 6v5.2c0 4.8 3.2 8.4 7.5 9.8 4.3-1.4 7.5-5 7.5-9.8V6L12 3Z"/><path d="m9 12 2 2 4-4"/></svg>
                    </span>
                    <div>
                        <span class="auth-login-eyebrow">WORKSPACE ACCESS</span>
                        <h2>Welcome back</h2>
                        <p>Sign in to continue to your AlphaLink workspace.</p>
                    </div>
                </div>

                @if ($errors->any())
                    <div id="login-error" class="auth-login-alert" role="alert" aria-live="polite">
                        <span class="auth-login-alert-icon" aria-hidden="true">!</span>
                        <span>{{ $errors->first() }}</span>
                    </div>
                @endif

                @if (session('status'))
                    <div class="auth-login-alert auth-login-success" role="status" aria-live="polite">
                        {{ session('status') }}
                    </div>
                @endif

                <form class="auth-login-form" method="post" action="{{ route('login.store') }}">
                    @csrf
                    <div class="auth-login-field">
                        <label for="username">Username or email</label>
                        <div class="auth-login-input-wrap">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="3.2"/><path d="M5.5 20v-1.6a6.5 6.5 0 0 1 13 0V20Z"/></svg>
                            <input
                                id="username"
                                name="username"
                                type="text"
                                value="{{ old('username', old('email')) }}"
                                placeholder="Enter your username or email"
                                autocomplete="username"
                                autocapitalize="none"
                                spellcheck="false"
                                required
                                autofocus
                                @if ($errors->has('username') || $errors->has('email')) aria-invalid="true" aria-describedby="login-error" @endif
                            >
                        </div>
                    </div>
                    <div class="auth-login-field">
                        <label for="password">Password</label>
                        <div class="auth-login-input-wrap">
                            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 5v2"/></svg>
                            <input
                                id="password"
                                name="password"
                                type="password"
                                placeholder="Enter your password"
                                autocomplete="current-password"
                                required
                                @if ($errors->has('password')) aria-invalid="true" aria-describedby="login-error" @endif
                            >
                        </div>
                    </div>
                    <div class="auth-login-options">
                        <label class="auth-login-remember" for="remember">
                            <input id="remember" type="checkbox" name="remember" value="1">
                            <span>Remember me</span>
                        </label>
                    </div>
                    <button class="auth-login-submit" type="submit">
                        <span>Sign in to AlphaLink</span>
                        <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false"><path d="M4 10h11M10 5l5 5-5 5" /></svg>
                    </button>
                </form>

                <div class="auth-login-panel-footer"><span></span> Secure access to your operations workspace</div>
            </div>
        </section>

        <footer class="auth-login-footer">
            <span>© {{ date('Y') }} AlphaLink</span>
            <span class="auth-login-footer-divider" aria-hidden="true"></span>
            <span>Command OS</span>
        </footer>
    </main>

    <script>
        (() => {
            const toggle = document.getElementById('auth-theme-toggle');
            const root = document.documentElement;
            const readTheme = () => root.dataset.commandTheme === 'dark' ? 'dark' : 'light';
            const applyTheme = (theme) => {
                root.dataset.commandTheme = theme;
                document.body.dataset.commandTheme = theme;
                toggle.setAttribute('aria-label', theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme');
                toggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
                try { window.localStorage.setItem('alphalink-command-os-theme', theme); } catch { /* Theme remains available for this page. */ }
            };

            applyTheme(readTheme());
            toggle.addEventListener('click', () => applyTheme(readTheme() === 'dark' ? 'light' : 'dark'));
        })();
    </script>
@endsection
