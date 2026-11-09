@extends('layouts.admin-shell')

@section('title', 'Warmup Statistics')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-hero-card">
            <div class="command-os-hero-card-left">
                <div class="command-os-hero-mark" aria-hidden="true">
                    <i class="fas fa-fire"></i>
                </div>
                <div class="command-os-hero-copy">
                    <span class="command-os-hero-eyebrow">TRACKING &amp; ANALYTICS</span>
                    <h1 class="command-os-hero-title">WARMUP STATISTICS</h1>
                    <p class="command-os-hero-subtitle">Unique recipient-action records, delivery engagement, and automated activity grouped by Warmup draft</p>
                </div>
            </div>
            <div class="command-os-hero-card-right d-flex align-items-center">
                <a class="btn btn-sm btn-outline-secondary font-w700 font-size-xs px-3 mr-2" href="{{ route('console.warmups.index', $workspace->id) }}" style="border-radius: 8px;">
                    <i class="fas fa-layer-group mr-1"></i> WARMUP DRAFTS
                </a>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="si si-size-fullscreen"></i>
                </button>
            </div>
        </header>

        <!-- 2. KPI Overview Cards -->
        <section class="command-os-all-stats-overview" aria-label="Warmup KPI summary">
            @php
                $metricConfig = [
                    ['Unique Opens', 'opens', 'fas fa-envelope-open', 'records'],
                    ['Unique Clicks', 'clicks', 'fas fa-mouse-pointer', 'events'],
                    ['Leads', 'leads', 'fas fa-funnel-dollar', 'leads'],
                    ['Unsubscribes', 'unsubscribes', 'fas fa-user-minus', 'scope'],
                    ['Automated Traffic', 'bots', 'fas fa-robot', 'pivot'],
                ];
            @endphp
            @foreach ($metricConfig as [$label, $key, $icon, $iconClass])
                <article class="all-stats-overview-card">
                    <span class="all-stats-overview-icon icon-{{ $iconClass }}" aria-hidden="true">
                        <i class="{{ $icon }}"></i>
                    </span>
                    <span class="all-stats-overview-copy">
                        <span>{{ $label }}</span>
                        <strong>{{ number_format($summary[$key] ?? 0) }}</strong>
                        <small>Recorded events</small>
                    </span>
                </article>
            @endforeach
        </section>

        <!-- 3. Filter Bar Card -->
        <section class="command-os-filter-card" aria-label="Filter warmup statistics">
            <form class="form-row align-items-end" method="get" action="{{ route('console.warmups.statistics', $workspace->id) }}">
                <div class="form-group col-sm-12 col-md-4 col-lg-3 mb-2 mb-md-0">
                    <label for="warmup-stats-from" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                        <i class="far fa-calendar-alt mr-1 text-purple"></i> Date From:
                    </label>
                    <div class="command-input-wrap">
                        <input class="form-control" id="warmup-stats-from" name="from" type="date" max="{{ today()->toDateString() }}" value="{{ $from }}" required style="height: 42px;">
                    </div>
                </div>
                <div class="form-group col-sm-12 col-md-4 col-lg-3 mb-2 mb-md-0">
                    <label for="warmup-stats-to" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                        <i class="far fa-calendar-alt mr-1 text-purple"></i> Date To:
                    </label>
                    <div class="command-input-wrap">
                        <input class="form-control" id="warmup-stats-to" name="to" type="date" max="{{ today()->toDateString() }}" value="{{ $to }}" required style="height: 42px;">
                    </div>
                </div>
                <div class="form-group col-sm-12 col-md-4 col-lg-2 mb-0">
                    <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1 d-none d-md-block" style="visibility:hidden;">&nbsp;</label>
                    <button class="command-os-btn-filter w-100" type="submit" style="height: 42px;">
                        <i class="fas fa-search mr-1"></i> FILTER
                    </button>
                </div>
            </form>
        </section>

        <!-- 4. Info Banner -->
        <div class="command-os-info-banner py-2 px-3 font-size-xs" role="note">
            <div class="command-os-info-banner-icon"><i class="fas fa-info-circle"></i></div>
            <div class="command-os-info-banner-content text-muted">
                Counts below use AlphaLink's unique recipient-action records, grouped by Warmup draft. Opens, clicks, leads, unsubscribes, and automated traffic are distinct actions. This report does not infer deliveries or opt-out attribution from send totals.
            </div>
        </div>

        <!-- 5. Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom">
                <h4 class="font-w700 text-uppercase font-size-sm mb-0">
                    <i class="fas fa-table mr-2 text-purple"></i> Unique Actions by Warmup
                </h4>
            </div>

            @if ($rows->isEmpty())
                <div class="command-table-empty-state py-5 text-center" role="status">
                    <div class="command-empty-state-icon mb-3" style="font-size: 2.2rem; color: #8b5cf6; opacity: 0.85;">
                        <i class="fas fa-inbox"></i>
                    </div>
                    <h4 class="font-w600 text-ink mb-1">No Warmup Actions in This Date Range</h4>
                    <p class="text-muted font-size-sm mb-0">This report uses saved AlphaLink tracking actions. It does not generate sample or estimated activity.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-striped w-100">
                        <caption class="sr-only">Unique recipient actions by Warmup</caption>
                        <thead>
                            <tr>
                                <th scope="col">WARMUP</th>
                                <th scope="col" class="text-right">OPENS</th>
                                <th scope="col" class="text-right">CLICKS</th>
                                <th scope="col" class="text-right">LEADS</th>
                                <th scope="col" class="text-right">UNSUBSCRIBES</th>
                                <th scope="col" class="text-right">AUTOMATED</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <th scope="row" class="font-w600 text-purple">{{ $row['name'] }}</th>
                                    <td class="text-right font-w600" style="font-family: var(--font-mono, monospace);">{{ number_format($row['opens']) }}</td>
                                    <td class="text-right font-w600 text-primary" style="font-family: var(--font-mono, monospace);">{{ number_format($row['clicks']) }}</td>
                                    <td class="text-right font-w600 text-success" style="font-family: var(--font-mono, monospace);">{{ number_format($row['leads']) }}</td>
                                    <td class="text-right font-w600 text-warning" style="font-family: var(--font-mono, monospace);">{{ number_format($row['unsubscribes']) }}</td>
                                    <td class="text-right font-w600 text-muted" style="font-family: var(--font-mono, monospace);">{{ number_format($row['bots']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
