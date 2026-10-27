@extends('layouts.base')

@section('title', 'Login')

@section('body')
    <main class="dms-login-page">
        <div class="dms-login-content">
            <div class="dms-login-icon" aria-hidden="true">
                <svg viewBox="0 0 48 48" focusable="false">
                    <path d="M7 10h34a3 3 0 0 1 3 3v22a3 3 0 0 1-3 3H7a3 3 0 0 1-3-3V13a3 3 0 0 1 3-3Z" />
                    <path d="m5 13 19 15 19-15" />
                </svg>
            </div>

            @if ($errors->any())
                <div id="login-error" class="dms-login-alert" role="alert" aria-live="polite">
                    {{ $errors->first() }}
                </div>
            @endif

            <form class="dms-login-form" method="post" action="{{ route('login.store') }}">
                @csrf
                <div class="dms-login-field">
                    <label class="dms-sr-only" for="username">Username</label>
                    <input
                        id="username"
                        name="username"
                        type="text"
                        value="{{ old('username', old('email')) }}"
                        placeholder="Username"
                        autocomplete="username"
                        autocapitalize="none"
                        spellcheck="false"
                        required
                        autofocus
                        @if ($errors->has('username') || $errors->has('email')) aria-invalid="true" aria-describedby="login-error" @endif
                    >
                </div>
                <div class="dms-login-field">
                    <label class="dms-sr-only" for="password">Password</label>
                    <input
                        id="password"
                        name="password"
                        type="password"
                        placeholder="Password"
                        autocomplete="current-password"
                        required
                        @if ($errors->has('password')) aria-invalid="true" aria-describedby="login-error" @endif
                    >
                </div>
                <label class="dms-login-remember" for="remember">
                    <input id="remember" type="checkbox" name="remember" value="1">
                    <span>Remember Me</span>
                </label>
                <button class="dms-login-submit" type="submit">
                    <span>LOGIN</span>
                    <svg viewBox="0 0 20 20" aria-hidden="true" focusable="false">
                        <path d="M4 10h11M10 5l5 5-5 5" />
                    </svg>
                </button>
            </form>
        </div>

        <footer class="dms-login-footer">
            <strong>{{ config('app.name', 'AlphaLink') }}</strong>
            <span>© {{ date('Y') }}</span>
        </footer>
    </main>
@endsection
