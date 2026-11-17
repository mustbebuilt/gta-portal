@extends('layout')
@section('title','Progress report needs attention')
@section('content')
@include('progress66.assets')
<div class="progress66">@if($demo)<div class="p66-demo">Demonstration — fictional data</div>@endif<h1>Report needs attention</h1><section class="panel" role="alert"><p>{{ $message }}</p><p>Your saved records have not been changed. Check the source or choose a narrower reporting period.</p><a class="button" href="{{ request()->url() }}">Reset report filters</a>@if(!empty($groups))<form method="get"><label for="p66-recover-group">Choose an authorised tutor group</label><select name="group" id="p66-recover-group">@foreach($groups as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select><button>Open group report</button></form>@endif</section></div>
@endsection
