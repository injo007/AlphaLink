@extends('layouts.base')

@section('title', 'Workspaces')

@section('body')
    <main class="workspace-picker-page">
        <header class="picker-header">
            <a class="brand" href="{{ route('console.index') }}" aria-label="AlphaLink">
                <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
                <span class="brand-name">alpha<span>link</span></span>
            </a>
            <div class="topbar-actions">
                <span class="user-name">{{ auth()->user()->name ?: auth()->user()->email }}</span>
                <form method="post" action="{{ route('logout') }}">@csrf<button class="signout-button" type="submit">Sign out</button></form>
            </div>
        </header>
        <section class="picker-content">
            <p class="eyebrow form-eyebrow">YOUR ACCESS</p>
            @if ($workspaces->isEmpty())
                <h1>No workspace is connected yet.</h1>
                <p class="picker-copy">Your account is signed in, but it is not a member of an active AlphaLink workspace. Ask an administrator to add your account.</p>
                <div class="empty-note"><span class="empty-note-dot" aria-hidden="true"></span><span>Workspace access is assigned by an administrator.</span></div>
            @else
                <h1>Choose a workspace.</h1>
                <p class="picker-copy">Select the workspace whose operations you want to review.</p>
                <div class="workspace-cards">
                    @foreach ($workspaces as $workspace)
                        <a class="workspace-card" href="{{ route('console.workspace', $workspace->id) }}">
                            <span class="workspace-glyph" aria-hidden="true">{{ mb_strtoupper(mb_substr($workspace->name, 0, 1)) }}</span>
                            <span class="workspace-card-copy"><strong>{{ $workspace->name }}</strong><small>{{ $workspace->slug }}</small></span>
                            <span class="workspace-card-role">{{ str_replace('_', ' ', $workspace->pivot->role) }}</span>
                            <span class="card-arrow" aria-hidden="true">↗</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
        <footer class="picker-footer"><span>ALPHALINK · OPERATIONS LEDGER</span><span>WORKSPACE ACCESS IS SCOPED TO YOUR MEMBERSHIP</span></footer>
    </main>
@endsection
