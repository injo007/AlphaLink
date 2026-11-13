@extends('layouts.admin-shell')

@section('title', 'Redirects & Rotators')

@push('styles')
    <style>
        .redirects-page { color: var(--co-ink, #17203e); min-width: 0; width: 100%; }
        .redirects-shell { background: var(--co-surface, #fff); border: 1px solid var(--co-border, #dfe3f1); border-radius: 1rem; box-shadow: 0 8px 26px rgb(29 39 84 / 5%); overflow: hidden; }
        .redirects-hero { align-items: center; background: linear-gradient(115deg, color-mix(in srgb, var(--co-purple, #793df6) 8%, var(--co-surface, #fff)), var(--co-surface, #fff) 62%); border-bottom: 1px solid var(--co-border, #dfe3f1); display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; padding: clamp(1rem, 2.2vw, 1.55rem); }
        .redirects-heading { align-items: center; display: flex; gap: .9rem; min-width: 0; }
        .redirects-mark { align-items: center; background: color-mix(in srgb, var(--co-purple, #793df6) 12%, var(--co-surface, #fff)); border: 1px solid color-mix(in srgb, var(--co-purple, #793df6) 22%, var(--co-border, #dfe3f1)); border-radius: .85rem; color: var(--co-purple, #793df6); display: inline-flex; flex: 0 0 2.8rem; height: 2.8rem; justify-content: center; }
        .redirects-eyebrow { color: var(--co-purple, #793df6); display: block; font-size: .66rem; font-weight: 800; letter-spacing: .12em; margin-bottom: .2rem; text-transform: uppercase; }
        .redirects-heading h1 { color: var(--co-ink, #17203e); font-size: clamp(1.25rem, 2vw, 1.6rem); font-weight: 800; letter-spacing: -.025em; margin: 0; }
        .redirects-heading p { color: var(--co-muted, #5f6b89); font-size: .84rem; margin: .22rem 0 0; max-width: 54rem; }
        .redirects-meta { align-items: center; background: var(--co-surface-muted, #f7f8fd); border: 1px solid var(--co-border, #dfe3f1); border-radius: .7rem; color: var(--co-muted, #5f6b89); display: inline-flex; font-size: .72rem; font-weight: 750; gap: .45rem; padding: .55rem .75rem; white-space: nowrap; }
        .redirects-meta i { color: var(--co-purple, #793df6); }
        .redirects-content { display: grid; gap: 1rem; padding: clamp(.9rem, 1.8vw, 1.35rem); }
        .redirects-metrics { display: grid; gap: .75rem; grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .redirects-metric { background: var(--co-surface, #fff); border: 1px solid var(--co-border, #dfe3f1); border-radius: .8rem; display: flex; gap: .8rem; min-width: 0; padding: .95rem; }
        .redirects-metric-icon { align-items: center; background: color-mix(in srgb, var(--co-purple, #793df6) 10%, var(--co-surface, #fff)); border-radius: .65rem; color: var(--co-purple, #793df6); display: inline-flex; flex: 0 0 2.45rem; height: 2.45rem; justify-content: center; }
        .redirects-metric small { color: var(--co-muted, #5f6b89); display: block; font-size: .68rem; font-weight: 800; letter-spacing: .055em; text-transform: uppercase; }
        .redirects-metric strong { color: var(--co-ink, #17203e); display: block; font-size: 1.25rem; font-variant-numeric: tabular-nums; line-height: 1.25; margin-top: .12rem; }
        .redirects-metric span { color: var(--co-muted, #5f6b89); display: block; font-size: .72rem; margin-top: .15rem; }
        .redirects-note { align-items: flex-start; background: color-mix(in srgb, var(--co-blue, #277bff) 7%, var(--co-surface, #fff)); border: 1px solid color-mix(in srgb, var(--co-blue, #277bff) 22%, var(--co-border, #dfe3f1)); border-radius: .75rem; color: var(--co-ink, #17203e); display: flex; gap: .65rem; padding: .85rem 1rem; }
        .redirects-note i { color: var(--co-blue, #277bff); margin-top: .15rem; }
        .redirects-note p { font-size: .78rem; line-height: 1.55; margin: 0; }
        .redirects-note strong { font-weight: 800; }
        .redirects-section { background: var(--co-surface, #fff); border: 1px solid var(--co-border, #dfe3f1); border-radius: .85rem; min-width: 0; overflow: hidden; }
        .redirects-section-head { align-items: center; border-bottom: 1px solid var(--co-border, #dfe3f1); display: flex; flex-wrap: wrap; gap: .6rem; justify-content: space-between; padding: .95rem 1rem; }
        .redirects-section-head h2 { color: var(--co-ink, #17203e); font-size: .86rem; font-weight: 800; letter-spacing: .045em; margin: 0; text-transform: uppercase; }
        .redirects-section-head p { color: var(--co-muted, #5f6b89); font-size: .74rem; margin: .2rem 0 0; }
        .redirects-count { background: color-mix(in srgb, var(--co-purple, #793df6) 9%, var(--co-surface, #fff)); border: 1px solid color-mix(in srgb, var(--co-purple, #793df6) 18%, var(--co-border, #dfe3f1)); border-radius: 999px; color: var(--co-purple, #793df6); font-size: .7rem; font-weight: 800; padding: .3rem .55rem; }
        .redirects-table-wrap { max-width: 100%; overflow-x: auto; overscroll-behavior-x: contain; }
        .redirects-table { border-collapse: collapse; margin: 0; min-width: 680px; width: 100%; }
        .redirects-table th { background: var(--co-surface-muted, #f7f8fd); border-bottom: 1px solid var(--co-border-strong, #d3d8eb); color: var(--co-muted, #5f6b89); font-size: .67rem; font-weight: 800; letter-spacing: .055em; padding: .7rem .85rem; text-align: left; text-transform: uppercase; white-space: nowrap; }
        .redirects-table td { border-bottom: 1px solid var(--co-border, #dfe3f1); color: var(--co-ink, #17203e); font-size: .78rem; padding: .75rem .85rem; vertical-align: middle; }
        .redirects-table tbody tr:last-child td { border-bottom: 0; }
        .redirects-host { font-family: var(--font-mono, ui-monospace, monospace); font-size: .74rem; overflow-wrap: anywhere; }
        .redirects-status { align-items: center; border: 1px solid var(--co-border, #dfe3f1); border-radius: 999px; display: inline-flex; font-size: .68rem; font-weight: 800; gap: .35rem; padding: .3rem .55rem; white-space: nowrap; }
        .redirects-status.is-ready { background: color-mix(in srgb, #1ca572 9%, var(--co-surface, #fff)); border-color: color-mix(in srgb, #1ca572 25%, var(--co-border, #dfe3f1)); color: #147b55; }
        .redirects-status.is-pending { background: color-mix(in srgb, #d38b22 10%, var(--co-surface, #fff)); border-color: color-mix(in srgb, #d38b22 28%, var(--co-border, #dfe3f1)); color: #8d5716; }
        .redirects-empty { color: var(--co-muted, #5f6b89); font-size: .8rem; padding: 1.5rem 1rem; text-align: center; }
        .redirects-empty i { color: color-mix(in srgb, var(--co-purple, #793df6) 65%, var(--co-muted, #5f6b89)); display: block; font-size: 1.2rem; margin-bottom: .5rem; }
        @media (max-width: 700px) { .redirects-metrics { grid-template-columns: 1fr; } .redirects-hero { align-items: flex-start; } .redirects-content { padding: .75rem; } }
        @media (prefers-reduced-motion: reduce) { .redirects-page *, .redirects-page *::before, .redirects-page *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; } }
        html[data-command-theme='dark'] .redirects-status.is-ready { background: color-mix(in srgb, #21c58b 16%, var(--co-surface, #111d3b)); color: #8df0c5; }
        html[data-command-theme='dark'] .redirects-status.is-pending { background: color-mix(in srgb, #e5a43e 16%, var(--co-surface, #111d3b)); color: #ffd38a; }
    </style>
@endpush

@section('content')
    <main class="redirects-page" aria-labelledby="redirects-title">
        <div class="redirects-shell">
            <header class="redirects-hero">
                <div class="redirects-heading">
                    <span class="redirects-mark" aria-hidden="true"><i class="fas fa-link"></i></span>
                    <div>
                        <span class="redirects-eyebrow">Campaign Studio · Workspace redirects</span>
                        <h1 id="redirects-title">Redirects &amp; Rotators</h1>
                        <p>Check campaign click routing, installed tracking endpoints, and recent redirect activity.</p>
                    </div>
                </div>
                <span class="redirects-meta"><i class="fas fa-shield-alt" aria-hidden="true"></i> Workspace-scoped · read only</span>
            </header>

            <div class="redirects-content">
                <section class="redirects-metrics" aria-label="Redirect summary">
                    <article class="redirects-metric">
                        <span class="redirects-metric-icon" aria-hidden="true"><i class="fas fa-server"></i></span>
                        <div><small>Tracking endpoints ready</small><strong>{{ number_format($readyEndpointCount) }}</strong><span>Installed hosts with tracking and HTTPS enabled</span></div>
                    </article>
                    <article class="redirects-metric">
                        <span class="redirects-metric-icon" aria-hidden="true"><i class="fas fa-mouse-pointer"></i></span>
                        <div><small>Click events · 24 hours</small><strong>{{ number_format($clicksLast24Hours) }}</strong><span>Recorded campaign click requests</span></div>
                    </article>
                    <article class="redirects-metric">
                        <span class="redirects-metric-icon" aria-hidden="true"><i class="fas fa-lock"></i></span>
                        <div><small>Campaign fallback</small><strong>Signed</strong><span>AlphaLink handles clicks when a server tracker is unavailable</span></div>
                    </article>
                </section>

                <aside class="redirects-note" aria-label="Redirect behavior">
                    <i class="fas fa-info-circle" aria-hidden="true"></i>
                    <p><strong>How campaign links work:</strong> sends use the server’s saved tracking hostname only when tracking and HTTPS are enabled. Otherwise, the signed AlphaLink click route resolves the offer saved with the recipient snapshot. Destination rotation is not configured.</p>
                </aside>

                <section class="redirects-section" aria-labelledby="tracking-endpoints-title">
                    <header class="redirects-section-head">
                        <div><h2 id="tracking-endpoints-title">Installed tracking endpoints</h2><p>Readiness is based on saved installation settings.</p></div>
                        <span class="redirects-count">{{ $trackingServers->count() }} installed</span>
                    </header>
                    @if ($trackingServers->isEmpty())
                        <div class="redirects-empty"><i class="fas fa-server" aria-hidden="true"></i>No installed servers are available in this workspace yet.</div>
                    @else
                        <div class="redirects-table-wrap" role="region" aria-label="Installed tracking endpoints" tabindex="0">
                            <table class="redirects-table">
                                <thead><tr><th scope="col">Server</th><th scope="col">Tracking host</th><th scope="col">Tracking</th><th scope="col">HTTPS</th><th scope="col">Readiness</th></tr></thead>
                                <tbody>
                                    @foreach ($trackingServers as $server)
                                        <tr>
                                            <td><strong>{{ $server['name'] }}</strong><br><span class="text-muted small">{{ $server['ip_address'] }}</span></td>
                                            <td class="redirects-host">{{ $server['tracking_host'] ?? 'Not configured' }}</td>
                                            <td>{{ $server['tracking_enabled'] ? 'Enabled' : 'Disabled' }}</td>
                                            <td>{{ $server['https_enabled'] ? 'Enabled' : 'Disabled' }}</td>
                                            <td><span class="redirects-status {{ $server['ready'] ? 'is-ready' : 'is-pending' }}"><i class="fas {{ $server['ready'] ? 'fa-check-circle' : 'fa-exclamation-circle' }}" aria-hidden="true"></i>{{ $server['status'] }}</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>

                <section class="redirects-section" aria-labelledby="recent-redirects-title">
                    <header class="redirects-section-head">
                        <div><h2 id="recent-redirects-title">Recent redirect activity</h2><p>Campaign clicks received in the last 24 hours.</p></div>
                        <span class="redirects-count">{{ $recentClicks->count() }} shown</span>
                    </header>
                    @if ($recentClicks->isEmpty())
                        <div class="redirects-empty"><i class="fas fa-route" aria-hidden="true"></i>No campaign click events have been recorded in the last 24 hours.</div>
                    @else
                        <div class="redirects-table-wrap" role="region" aria-label="Recent campaign click events" tabindex="0">
                            <table class="redirects-table">
                                <thead><tr><th scope="col">Campaign</th><th scope="col">ISP</th><th scope="col">Received at</th><th scope="col">Result</th></tr></thead>
                                <tbody>
                                    @foreach ($recentClicks as $click)
                                        <tr>
                                            <td>{{ $click->campaign_name }}</td>
                                            <td>{{ $click->isp_name ?: '—' }}</td>
                                            <td>{{ \Illuminate\Support\Carbon::parse($click->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}</td>
                                            <td><span class="redirects-status is-ready"><i class="fas fa-check-circle" aria-hidden="true"></i>Recorded</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            </div>
        </div>
    </main>
@endsection
