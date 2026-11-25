@if($registerUrl)
    <a class="button" href="{{ $registerUrl }}">Open this week’s register</a>
@else
    <form method="post" action="{{ route('attendance.weekly.open', $row['group']) }}">
        @csrf
        <input type="hidden" name="date" value="{{ $row['date'] }}">
        <input type="hidden" name="week" value="{{ $row['week'] }}">
        <button class="primary">Open this week’s register</button>
    </form>
@endif
