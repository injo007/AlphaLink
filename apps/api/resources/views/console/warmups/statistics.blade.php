@extends('layouts.admin-shell')

@section('title', 'Warmup Statistics')

@section('content')
<section class="block block-rounded">
    <div class="block-header">
        <h3 class="block-title">Warmup Statistics</h3>
        <div class="block-options"><a class="btn btn-sm btn-alt-primary" href="{{ route('console.warmups.index', $workspace->id) }}">WARMUP DRAFTS</a></div>
    </div>
    <div class="block-content block-content-full">
        <div class="alert alert-info" role="status">Counts below use AlphaLink's unique recipient-action records, grouped by Warmup draft. Opens, clicks, leads, unsubscribes, and automated traffic are distinct actions. This report does not infer deliveries or opt-out attribution from send totals.</div>
        <form class="form-row align-items-end mb-4" method="get" action="{{ route('console.warmups.statistics', $workspace->id) }}">
            <div class="form-group col-sm-5 col-md-3"><label for="warmup-stats-from">From</label><input class="form-control" id="warmup-stats-from" name="from" type="date" max="{{ today()->toDateString() }}" value="{{ $from }}" required></div>
            <div class="form-group col-sm-5 col-md-3"><label for="warmup-stats-to">To</label><input class="form-control" id="warmup-stats-to" name="to" type="date" max="{{ today()->toDateString() }}" value="{{ $to }}" required></div>
            <div class="form-group col-sm-2"><button class="btn btn-primary" type="submit">FILTER</button></div>
        </form>
        <div class="row mb-4">
            @foreach ([['Unique opens', 'opens'], ['Unique clicks', 'clicks'], ['Leads', 'leads'], ['Unsubscribes', 'unsubscribes'], ['Automated actions', 'bots']] as [$label, $key])
                <div class="col-6 col-md-4 col-xl-2 mb-3"><div class="border rounded p-3 h-100"><div class="small text-muted">{{ $label }}</div><div class="h3 mb-0">{{ number_format($summary[$key]) }}</div></div></div>
            @endforeach
        </div>
        @if ($rows->isEmpty())
            <div class="border rounded p-4 text-center" role="status"><h4 class="h5">No Warmup actions in this date range</h4><p class="text-muted mb-0">This report uses saved AlphaLink tracking actions. It does not generate sample or estimated activity.</p></div>
        @else
            <div class="table-responsive"><table class="table table-sm table-hover table-striped">
                <caption class="sr-only">Unique recipient actions by Warmup</caption>
                <thead><tr><th scope="col">Warmup</th><th scope="col">Opens</th><th scope="col">Clicks</th><th scope="col">Leads</th><th scope="col">Unsubscribes</th><th scope="col">Automated</th></tr></thead>
                <tbody>@foreach ($rows as $row)<tr><th scope="row">{{ $row['name'] }}</th><td>{{ number_format($row['opens']) }}</td><td>{{ number_format($row['clicks']) }}</td><td>{{ number_format($row['leads']) }}</td><td>{{ number_format($row['unsubscribes']) }}</td><td>{{ number_format($row['bots']) }}</td></tr>@endforeach</tbody>
            </table></div>
        @endif
    </div>
</section>
@endsection
