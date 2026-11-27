@php($label = $row['week'] === today()->startOfWeek()->toDateString() ? 'Open this week’s register' : 'Open scheduled register')
@if($registerUrl)
    <a class="button" href="{{ $registerUrl }}">{{ $label }}</a>
@else
    <form method="post" action="{{ route('attendance.weekly.open', $row['group']) }}">
        @csrf
        <input type="hidden" name="date" value="{{ $row['date'] }}">
        <input type="hidden" name="week" value="{{ $row['week'] }}">
        <button class="primary">{{ $label }}</button>
    </form>
@endif
