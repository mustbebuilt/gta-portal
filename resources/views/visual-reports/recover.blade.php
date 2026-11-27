@extends('layout')
@section('title', 'Visual Reports')
@section('content')
<header class="page-heading"><h1>Visual Reports</h1><a class="button" href="{{ route('reports.index') }}">Reports dashboard</a></header>
<section class="panel">
    <h2>Change the report selection</h2>
    <p role="alert">{{ $message }}</p>
    <form method="get" action="{{ route('visual-reports.index') }}" class="search">
        @if($selectedApprentice)<input type="hidden" name="apprentice" value="{{ $selectedApprentice }}">@endif
        @if(!$selectedApprentice && auth()->user()->role !== 'apprentice')
            <div><label for="visual-recovery-reference">Apprentice reference (optional)</label>
                <input id="visual-recovery-reference" name="reference" maxlength="40">
            </div>
        @endif
        @if($groups->isNotEmpty() && !$selectedApprentice)
            <div><label for="visual-recovery-group">Tutor group</label>
                <select id="visual-recovery-group" name="group">
                    <option value="">All authorised groups</option>
                    @foreach($groups as $group)
                        <option value="{{ $group->id }}">{{ $group->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <button class="primary">Open with default dates</button>
    </form>

</section>
@endsection
