@extends('layouts.admin-shell')

@section('title', 'Clone Campaign')

@section('content')
    <h2 class="content-heading">CLONE CAMPAIGN</h2>
    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">CAMPAIGN TO COPY</h3>
            <div class="block-options"><a href="{{ route('console.campaigns.show', [$workspace->id, $source->id]) }}">{{ $source->name }}</a></div>
        </div>
        <div class="block-content block-content-full">
            @include('console.campaigns.partials.clone-form')
        </div>
    </div>
    <p><a href="{{ route('console.campaigns.show', [$workspace->id, $source->id]) }}">Back to source campaign</a></p>
@endsection

@push('scripts')
    <script src="{{ asset('console-assets/campaign-clone-modal.js') }}?v={{ substr(sha1_file(public_path('console-assets/campaign-clone-modal.js')), 0, 12) }}" defer></script>
@endpush
