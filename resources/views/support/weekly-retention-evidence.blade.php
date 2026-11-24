@if($evidence['attendance'])
<p><strong>Two consecutive missed sessions</strong></p>
<ul>
@foreach($evidence['attendance'] as $session)
    <li>{{ $session['date'] }} · {{ \App\Support\AttendanceMath::CODES[$session['code']] }}</li>
@endforeach
</ul>
@endif
@if($otj = $evidence['otj'])
<p><strong>Off-the-job training is {{ $otj['behind'] }}% behind</strong></p>
<p>{{ $otj['recorded'] }} hours recorded of {{ $otj['expected'] }} hours due.</p>
@endif
