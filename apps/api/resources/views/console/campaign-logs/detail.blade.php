@extends('layouts.admin-shell')

@section('title', 'Campaign configuration history')

@section('content')
    <section class="block block-rounded campaign-history-page">
        <header class="campaign-history-header">
            <div class="campaign-history-heading"><span class="campaign-history-eyebrow">CAMPAIGN STUDIO / CONFIGURATION</span><h1>Saved campaign configuration</h1></div>
            <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.campaign-logs.history', [$workspace->id, $campaign->id, $history]) }}"><i class="fas fa-arrow-left mr-1" aria-hidden="true"></i>Back to {{ $history }}</a>
        </header>
        <div class="p-3">@include('console.campaign-logs.partials.history-detail')</div>
    </section>
@endsection
