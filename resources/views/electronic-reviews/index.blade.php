@extends('layout')
@section('title','Review signing')
@section('content')
<header class="page-heading"><div><p class="eyebrow">Participant review records</p><h1>Review signing</h1><p>Read and respond to your review record. All three participants sign the same released content; an independent staff check follows.</p></div></header>
<form method="get"><label>Show<select name="filter">@foreach(['open'=>'Open','mine'=>'Waiting for me','all'=>'All','Draft'=>'Private drafts','Released'=>'Released','All signed'=>'Waiting for staff check','Checked'=>'Checked','Declined'=>'Declined','Withdrawn'=>'Withdrawn'] as $key=>$label)<option value="{{ $key }}" @selected($filter===$key)>{{ $label }}</option>@endforeach</select></label><button>Apply filter</button></form>
@forelse($rows  as $p)<section class="panel"><h2><a href="{{ route('electronic-reviews.show',$p) }}">{{ $p->apprentice->reference }} · {{ $p->apprentice->first_name }} {{ $p->apprentice->last_name }}</a></h2><p>Review {{ $p->progress_review_draft_id }} · {{ $p->state }} · Response window ends {{ $p->expires_on->format('d M Y') }} @if(!$p->checked_at&&$p->expires_on->lt(today())) · Expired @endif</p></section>@empty<section class="panel"><p>No matching participant review records are available to your current account.</p></section>@endforelse
{{ $rows->links() }}
@endsection
