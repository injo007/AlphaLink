@extends('layouts.base')

@section('title', 'Profile')

@section('body')
    <main class="mx-auto max-w-2xl px-4 py-10 sm:px-6">
        <header class="mb-6">
            <a href="{{ route('console.dashboard.main') }}" class="text-sm text-[var(--color-muted)] underline">← Back to AlphaLink</a>
            <h1 class="mt-4 text-2xl font-semibold tracking-tight">Profile</h1>
            <p class="mt-1 text-sm text-[var(--color-muted)]">Change your account password.</p>
        </header>

        @if ($errors->any())
            <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" aria-live="polite">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ route('auth.change_password') }}" class="space-y-5 rounded-xl border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-sm sm:p-7">
            @csrf
            <div>
                <label class="mb-1.5 block text-sm font-medium" for="current_password">Current password</label>
                <input id="current_password" name="current_password" type="password" class="block w-full rounded-md border border-[var(--color-border)] bg-white px-3 py-2 text-sm shadow-sm focus:border-[var(--color-primary)] focus:outline-none" autocomplete="current-password" required @if ($errors->has('current_password')) aria-invalid="true" @endif>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium" for="password">New password</label>
                <input id="password" name="password" type="password" class="block w-full rounded-md border border-[var(--color-border)] bg-white px-3 py-2 text-sm shadow-sm focus:border-[var(--color-primary)] focus:outline-none" autocomplete="new-password" required @if ($errors->has('password')) aria-invalid="true" @endif>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium" for="password_confirmation">Confirm new password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" class="block w-full rounded-md border border-[var(--color-border)] bg-white px-3 py-2 text-sm shadow-sm focus:border-[var(--color-primary)] focus:outline-none" autocomplete="new-password" required>
            </div>
            <button class="inline-flex items-center rounded-md bg-[var(--color-primary)] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[var(--color-primary-dark)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)] focus:ring-offset-2" type="submit">Change password</button>
        </form>
    </main>
@endsection
