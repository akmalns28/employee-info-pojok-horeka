@extends('layouts.app')

@section('title', 'Edit '.$label)
@section('header', 'Edit '.$label)
@section('sub header', 'Data Master')

@section('header actions')
    <a href="{{ route($resource.'.index') }}" class="btn">Kembali</a>
@endsection

@section('content')
    <div class="card">{{-- The form is also used in the create modal. --}}
        @include('organization.form')
    </div>
@endsection
