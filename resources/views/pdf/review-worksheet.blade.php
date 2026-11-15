@extends('pdf.layout')
@section('title','Review preparation worksheet — not a signed review')
@section('content')
<h1>Review preparation worksheet</h1><p><strong>Factual preparation only — not a signed review or a record that a meeting took place.</strong></p><p>{{ $worksheet['identity']['reference'] }} · {{ $worksheet['identity']['first_name'] }} {{ $worksheet['identity']['last_name'] }}. Training period {{ $worksheet['from'] }} to {{ $worksheet['to'] }}. Captured {{ $worksheet['captured_at'] }}.</p>
@foreach(\App\Support\ReviewBriefs::FIELDS as $key=>$label)<h2>{{ $label }}</h2><p class="answer">{{ $worksheet['notes'][$key] }}</p>@endforeach
<h2>Human checks remain</h2><p>Confirm source accuracy, actual discussion, previous targets, support needs and the next agreed actions. Use the original review workflow to edit, save, prepare for signatures and obtain the required sign-off. Private support case notes are excluded. Current portal context is not a reconstructed historical return.</p>
@endsection
