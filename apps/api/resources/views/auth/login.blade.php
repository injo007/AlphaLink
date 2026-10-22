@extends('layouts.base')

@section('title', 'Sign in')

@section('body')
    <main class="signin-page">
        <section class="signin-story" aria-label="AlphaLink">
            <a class="brand brand-on-dark" href="{{ route('login') }}" aria-label="AlphaLink">
                <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
                <span class="brand-name">alpha<span>link</span></span>
            </a>
            <div class="story-content">
                <p class="eyebrow story-eyebrow"><span class="story-rule"></span> CONTROL WITH CONTEXT</p>
                <h1>Every message begins with <em>permission.</em></h1>
                <p class="story-copy">A clear operations desk for audiences, campaign versions, and the evidence behind them.</p>
                <div class="story-ledger" aria-hidden="true">
                    <div class="ledger-line"><span>CONSENT</span><span class="ledger-track"><i class="track-consent"></i></span><span>01</span></div>
                    <div class="ledger-line"><span>CONTENT</span><span class="ledger-track"><i class="track-content"></i></span><span>02</span></div>
                    <div class="ledger-line"><span>DELIVERY</span><span class="ledger-track"><i class="track-delivery"></i></span><span>03</span></div>
                </div>
            </div>
            <div class="story-footer"><span>BUILT FOR TRACEABLE EMAIL OPERATIONS</span><span>AL / 01</span></div>
        </section>

        <section class="signin-panel" aria-labelledby="signin-title">
            <div class="signin-panel-top"><span>WORKSPACE ACCESS</span><span class="access-mark" aria-hidden="true">↘</span></div>
            <div class="signin-form-wrap">
                <p class="eyebrow form-eyebrow">WELCOME BACK</p>
                <h2 id="signin-title">Sign in to AlphaLink</h2>
                <p class="signin-intro">Use the account provided by your workspace administrator.</p>

                @if ($errors->any())
                    <div class="form-alert" role="alert" aria-live="polite">
                        <span class="alert-mark" aria-hidden="true">!</span>
                        <span>{{ $errors->first() }}</span>
                    </div>
                @endif

                <form class="signin-form" method="post" action="{{ route('login.store') }}" novalidate>
                    @csrf
                    <div class="field-group">
                        <label for="email">Email address</label>
                        <input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" inputmode="email" required autofocus>
                    </div>
                    <div class="field-group">
                        <div class="field-label-row"><label for="password">Password</label><span class="field-note">Private to your account</span></div>
                        <input id="password" name="password" type="password" autocomplete="current-password" required @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-error" @endif>
                        @error('password')<span class="field-error" id="password-error">{{ $message }}</span>@enderror
                    </div>
                    <label class="remember-row"><input type="checkbox" name="remember" value="1"><span class="checkmark" aria-hidden="true"></span><span>Keep me signed in on this device</span></label>
                    <button class="primary-button signin-submit" type="submit"><span>Enter workspace</span><span aria-hidden="true">↗</span></button>
                </form>
                <p class="signin-help">Need access? Contact your workspace administrator.</p>
            </div>
            <div class="signin-panel-foot"><span>ALPHALINK / ACCOUNT SECURITY</span><span>SESSION PROTECTED</span></div>
        </section>
    </main>
@endsection
