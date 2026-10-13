@extends('errors::minimal')

@section('title', __('Server Error'))
@section('code', '500')
@section('message')
    {{ __('Server Error') }}
    @if (jot()->traceId())
        <br><small>{{ __('Reference') }}: @traceId</small>
    @endif
@endsection
