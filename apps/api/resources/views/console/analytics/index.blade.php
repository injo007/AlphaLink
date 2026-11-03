@extends('layouts.admin-shell')

@section('title', 'Main Dashboard')

@section('content')
    @include('console.analytics.partials.main-dashboard-content')
@endsection

@push('scripts')
    <script src="{{ asset('console-assets/main-dashboard-refresh.js') }}?v={{ substr(sha1_file(public_path('console-assets/main-dashboard-refresh.js')), 0, 12) }}" defer></script>
@endpush
