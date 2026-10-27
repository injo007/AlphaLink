@extends('layouts.dms-admin')

@section('title', 'App Errors - DMS')

@section('content')
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">APP ERRORS</h3>
            <div class="block-options">{{ count($entries) }} recent entries</div>
        </div>
        <div class="block-content block-content-full">
            @if ($entries === [])
                <p class="text-center mb-0">No application errors were found.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-striped">
                        <thead><tr><th scope="col">DATE</th><th scope="col">LEVEL</th><th scope="col">FILE</th><th scope="col">SUMMARY</th></tr></thead>
                        <tbody>
                            @foreach ($entries as $entry)
                                <tr>
                                    <td>{{ $entry['date'] }}</td>
                                    <td><span class="badge badge-danger">{{ $entry['level'] }}</span></td>
                                    <td>{{ $entry['file'] }}</td>
                                    <td>{{ $entry['summary'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <p class="text-muted small mb-0">Only the last 100 error-level summaries from AlphaLink Laravel log files are shown. Stack traces and common secrets, email addresses, IP addresses, and URLs are redacted.</p>
        </div>
    </div>
@endsection
