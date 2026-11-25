@extends('layout')
@section('title', 'Programme outcome')
@section('content')
<header class="page-heading">
    <div>
        <p class="eyebrow">{{ $apprentice->first_name }} {{ $apprentice->last_name }} · {{ $apprentice->reference }}</p>
        <h1>Programme outcome</h1>
        <p>{{ $apprentice->programmeLabel() ?? 'Programme not recorded' }}</p>
    </div>
    <div class="toolbar">
        <a class="button" href="{{ route('apprentices.show', $apprentice) }}">Apprentice record</a>
        <a class="button" href="{{ route('apprentices.archive') }}">Apprentice archive</a>
    </div>
</header>
@include('learning.errors')

@if(!$closure && !$endedWithoutAchievement && ($outcome?->context['status'] ?? null) !== 'Completed')
@include('apprentices.record-leaving')
@endif

@if($closure)
<section class="panel">
    <h2>Completed and archived</h2>
    <p><strong>{{ $closure['grade'] }}</strong> on EPA attempt {{ $closure['attempt'] }}.</p>
    <p>Achievement date {{ $outcome->context['achievementDate'] }} · Actual programme end date {{ $outcome->context['endDate'] }}</p>
    <p>Employment at exit · {{ $exitEmployment }} · <a href="{{ route('retention.edit', $apprentice) }}">Update exit details</a></p>
    @if(isset($closure['completion_pack']))
        <p><a href="{{ route('apprentices.close-down.completion-pack', $apprentice) }}">Download completion pack (PDF)</a></p>
    @else
        <p>Completion pack unavailable for this previous closure.</p>
    @endif
</section>
@endif

@if(!$closure && ($outcome?->context['status'] ?? null) === 'Completed')
<section class="panel">
    <h2>Completed</h2>
    <p>Actual programme end date {{ $outcome->context['endDate'] ?? 'Not recorded' }} · Achievement {{ $outcome->context['achievement'] ?? 'Unknown' }}</p>
    <p><a href="{{ route('retention.edit', $apprentice) }}">View reporting details</a></p>
</section>
@endif

<section class="panel">
    <h2>EPA attempt history</h2>
    @if($attempts)
    <div class="table-scroll"><table>
        <thead><tr><th scope="col">Attempt</th><th scope="col">Assessment date</th><th scope="col">Result</th><th scope="col">Result received date</th></tr></thead>
        <tbody>
        @foreach($attempts as $attempt)
        <tr>
            <td>{{ $attempt['number'] }}</td><td>{{ $attempt['assessment_date'] }}</td>
            <td>{{ $attempt['result'] }}</td><td>{{ $attempt['received_date'] ?? 'Awaiting result' }}</td>
        </tr>
        @endforeach
        </tbody>
    </table></div>
    @else
    <p>No EPA attempts recorded.</p>
    @endif
</section>

@if($endedWithoutAchievement)
<section class="panel">
    <h2>{{ $outcome->context['status'] }}</h2>
    <p>Actual programme end date {{ $outcome->context['endDate'] ?? 'Not recorded' }} · Employment at exit {{ $exitEmployment }}</p>
    @if($outcome->context['status'] === 'Withdrawn')<p>Reason {{ $outcome->context['reason'] ?? 'Not established' }}</p>@endif
    <p><a href="{{ route('retention.edit', $apprentice) }}">View reporting details or correct the record</a></p>
</section>
@elseif(!$closure && $errors->has('version'))
<p><a class="button" href="{{ route('apprentices.close-down', $apprentice) }}">Reload attempt history</a></p>
@elseif(!$closure && $number)
<section class="panel">
    <h2>{{ $draft ? 'Update' : 'Record' }} EPA attempt {{ $number }}</h2>
    <p>The programme stays open while a result is awaited or after an unsuccessful attempt.</p>
    <form method="post" enctype="multipart/form-data" action="{{ route('apprentices.close-down.save', $apprentice) }}" data-epa-attempt>
        @csrf
        <input type="hidden" name="version" value="{{ $outcome?->version ?? 0 }}">
        <div class="form-grid">
            <div>
                <label for="epa-assessment">Assessment date</label>
                <input id="epa-assessment" type="date" name="assessment_date" max="{{ today()->toDateString() }}" required value="{{ old('assessment_date', $draft['assessment_date'] ?? '') }}">
            </div>
            <div>
                <label for="epa-result">EPA result</label>
                <select id="epa-result" name="result" required>
                    @foreach(['Awaiting result', 'Unsuccessful', ...$grades] as $result)
                    <option @selected(old('result', $draft['result'] ?? 'Awaiting result') === $result)>{{ $result }}</option>
                    @endforeach
                </select>
            </div>
            <div data-epa-received>
                <label for="epa-received">Result received date</label>
                <input id="epa-received" type="date" name="received_date" max="{{ today()->toDateString() }}" value="{{ old('received_date') }}">
            </div>
            <div data-epa-achievement>
                <label for="epa-achievement">Confirmed achievement date</label>
                <input id="epa-achievement" type="date" name="achievement_date" max="{{ today()->toDateString() }}" value="{{ old('achievement_date', $outcome?->context['achievementDate'] ?? null) }}">
            </div>
            <div data-epa-achievement>
                <label for="epa-end">Actual programme end date</label>
                <input id="epa-end" type="date" name="end_date" max="{{ today()->toDateString() }}" value="{{ old('end_date', $outcome?->context['endDate'] ?? null) }}">
            </div>
        </div>
        <div data-epa-achievement>
            <label for="exit-employment">Employment at exit</label>
            <select id="exit-employment" name="exit_employment">
                <option value="">Choose employment status</option>
                @foreach($employmentChoices as $choice)
                    <option @selected(old('exit_employment', $outcome?->context['exitEmployment'] ?? null) === $choice)>{{ $choice }}</option>
                @endforeach
            </select>
            <small>Use the status on the actual programme end date. Employed includes self-employed.</small>
        </div>
        <div data-epa-achievement>
            <label for="completion-pack">Completion pack (PDF)</label>
            <input id="completion-pack" name="completion_pack" type="file" accept=".pdf,application/pdf" aria-describedby="completion-pack-help">
            <small id="completion-pack-help">Scan the completed pack and upload it as one PDF, up to 15 MB. Required to close the programme.</small>
        </div>
        <p data-epa-achievement>Confirming this result marks the programme complete, moves the record to the archive and adds it to the Achievers report.</p>
        <div class="form-actions">
            <button class="primary" name="action" value="record">Save attempt</button>
            <button class="primary" name="action" value="archive" data-epa-achievement>Confirm achievement and archive</button>
        </div>
    </form>
    <script src="{{ asset('assets/epa-close-down.js') }}" defer></script>
</section>
@elseif(!$closure)
<section class="panel"><p>All three EPA attempts have been recorded. The programme remains open. <a href="{{ route('retention.edit', $apprentice) }}">Record another programme outcome</a></p></section>
@endif
@endsection
