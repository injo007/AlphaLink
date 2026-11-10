@extends('layouts.admin-shell')

@section('title', 'Mix Lists')

@section('content')
@php($canCreateMix = in_array(auth()->user()?->access_role, [\App\Models\User::ACCESS_ROLE_OWNER, \App\Models\User::ACCESS_ROLE_ADMIN, \App\Models\User::ACCESS_ROLE_OPERATOR], true))
    <div class="command-os-entity-page">
        <!-- 1. Hero Header Card -->
        <header class="command-os-report-hero" aria-labelledby="mix-history-hero-title">
            <span class="command-os-report-hero-illustration" aria-hidden="true">
                <i class="fas fa-blender"></i>
            </span>
            <div class="command-os-report-hero-mark" aria-hidden="true">
                <i class="fas fa-blender"></i>
            </div>
            <div class="command-os-report-hero-copy">
                <p class="command-os-report-eyebrow">AUDIENCE INTELLIGENCE // REUSABLE SNAPSHOTS</p>
                <h1 id="mix-history-hero-title">MIX LISTS</h1>
                <p>Point-in-time audience snapshots, criteria definitions, and campaign targeting sets.</p>
            </div>
            <div class="command-os-hero-actions">
                @if ($canCreateMix)
                    <a class="btn-command-primary mr-2" href="{{ route('clean.console.audience-mixes.index') }}">
                        <i class="fas fa-plus mr-1"></i> CREATE MIX
                    </a>
                @endif
                <button type="button" class="command-os-btn-option" data-toggle="block-option" data-action="fullscreen_toggle" aria-label="Toggle full screen">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </header>

        <!-- 2. Filter Bar Card -->
        <section class="command-os-filter-card mb-3" aria-label="Filter mix history">
            <form id="mix-history-filters" method="get" class="d-flex flex-wrap align-items-end justify-content-between">
                <div class="d-flex flex-wrap align-items-end" style="gap: 12px;">
                    <div>
                        <label for="mix-history-per-page" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            <i class="fas fa-list-ol mr-1 text-purple"></i> Rows:
                        </label>
                        <select id="mix-history-per-page" name="per_page" class="form-control" style="height: 40px; border-radius: 10px; font-size: 12px; font-weight: 600;">
                            @foreach ([25, 50, 100] as $size)
                                <option value="{{ $size }}" @selected($perPage === $size)>Show {{ $size }} rows</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="mix-history-from" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            <i class="far fa-calendar-alt mr-1 text-purple"></i> Date From:
                        </label>
                        <input id="mix-history-from" name="from" type="date" class="form-control" value="{{ $from }}" required style="height: 40px; border-radius: 10px; font-size: 12px;">
                    </div>
                    <div>
                        <label for="mix-history-to" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                            <i class="far fa-calendar-alt mr-1 text-purple"></i> Date To:
                        </label>
                        <input id="mix-history-to" name="to" type="date" class="form-control" value="{{ $to }}" required style="height: 40px; border-radius: 10px; font-size: 12px;">
                    </div>
                    <div>
                        <label class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1 d-none d-md-block" style="visibility: hidden;">&nbsp;</label>
                        <button type="submit" class="btn-command-action" style="height: 40px;">
                            <i class="fas fa-filter mr-1"></i> FILTER
                        </button>
                    </div>
                </div>
                <div class="mt-2 mt-md-0" style="min-width: 260px;">
                    <label for="mix-history-search" class="command-os-field-label font-w700 text-uppercase font-size-xs mb-1">
                        <i class="fas fa-search mr-1 text-purple"></i> Search Mix or ISP:
                    </label>
                    <div class="position-relative">
                        <input id="mix-history-search" name="search" type="search" class="form-control" value="{{ $search }}" maxlength="100" placeholder="Search by name..." style="height: 40px; border-radius: 10px; font-size: 12px; padding-left: 34px;">
                        <i class="fas fa-search position-absolute text-muted" style="left: 12px; top: 13px; font-size: 12px;"></i>
                    </div>
                </div>
            </form>
        </section>

        <div id="mix-history-status" class="sr-only" role="status" aria-live="polite"></div>

        <!-- 3. Table Card -->
        <div class="command-os-table-card">
            <div class="table-responsive">
                <table class="table command-os-table table-sm table-hover mb-0" aria-label="Mix history">
                    <thead>
                        <tr>
                            <th scope="col" style="width: 50px;">#</th>
                            <th scope="col">NAME</th>
                            <th scope="col" class="text-right">TOTAL</th>
                            <th scope="col">ISP</th>
                            <th scope="col" class="text-center">LISTS</th>
                            <th scope="col">FILTERS</th>
                            <th scope="col" class="text-center">STATUS</th>
                            <th scope="col">CREATED AT</th>
                            <th scope="col">USABLE UNTIL</th>
                            <th scope="col" class="text-right">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($mixes as $mix)
                            @php
                                $criteria = $mix->criteria ?? [];
                                $sourceListCount = count(array_unique($criteria['source_list_ids'] ?? []));
                                $filters = $criteria['filters'] ?? [];
                                $filterCount = count($filters) + count(array_filter([
                                    $criteria['email_sex'] ?? null,
                                    $criteria['email_device'] ?? null,
                                    $criteria['last_insert_between'] ?? null,
                                    $criteria['extensions'] ?? null,
                                    $criteria['activity_between'] ?? null,
                                ], static fn ($value): bool => filled($value)));
                            @endphp
                            <tr>
                                <td><span class="text-muted font-mono" style="font-size: 11px;">#{{ ($mixes->firstItem() ?? 1) + $loop->index }}</span></td>
                                <td>
                                    <strong class="font-w600" style="color: var(--command-os-text, #1e293b); font-size: 12.5px;">{{ $mix->name }}</strong>
                                </td>
                                <td class="text-right">
                                    <span class="font-mono font-w700 text-purple" style="font-size: 12px;">{{ number_format($mix->recipient_count) }}</span>
                                </td>
                                <td>
                                    <span class="badge-command-pill" style="font-size: 11px;">{{ $mix->isp?->name ?? '—' }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge-command-pill" style="font-size: 11px;">{{ $sourceListCount }}</span>
                                </td>
                                <td>
                                    <details class="command-details-pop">
                                        <summary class="badge-command-pill" style="cursor: pointer; display: inline-flex; align-items: center; gap: 4px;">
                                            <i class="fas fa-sliders-h" style="font-size: 10px;"></i> {{ $filterCount }} criteria
                                        </summary>
                                        <pre class="mt-2 p-2 rounded" style="background: rgba(0, 0, 0, 0.25); border: 1px solid rgba(139, 92, 246, 0.2); font-size: 10.5px; font-family: monospace; max-height: 180px; overflow-y: auto; white-space: pre-wrap;">{{ json_encode($criteria, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                    </details>
                                </td>
                                <td class="text-center">
                                    @if ($mix->status === 'active')
                                        <span class="badge-command-pill status-ready">
                                            <i class="fas fa-check-circle mr-1" style="color: #10b981;"></i> ACTIVE
                                        </span>
                                    @else
                                        <span class="badge-command-pill" style="border-color: rgba(239, 68, 68, 0.35); color: #ef4444;">
                                            <i class="fas fa-clock mr-1"></i> EXPIRED
                                        </span>
                                    @endif
                                </td>
                                <td><span class="text-muted font-mono" style="font-size: 11px;">{{ $mix->created_at?->format('Y-m-d H:i:s') }}</span></td>
                                <td>
                                    @if ($mix->status === 'active')
                                        <span class="font-mono text-purple" style="font-size: 11px;">{{ $mix->created_at?->copy()->addHours(24)->format('Y-m-d H:i:s') ?? '—' }}</span>
                                    @else
                                        <span class="font-mono text-muted" style="font-size: 11px;">{{ $mix->expired_at?->format('Y-m-d H:i:s') ?? 'Expired' }}</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if ($mix->status === 'expired' && $canCreateMix)
                                        <button type="button" class="btn-command-action remix-mix" style="padding: 4px 10px; font-size: 11px;" data-url="{{ route('clean.console.audience-mixes.remix', $mix->id) }}">
                                            <i class="fas fa-sync-alt mr-1" aria-hidden="true"></i> REMIX
                                        </button>
                                    @else
                                        <span class="badge-command-pill status-ready" title="This mix can be selected in campaign targeting for 24 hours after creation.">
                                            READY
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="text-center text-muted py-5">
                                    <i class="fas fa-blender fa-2x mb-2 d-block text-purple" style="opacity: 0.5;"></i>
                                    No audience mixes were created in this date range.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- 4. Footer Pagination -->
            <div class="command-table-footer d-flex flex-wrap align-items-center justify-content-between p-3 border-top">
                <span class="text-muted font-size-xs">Page {{ $mixes->currentPage() }} of {{ $mixes->lastPage() }} ({{ number_format($mixes->total()) }} records)</span>
                <div>{{ $mixes->links() }}</div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        (() => {
            const filters = document.getElementById('mix-history-filters');
            const from = document.getElementById('mix-history-from');
            const to = document.getElementById('mix-history-to');
            const status = document.getElementById('mix-history-status');
            const prefix = `audience_mix_history_${@json((string) $workspace->id)}`;
            const params = new URLSearchParams(window.location.search);
            if (!params.has('from') && !params.has('to') && sessionStorage.getItem(`${prefix}_from`) && sessionStorage.getItem(`${prefix}_to`)) {
                from.value = sessionStorage.getItem(`${prefix}_from`);
                to.value = sessionStorage.getItem(`${prefix}_to`);
                filters.requestSubmit();
            }
            filters.addEventListener('submit', () => {
                sessionStorage.setItem(`${prefix}_from`, from.value);
                sessionStorage.setItem(`${prefix}_to`, to.value);
            });
            document.querySelectorAll('.remix-mix').forEach((button) => button.addEventListener('click', async () => {
                const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.innerHTML = '<i class="fas fa-sync fa-spin mr-1" aria-hidden="true"></i> REMIXING…';
                status.textContent = 'Rebuilding the saved audience mix.';
                try {
                    const response = await fetch(button.dataset.url, {
                        method: 'POST',
                        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf || '' },
                        body: JSON.stringify({}),
                    });
                    const result = await response.json();
                    if (!response.ok) throw new Error(result.data || result.message || 'Mix could not be rebuilt.');
                    status.textContent = `${result.data}.`;
                    window.location.reload();
                } catch (error) {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-sync-alt mr-1" aria-hidden="true"></i> REMIX';
                    status.textContent = error.message;
                    if (window.AlphaLinkToast?.show) {
                        window.AlphaLinkToast.show(error.message, 'danger');
                    } else {
                        alert(error.message);
                    }
                }
            }));
        })();
    </script>
@endpush
