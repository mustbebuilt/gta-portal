@extends('layout')
@section('title','Intake reference lookup')
@section('content')
<p><a href="{{ route('intakes.form') }}">Back to intake upload</a></p><h1>Intake reference lookup</h1><p>Active catalogue entries only. Listing is not approval of programme/cohort content, employment or individual authority. No automatic account or directory creation.</p><form method="get"><label for="kind">Catalogue</label><select id="kind" name="kind">@foreach(['programmes','employers','groups','staff'] as $value)<option @selected($kind===$value)>{{ $value }}</option>@endforeach</select><label for="search">Name contains</label><input id="search" name="search" value="{{ $search }}" maxlength="100"><button>Find references</button></form>
@forelse($records as $record)<section class="panel"><h2>{{ $record->name }}</h2>@if($kind==='programmes')<p>programme_code: {{ $record->standard_code }} · programme_version: {{ $record->version }}</p>@elseif($kind==='staff')<p>Individual staff email: {{ $record->email }}</p>@foreach(\App\Support\StaffHandover::SLOTS as $slot=>$label)@if(\App\Support\StaffHandover::eligible($record,$slot))<p>{{ $label }}</p>@endif @endforeach @else<p>Exact {{ $kind==='groups'?'group_reference':'employer_reference' }}: {{ $record->reference }}</p>@endif</section>@empty<p>No matching active entries.</p>@endforelse
{{ $records->links() }}
@endsection
