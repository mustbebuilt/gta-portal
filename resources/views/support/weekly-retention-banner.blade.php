@if($weeklyReminderCount > 0)
<section class="panel">
    <a href="{{ route('weekly-retention.reminders') }}">Open this week’s training {{ Str::plural('reminder', $weeklyReminderCount) }} ({{ $weeklyReminderCount }})</a>
</section>
@endif
