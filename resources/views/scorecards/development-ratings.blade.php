<dl class="form-grid">
    @foreach(\App\Support\ApprenticeReviewData::DEVELOPMENT_AREAS as $key => $label)
        <div><dt>{{ $label }}</dt><dd>{{ $development['ratings'][$key] ?? 'Not recorded' }}</dd></div>
    @endforeach
</dl>
@if(filled($development['comment'] ?? null))
<p class="recorded-answer">{{ $development['comment'] }}</p>
@endif
