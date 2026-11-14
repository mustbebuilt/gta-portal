@foreach(collect($fields)->groupBy('section') as $section=>$items)
<fieldset><legend>{{ $section }}</legend><div class="form-grid">@foreach($items as $field)
@php($name=$field['name'])
@php($value=($preview??false)?'':($readonly?($values[$name]??''):old('answers.'.$name,$values[$name]??'')))
@if($field['type']==='notice')<p class="muted full">{{ $field['label'] }}</p>
@elseif($field['type']!=='decision')
<div class="{{ ($field['long']??false)?'full':'' }}"><label for="field-{{ $name }}">{{ $field['label'] }}</label>
@if($field['help'])<p class="muted">{{ $field['help'] }}</p>@endif
@if($readonly)<p class="recorded-answer">{{ $field['type']==='checkbox' ? (in_array($value,['on','1',1],true)?'Confirmed':'Not confirmed') : ($value!=='' && $value!==null ? $value : 'Not recorded') }}</p>
@elseif(in_array($field['type'],['choice','score'],true))<select id="field-{{ $name }}" name="answers[{{ $name }}]"><option value="">Choose</option>@foreach($field['type']==='score'?range(1,10):$field['options'] as $option)<option value="{{ $option }}" @selected((string)$value===(string)$option)>{{ $option }}</option>@endforeach</select>
@elseif($field['type']==='checkbox')<input id="field-{{ $name }}" type="checkbox" name="answers[{{ $name }}]" value="on" @checked(in_array($value,['on','1',1],true))>
@elseif(in_array($field['type'],['date','number'],true))<input id="field-{{ $name }}" type="{{ $field['type'] }}" name="answers[{{ $name }}]" value="{{ $value }}" @if($field['type']==='number')step="any" min="0"@endif>
@else<textarea data-print-full-answer id="field-{{ $name }}" name="answers[{{ $name }}]" rows="{{ ($field['long']??false)?4:2 }}">{{ $value }}</textarea>@endif
</div>@endif
@endforeach</div></fieldset>
@endforeach
