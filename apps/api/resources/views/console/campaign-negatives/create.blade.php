@extends('layouts.dms-admin')

@section('title', 'Upload Negative')

@section('content')
    <h2 class="content-heading">UPLOAD NEGATIVE</h2>

    @if ($errors->any())
        <div class="alert alert-danger" role="alert" tabindex="-1"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="block block-rounded">
        <div class="block-header block-header-default"><h3 class="block-title">Upload Negative</h3></div>
        <div class="block-content">
            <div class="row justify-content-center">
                <div class="col-sm-12 col-md-6">
                    <form enctype="multipart/form-data" action="{{ route('console.campaign-negatives.store', $workspace->id) }}" method="POST">
                        @csrf
                        <div class="form-group">
                            <label for="negative-name">Negative Name:</label>
                            <input id="negative-name" class="form-control form-control-alt" type="text" name="name" maxlength="255" placeholder="Negative Name ..." value="{{ old('name') }}" required>
                        </div>
                        <div class="form-group">
                            <label for="negative-file">Negative File:</label>
                            <input id="negative-file" class="form-control-file" type="file" name="file" accept=".csv,.txt,.html,text/csv,text/plain,text/html" required>
                            <small class="form-text text-muted">CSV, TXT, or HTML · maximum 20 MB. File contents are stored privately.</small>
                        </div>
                        <div class="form-group mb-0">
                            <button class="btn btn-block btn-info" type="submit">UPLOAD</button>
                            <a class="btn btn-block btn-alt-secondary" href="{{ route('console.campaign-negatives.index', $workspace->id) }}">CANCEL</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
