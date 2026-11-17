@extends('layout')
@section('title', 'Employer & Mentor Resources')
@section('content')
<link rel="stylesheet" href="{{ asset('assets/resources65.css') }}">
<div id="gta-resources65" class="gta65"></div>
<script>
window.GTA_RESOURCE_CONTENT = @json($resourceContent);
window.GTA_RESOURCE_CONFIG = @json($resourceConfig);
</script>
<script defer src="{{ asset('assets/resources65.js') }}"></script>
@endsection
