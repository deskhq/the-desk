@extends('errors.layout')

@section('code', '500')
@section('title', __('Something broke on our desk'))
@section('message', __('An unexpected error occurred on our end. Your messages are safe — try again in a moment.'))

@section('actions')
    <a class="pill pill--primary" href="{{ url()->current() }}">{{ __('Try again') }}</a>
    <a class="pill pill--ghost" href="{{ url('/') }}">{{ __('Back to your workspace') }}</a>
@endsection
