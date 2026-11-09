@extends('layouts.admin-shell')

@section('title', 'Create Mix')

@section('content')
    @include('console.audiences.mixes.partials.builder', ['mixBuilderInModal' => false])
@endsection
