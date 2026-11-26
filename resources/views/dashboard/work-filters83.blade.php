@php($activeWorkFilters = request()->filled('group') || request()->filled('apprentice') || $priorityFilter!=='all' || $workType!=='all')
<details class="panel" @if($activeWorkFilters || $errors->any()) open @endif><summary>Filter work{{$activeWorkFilters?' · filters applied':''}}</summary>
<form method="get" action="{{route('dashboard.index')}}" class="search" data-work-filters>
@if(auth()->user()->role!=='apprentice')
<div><label for="dashboard-group">Group</label><select id="dashboard-group" name="group"><option value="">All visible groups</option>@foreach($groups as $g)<option value="{{$g->id}}" @selected((string)request('group')===(string)$g->id)>{{$g->name}}</option>@endforeach</select></div>
<div><label for="dashboard-apprentice">Apprentice</label><select id="dashboard-apprentice" name="apprentice"><option value="">All in selected group</option>@foreach($visible as $a)<option value="{{$a->id}}" data-group="{{$a->tutor_group_id}}" @selected((string)request('apprentice')===(string)$a->id)>{{$a->first_name}} {{$a->last_name}}</option>@endforeach</select></div>
@endif
<div><label for="priority-filter">Status</label><select id="priority-filter" name="priority">
    @foreach(\App\Support\Workday83::STATUSES as $value => $label)<option value="{{ $value }}" @selected($priorityFilter === $value)>{{ $label }}</option>@endforeach
</select></div>
<div><label for="work-type">Work type</label><select id="work-type" name="work_type">
    @foreach($workTypes as $value => $label)<option value="{{ $value }}" @selected($workType === $value)>{{ $label }}</option>@endforeach
</select></div>
<button>Apply filters</button>@if($activeWorkFilters)<a class="button" href="{{route('dashboard.index')}}">Clear</a>@endif
</form></details>
<script src="{{ asset('assets/work-today.js') }}" defer></script>
