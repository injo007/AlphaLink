@extends('layouts.admin-shell')

@section('title', 'Negative Token Files')

@section('content')
    <div class="command-os-entity-page">
        <!-- 1. Hero Card -->
        <header class="command-os-report-hero" aria-labelledby="negatives-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-ban"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-ban"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">CAMPAIGN MESSAGE DATA // [NEGATIVE] TOKEN</p>
                <h1 id="negatives-hero-title">NEGATIVE TOKEN FILES</h1>
                <p>Private files available to the <code>[negative]</code> message placeholder.</p>
            </div>
            <div class="command-os-hero-actions">
                <div class="command-os-report-hero-range">
                    <span>MESSAGE PLACEHOLDER</span>
                    <strong><code>[negative]</code></strong>
                </div>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </header>

        <!-- 2. KPI Overview Cards -->
        <section class="command-os-all-stats-overview" aria-label="Negative Token Files Overview">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-ban"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Uploaded Files</span>
                    <strong>{{ $negatives->count() }}</strong>
                    <small>Available to your account</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-blue" aria-hidden="true"><i class="fas fa-lock"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Access Scope</span>
                    <strong>UPLOADER</strong>
                    <small>Private to the uploader</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-cyan" aria-hidden="true"><i class="fas fa-file-code"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Format</span>
                    <strong>CSV / TXT / HTML</strong>
                    <small>Message token source</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-emerald" aria-hidden="true"><i class="fas fa-paper-plane"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Placeholder</span>
                    <strong>[negative]</strong>
                    <small>Inserted into message content</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-shield-alt"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Audience Filtering</span>
                    <strong>SEPARATE</strong>
                    <small>Managed in Offer/List Exclusions</small>
                </span>
            </article>
        </section>

        @if (session('status'))
            <div class="alert alert-success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <!-- 3. Info Banner -->
        <div class="command-os-info-banner" role="note">
            <div class="command-os-info-banner-icon" aria-hidden="true">
                <i class="fas fa-info-circle"></i>
            </div>
            <div class="command-os-info-banner-content">
                This file is inserted wherever the campaign message uses <code>[negative]</code>. It does not suppress recipients. For campaign audience exclusions, use <a href="{{ route('console.drops.suppression', $workspace->id) }}">Offer/List Exclusions</a>; those saved matches are automatically applied to campaigns using the same Offer and List.
            </div>
        </div>

        <!-- 4. Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center">
                    <i class="fas fa-file-contract text-purple font-size-lg mr-2"></i>
                    <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">Negative Token Files</h3>
                </div>
                <a class="btn btn-sm btn-primary" href="{{ route('console.campaign-negatives.create', $workspace->id) }}">
                    <i class="fas fa-plus mr-1"></i> ADD TOKEN FILE
                </a>
            </div>

            @if ($negatives->isEmpty())
                <div class="text-center py-5">
                    <div class="text-muted mb-2"><i class="fas fa-ban fa-3x"></i></div>
                    <p class="text-muted mb-3">No negative token files uploaded yet.</p>
                    <a class="btn btn-sm btn-primary" href="{{ route('console.campaign-negatives.create', $workspace->id) }}">
                        <i class="fas fa-upload mr-1"></i> UPLOAD FIRST TOKEN FILE
                    </a>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover w-100">
                        <thead>
                            <tr>
                                <th scope="col">#</th>
                                <th scope="col">NAME</th>
                                <th scope="col">FILE</th>
                                <th scope="col">SIZE</th>
                                <th scope="col">CREATED AT</th>
                                <th scope="col" class="text-right">ACTIONS</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($negatives as $negative)
                                <tr>
                                    <td><span class="badge badge-light border">{{ $negative->id }}</span></td>
                                    <td><strong class="text-purple">{{ $negative->name }}</strong></td>
                                    <td><code>{{ $negative->original_filename }}</code></td>
                                    <td><span class="badge badge-light border">{{ number_format($negative->size_bytes / 1024, 1) }} KB</span></td>
                                    <td>{{ $negative->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td class="text-nowrap text-right">
                                        <div class="command-action-btn-group justify-content-end">
                                            <a class="command-action-btn action-primary" href="{{ route('console.campaign-negatives.download', [$workspace->id, $negative->id]) }}" title="DOWNLOAD NEGATIVE" aria-label="Download {{ $negative->name }}"><i class="fas fa-download"></i></a>
                                            <form class="d-inline" method="POST" action="{{ route('console.campaign-negatives.destroy', [$workspace->id, $negative->id]) }}" onsubmit="return confirm('Delete this negative file? Campaign drafts that selected it will keep the old ID, but the file will be removed.');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="command-action-btn action-danger" type="submit" title="DELETE NEGATIVE" aria-label="Delete {{ $negative->name }}"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
