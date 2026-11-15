@extends('layout')
@section('title','Privacy information')
@section('content')<h1>Privacy information</h1><section id="contact">@include('privacy.body')</section><p><a href="{{ route('login') }}">Sign in</a>@auth · <a href="{{ route('portal-privacy.form') }}">Your privacy notice and review</a>@endauth</p>@endsection
