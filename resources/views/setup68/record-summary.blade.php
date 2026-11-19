@can('update',$apprentice)
@php($setup68=\App\Models\ApprenticeSetupDraft68::where('apprentice_id',$apprentice->id)->first())
@if($setup68)<section class="panel"><h2>Apprentice setup</h2><p>{{$setup68->state}} · <a href="{{route('setup68.show',[$setup68,6])}}">Review setup and RPL</a></p>@if(\App\Support\SupportAccess::team(auth()->user(),$apprentice))<p>Inclusion information: {{$apprentice->inclusion_status68??'Not recorded'}}. @if($apprentice->inclusion_status68==='pending')<a href="{{route('support.index',$apprentice)}}">Follow up pending inclusion information</a>@endif</p>@endif</section>@endif
@endcan
