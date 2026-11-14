@extends('layout')
@section('title','Support review reminders')
@section('content')
<header class="page-heading"><div><h1>Support review reminders</h1><p>Your recorded coordinator prompts. The original support plan holds the current review position.</p></div><a class="button" href="{{ route('support.worklist') }}">Support worklist</a></header>
@include('learning.errors')
<p class="test-notice">Seeing a reminder does not complete a review, change an approved plan or create a funding claim. Reminders contain no private support text.</p>
@forelse($items as $item)<section class="panel"><h2>{{ $item['apprentice']->reference }} · Plan {{ $item['plan']->id }}</h2>
@if($item['current'])<p>Review due {{ $item['reminder']->source['due'] }} · {{ $item['reminder']->seen_at?'Seen':'Awaiting acknowledgement' }}</p>@else<p>Source or coordinator changed. Check the current plan; this old reminder cannot be acknowledged as current.</p>@endif
<a class="button" href="{{ route('support.plan',[$item['apprentice'],$item['plan']]) }}">Open private plan</a>
@if($item['reminder']->dismissed_at)<p>Outdated reminder dismissed.</p>
@elseif($item['current'] && !$item['reminder']->seen_at)<form method="post" action="{{ route('support-review-reminders.seen',$item['reminder']) }}">@csrf<button>Mark reminder seen</button></form>
@elseif(!$item['current'] && !$item['reminder']->seen_at)<form method="post" action="{{ route('support-review-reminders.dismiss',$item['reminder']) }}">@csrf<button>Dismiss outdated reminder</button></form>@endif
</section>@empty<section class="panel"><p>No recorded reminders are available within your current access.</p></section>@endforelse
{{ $pages->links() }}
@endsection
