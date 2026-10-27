@extends('layouts.dms-admin')

@section('title', 'Main Dashboard')

@section('content')
    @include('console.analytics.partials.main-dashboard-content')
@endsection

@push('scripts')
    <script src="{{ asset('dms-reference/main-dashboard-refresh.js') }}?v={{ substr(sha1_file(public_path('dms-reference/main-dashboard-refresh.js')), 0, 12) }}" defer></script>
@endpush
