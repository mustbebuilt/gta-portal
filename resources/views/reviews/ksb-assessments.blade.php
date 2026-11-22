@extends('layout')
@section('content')
<h1>Formal KSB assessment records</h1>
<p>{{ $apprentice->first_name }} {{ $apprentice->last_name }}</p>
@include('learning.errors')
@if($key)
<section class="panel">
    <h2>Record an evidenced result</h2>
    <p>Approved programme mapping: {{ $plan['details']['ksb_ref'] }}. Use the exact KSB code from this mapping and the reference for the formal assessment.</p>
    <form method="post" action="{{ route('reviews.ksb.store', $apprentice) }}">
        @csrf
        <input type="hidden" name="plan_hash" value="{{ $plan['hash'] }}">
        <label>KSB code <input name="code" value="{{ old('code') }}" maxlength="16" required></label>
        <label>Assessment outcome <select name="outcome" required><option value="">Choose</option>
            @foreach(\App\Support\FormalKsbResults::OUTCOMES as $id => $label)
            <option value="{{ $id }}" @selected(old('outcome') === $id)>{{ $label }}</option>
            @endforeach
        </select></label>
        <label>Assessment date <input name="assessed_on" type="date" value="{{ old('assessed_on') }}" required></label>
        <label>Restricted assessment evidence reference <input name="evidence_reference" value="{{ old('evidence_reference') }}" maxlength="500" required></label>
        <label class="check"><input type="checkbox" name="mapping_checked" value="1" required> I checked the code and the formal result against this approved mapping.</label>
        <button class="primary">Save assessment result</button>
    </form>
</section>
@else
<p>Approve the delivery plan and its programme mapping first.</p>
@endif
<section class="panel">
    <h2>Assessment history</h2>
    @forelse($records as $record)
    <p>{{ $record->code }} · {{ \App\Support\FormalKsbResults::OUTCOMES[$record->outcome] }} · {{ $record->assessed_on->format('d M Y') }} · {{ $record->evidence_reference }}
        @if($record->curriculum_key !== $key) · Previous programme or mapping @endif</p>
    @empty
    <p>No formal results recorded.</p>
    @endforelse
    {{ $records->links() }}
</section>
<p><a href="{{ route('reviews.create', $apprentice) }}">Open progress review</a></p>
@endsection
