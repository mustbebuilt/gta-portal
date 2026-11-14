@if($errors->any())
<section class="errors error-summary" role="alert" tabindex="-1" aria-labelledby="form-error-summary-title" data-error-summary>
<h2 id="form-error-summary-title">Please check these details</h2>
<p>Your changes have not been saved. Select a linked error to go to its field.</p>
<ul>@foreach($errors->getMessages() as $field => $messages)
@foreach($messages as $message)
<li>@if(in_array($field, $errorFields ?? [], true))<a href="#{{ $field }}">{{ $message }}</a>@else{{ $message }}@endif</li>
@endforeach
@endforeach
</ul>
</section>
@endif
