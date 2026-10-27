@extends('layouts.dms-admin')

@section('title', 'Negatives')

@section('content')
    <h2 class="content-heading">NEGATIVES</h2>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="block block-rounded">
        <div class="block-header">
            <h3 class="block-title">Negatives</h3>
            <div class="block-options">
                <a class="btn btn-sm btn-info" href="{{ route('console.campaign-negatives.create', $workspace->id) }}">ADD NEW NEGATIVE</a>
            </div>
        </div>
        <div class="block-content block-content-full">
            <p class="text-muted">DMS stores each negative file under its owner and makes it selectable on campaign setup. AlphaLink follows that ownership within this workspace. The native sender has not been proven to consume this file yet.</p>
            @if ($negatives->isEmpty())
                <p class="text-center mb-0">No negatives uploaded.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead><tr><th scope="col">#</th><th scope="col">NAME</th><th scope="col">FILE</th><th scope="col">SIZE</th><th scope="col">CREATED AT</th><th scope="col">ACTIONS</th></tr></thead>
                        <tbody>
                            @foreach ($negatives as $negative)
                                <tr>
                                    <td>{{ $negative->id }}</td>
                                    <td>{{ $negative->name }}</td>
                                    <td>{{ $negative->original_filename }}</td>
                                    <td>{{ number_format($negative->size_bytes / 1024, 1) }} KB</td>
                                    <td>{{ $negative->created_at?->format('Y-m-d H:i:s') }}</td>
                                    <td class="text-nowrap">
                                        <a class="btn btn-info btn-sm" href="{{ route('console.campaign-negatives.download', [$workspace->id, $negative->id]) }}" title="DOWNLOAD NEGATIVE" aria-label="Download {{ $negative->name }}"><i class="fas fa-download"></i></a>
                                        <form class="d-inline" method="POST" action="{{ route('console.campaign-negatives.destroy', [$workspace->id, $negative->id]) }}" onsubmit="return confirm('Delete this negative file? Campaign drafts that selected it will keep the old ID, but the file will be removed.');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-danger btn-sm" type="submit" title="DELETE NEGATIVE" aria-label="Delete {{ $negative->name }}"><i class="fas fa-trash"></i></button>
                                        </form>
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
