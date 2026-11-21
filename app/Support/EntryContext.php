<?php
namespace App\Support;
use App\Models\{Apprentice,ApprenticeEntryContext as Entry,EntryContextEvent as Event,User};
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class EntryContext {
 public const RECRUITMENT_FIELDS = [
  'recruitment_route' => 'How did they first hear about GTA?',
  'employment_route' => 'How did they obtain the apprenticeship job?',
 ];

 public static function choices(): array {return json_decode(file_get_contents(resource_path('data/entry-choices.json')),true,512,JSON_THROW_ON_ERROR);}
 public static function fields(): array {return array_merge(array_keys(self::choices()),['entry_employer_id','employment_start_date','referral_source','notes','stage','confirmed_on']);}
 public static function rules(bool $employment=false): array {
  $rules=[];
  foreach(self::choices() as $k=>$values){if(!$employment||in_array($k,['family_owned','family_owner_manager','family_supervisor','relationship'],true)){$rules[$k]=['nullable',Rule::in($values)];}}
  $rules+=['entry_employer_id'=>['nullable','integer',Rule::exists('employers','id')],'employment_start_date'=>['nullable','date_format:Y-m-d','before_or_equal:today'],'notes'=>['nullable','string','max:500']];
  if(!$employment){$rules+=['referral_source'=>['nullable','string','max:160'],'stage'=>['required','in:Application,Enrolment'],'confirmed_on'=>['nullable','date_format:Y-m-d','before_or_equal:today']];}
  return $rules;
 }
 public static function validate(array $input,Apprentice $a,bool $employment=false):array {
  $d=Validator::make($input,self::rules($employment))->validate();
  $related=($d['family_owner_manager']??null)==='Yes'||($d['family_supervisor']??null)==='Yes';
  if(!$related&&in_array($d['family_owner_manager']??null,['No','Prefer not to say'],true)&&in_array($d['family_supervisor']??null,['No','Prefer not to say'],true)){$d['relationship']=in_array('Prefer not to say',[$d['family_owner_manager'],$d['family_supervisor']],true)?'Prefer not to say':'Not applicable';}
  if(!$employment&&($d['stage']??'')==='Enrolment'){
   foreach(array_keys(self::choices()) as $key){if(!filled($d[$key]??null)){throw ValidationException::withMessages([$key=>'Choose a response; Unknown is an honest answer.']);}}
   if(!filled($d['confirmed_on']??null)){throw ValidationException::withMessages(['confirmed_on'=>'Record the actual confirmation date.']);}
  }
  if(!$employment&&($d['already_employed']??null)==='No'&&filled($d['employment_start_date']??null)&&$a->start_date&&$d['employment_start_date']<$a->start_date->toDateString()){
   throw ValidationException::withMessages(['employment_start_date'=>'This date is before learning started. Check the already-employed answer.']);
  }
  if(!$employment&&($d['already_employed']??null)==='Yes'&&$a->start_date&&filled($d['employment_start_date']??null)&&$d['employment_start_date']>$a->start_date->toDateString()){
   throw ValidationException::withMessages(['employment_start_date'=>'Already employed at entry needs an employment start on or before learning started.']);
  }
  $related=($d['family_owner_manager']??null)==='Yes'||($d['family_supervisor']??null)==='Yes';
  if($related&&(!filled($d['relationship']??null)||$d['relationship']==='Not applicable')){throw ValidationException::withMessages(['relationship'=>'Choose the relationship category, Unknown or Prefer not to say. No names needed.']);}

  return $d;
 }
 public static function save(User $u,Apprentice $profile,array $input,int $version,string $reason=''):Entry {
  abort_unless(LearningAccess::manager($u),403);
  return DB::transaction(function()use($u,$profile,$input,$version,$reason){
   $a=Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();
   $entry=Entry::where('apprentice_id',$a->id)->lockForUpdate()->first();
   $prior=$entry?->only(self::fields())??[];foreach(['confirmed_on','employment_start_date'] as $date){if(isset($prior[$date])){$prior[$date]=substr((string)$prior[$date],0,10);}}
   $d=self::validate(array_merge($prior,$input),$a);
   if(($entry?->version??0)!==$version){throw ValidationException::withMessages(['version'=>'Entry information changed. Reload before saving.']);}
   if($entry&&trim($reason)===''){throw ValidationException::withMessages(['reason'=>'Briefly explain the correction or confirmation. Later employer changes use the separate dated action.']);}
   $before=$entry?->only(self::fields());$entry??=new Entry(['apprentice_id'=>$a->id]);
   foreach(self::fields() as $key){$entry->$key=$d[$key]??null;}$entry->stage=$d['stage'];$entry->updated_by=$u->id;$entry->version=$version+1;$entry->save();
   Event::create(['apprentice_id'=>$a->id,'actor_id'=>$u->id,'kind'=>'Entry','effective_on'=>$d['confirmed_on']??null,'revision'=>$entry->version,'payload'=>['before'=>$before,'after'=>$entry->only(self::fields()),'reason'=>$reason]]);
   return $entry;
  });
 }
 public static function employment(User $u,Apprentice $a,array $input):void {
  abort_unless(LearningAccess::manager($u),403);
  $meta=Validator::make($input,['effective_on'=>['required','date_format:Y-m-d','before_or_equal:today'],'reason'=>['required','string','max:500']])->validate();
  $d=self::validate($input,$a,true);
  DB::transaction(function()use($u,$a,$d,$meta){$locked=Apprentice::whereKey($a->id)->lockForUpdate()->firstOrFail();
   if(isset($d['entry_employer_id'])&&$d['entry_employer_id']!=$locked->employer_id){throw ValidationException::withMessages(['entry_employer_id'=>'Update the linked employer in the profile first; the entry employer stays historical.']);}
   if(filled($d['employment_start_date']??null)&&$d['employment_start_date']>$meta['effective_on']){throw ValidationException::withMessages(['employment_start_date'=>'Employment cannot start after the change reference date.']);}
   Event::create(['apprentice_id'=>$a->id,'actor_id'=>$u->id,'kind'=>'Employment','effective_on'=>$meta['effective_on'],'revision'=>1,'payload'=>['after'=>$d,'reason'=>$meta['reason']]]);
  });
 }
 public static function gaps(?Entry $entry,Apprentice $a):array {
  $g=[];foreach(array_keys(self::choices()) as $k){$v=$entry?->$k;if(!filled($v)||$v==='Unknown'){$g[]=$k.': '.($v==='Unknown'?'unknown':'not recorded');}}
  if(!$entry||$entry->stage!=='Enrolment'||!$entry->confirmed_on){$g[]='Entry confirmation outstanding';}
  if($entry?->already_employed==='Yes'&&!$entry->employment_start_date){$g[]='Employment start date unknown';}
  if(!$entry?->entry_employer_id){$g[]='Entry employer not recorded';}
  if($entry?->employment_start_date&&$a->start_date){$date=$entry->employment_start_date->toDateString();$start=$a->start_date->toDateString();if(($entry->already_employed==='Yes'&&$date>$start)||($entry->already_employed==='No'&&$date<$start)){$g[]='Employment date and entry employment answer conflict with learning start';}}
  return $g;
 }
}
