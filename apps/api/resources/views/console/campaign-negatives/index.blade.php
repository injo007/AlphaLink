@extends('layouts.admin-shell')

@section('title', 'Negatives')

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
                <p class="command-os-report-eyebrow">CAMPAIGN SUPPRESSION // WORKSPACE NEGATIVES</p>
                <h1 id="negatives-hero-title">NEGATIVES</h1>
                <p>Workspace negative suppression lists and opt-out exclusion files applied during campaign compilation.</p>
            </div>
            <div class="command-os-hero-actions">
                <div class="command-os-report-hero-range">
                    <span>CLUSTER STATUS</span>
                    <strong><i class="fas fa-circle text-success" style="font-size: 8px;"></i> SECURE REPO</strong>
                </div>
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </header>

        <!-- 2. KPI Overview Cards -->
        <section class="command-os-all-stats-overview" aria-label="Negatives Overview">
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-purple" aria-hidden="true"><i class="fas fa-ban"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Total Negatives</span>
                    <strong>{{ $negatives->count() }}</strong>
                    <small>Uploaded exclusion files</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-blue" aria-hidden="true"><i class="fas fa-lock"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Scope Isolation</span>
                    <strong>WORKSPACE</strong>
                    <small>Private to tenant</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-cyan" aria-hidden="true"><i class="fas fa-file-code"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Format</span>
                    <strong>MD5 &amp; PLAIN</strong>
                    <small>Dual hashing support</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-emerald" aria-hidden="true"><i class="fas fa-paper-plane"></i></span>
                <span class="all-stats-overview-copy">
                    <span>Integration</span>
                    <strong>CAMPAIGNS</strong>
                    <small>Selectable in wizard</small>
                </span>
            </article>
            <article class="all-stats-overview-card">
                <span class="all-stats-overview-icon icon-amber" aria-hidden="true"><i class="fas fa-shield-alt"></i></span>
                <span class="all-stats-overview-copy">
                    <span>File Integrity</span>
                    <strong>VERIFIED</strong>
                    <small>Checksum validated</small>
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
                Negative files are private to this workspace and can be selected during campaign setup. The campaign send path consumes the selected file for real-time recipient exclusion.
            </div>
        </div>

        <!-- 4. Table Card -->
        <div class="command-os-table-card">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="d-flex align-items-center">
                    <i class="fas fa-file-contract text-purple font-size-lg mr-2"></i>
                    <h3 class="h6 font-w700 text-uppercase tracking-wider mb-0 text-ink">Negative Suppression Catalogs</h3>
                </div>
                <a class="btn btn-sm btn-primary" href="{{ route('console.campaign-negatives.create', $workspace->id) }}">
                    <i class="fas fa-plus mr-1"></i> ADD NEW NEGATIVE
                </a>
            </div>

            @if ($negatives->isEmpty())
                <div class="text-center py-5">
                    <div class="text-muted mb-2"><i class="fas fa-ban fa-3x"></i></div>
                    <p class="text-muted mb-3">No negative suppression files uploaded yet.</p>
                    <a class="btn btn-sm btn-primary" href="{{ route('console.campaign-negatives.create', $workspace->id) }}">
                        <i class="fas fa-upload mr-1"></i> UPLOAD FIRST NEGATIVE
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
