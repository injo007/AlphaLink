@extends('layouts.admin-shell')

@php($isDrop = $kind === 'drop')
@section('title', $isDrop ? 'Drop Drafts' : 'Warmup Sends')

@section('content')
<section class="block block-rounded">
    <div class="block-header">
        <h3 class="block-title">{{ $isDrop ? 'Drop Drafts' : 'Warmup Sends' }}</h3>
        <div class="block-options d-flex flex-wrap" style="gap:.5rem">
            @if (! $isDrop)
                <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.warmups.seeds.index', $workspace->id) }}">SEED LISTS</a>
                <a class="btn btn-sm btn-alt-secondary" href="{{ route('console.warmups.statistics', $workspace->id) }}">STATISTICS</a>
            @endif
            <a class="btn btn-sm btn-primary" href="{{ $createUrl }}">{{ $isDrop ? 'NEW DROP' : 'NEW WARMUP' }}</a>
        </div>
    </div>
    <div class="block-content block-content-full">
        <div class="alert alert-warning" role="status">
            <strong>Draft preparation only.</strong> AlphaLink does not yet have a verified {{ $isDrop ? 'Drop' : 'Warmup' }} send adapter. Saving here stores an encrypted, revisioned draft; it does not test, schedule, or send email.
        </div>
        @if ($drafts->isEmpty())
            <div class="border rounded p-4 text-center" role="status">
                <h4 class="h5">No {{ $isDrop ? 'Drop' : 'Warmup' }} drafts yet</h4>
                <p class="text-muted mb-3">Create a draft to save content and workspace selections. No demo records are shown.</p>
                <a class="btn btn-primary" href="{{ $createUrl }}">{{ $isDrop ? 'CREATE DROP DRAFT' : 'CREATE WARMUP DRAFT' }}</a>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover table-striped">
                    <caption class="sr-only">Workspace-owned {{ $isDrop ? 'Drop' : 'Warmup' }} drafts</caption>
                    <thead><tr><th scope="col">Name</th><th scope="col">Status</th><th scope="col">Revision</th><th scope="col">Updated</th><th scope="col">Action</th></tr></thead>
                    <tbody>
                    @foreach ($drafts as $draft)
                        <tr>
                            <th scope="row">{{ $draft->name }}</th>
                            <td><span class="badge badge-secondary">DRAFT</span></td>
                            <td>{{ $draft->latestRevision?->revision_number ?? '—' }}</td>
                            <td>{{ $draft->updated_at?->format('Y-m-d H:i') ?? '—' }}</td>
                            <td><a class="btn btn-sm btn-alt-primary" href="{{ route($editRoute, [$workspace->id, $draft->id]) }}">OPEN DRAFT</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $drafts->links() }}
        @endif
    </div>
</section>
@endsection
