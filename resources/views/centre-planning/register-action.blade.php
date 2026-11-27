@php($key = $item['key'])
@if(isset($weeklyRegisterEntries[$key]))
    @php($weekly = $weeklyRegisterEntries[$key])
    @include('attendance.open-weekly', ['row' => $weekly, 'registerUrl' => $registerLinks[$key] ?? null])
    @if(!$compactRegisterAction && !isset($registerLinks[$key]))
        <a href="{{ route('attendance.index', ['group' => $weekly['group']->id, 'plan_date' => $weekly['date']]) }}#session-planner">Plan subjects for this register</a>
    @endif
@elseif(isset($registerLinks[$key]))
    <a class="button" href="{{ $registerLinks[$key] }}">Open matching register</a>
@elseif(isset($occurrenceLinks[$key]))
    <a href="{{ $occurrenceLinks[$key] }}">Check register for this session</a>
@elseif(!$compactRegisterAction && isset($registerChecks[$key]))
    <p>Ask the timetable team to check the register for this session.</p>
@endif
