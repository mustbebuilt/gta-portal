@if($reviewSchedule['appointment'])
<p><strong>Booked review appointment</strong></p><p>{{ $reviewSchedule['appointment']->starts_at->copy()->timezone(config('app.timezone'))->format('d/m/Y H:i') }}–{{ $reviewSchedule['appointment']->ends_at->copy()->timezone(config('app.timezone'))->format('H:i') }} ({{ config('app.timezone') }})</p><a class="button" href="{{ route('review-appointments.show',$reviewSchedule['appointment']) }}">Open review appointment</a><p>Booking, attendance and signatures are separate.</p>
@else
<p>No current published review appointment is available here. Confirm the booking with the Tutor.</p>
@endif
@if($reviewSchedule['limited'])<p>There are more than 50 upcoming published appointment records. Ask GTA to reconcile the appointment list.</p>@endif
@if($reviewSchedule['cycle']['next_due'])
<p>Review due reference: <strong>{{ \Carbon\Carbon::parse($reviewSchedule['cycle']['next_due'])->format('d/m/Y') }}</strong> · {{ $reviewSchedule['cycle']['status'] }}</p><p>Due ten weeks after the last checked meeting.</p>
@else
<p>The tracked next-review reference needs checking with the Tutor. A missing reference does not mean a review is unnecessary.</p>
@endif
@if($reviewSchedule['cycle']['integrity_checks']||$reviewSchedule['checks'])<p class="test-notice">A checked review record needs source checking. It does not add a milestone or establish a reliable next-review reference here.</p>@endif
