<?php
namespace App\Support;
use App\Models\{Apprentice,ApprenticeSetupDraft68 as Draft,ProgrammeSetupTemplate68 as Template,Programme,Employer,TrainingPlanWorkspace,IndividualDeliveryPlan,User,TutorGroup,LearningTask};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB,Gate,Validator};
use Illuminate\Support\Str;
class GuidedSetup68 {
 public const STEPS=[1=>'Apprentice details',2=>'Inclusion Markers',3=>'Apprenticeship',4=>'Prior learning',5=>'Employer',6=>'Review and confirm'];
 public static function actor(User $actor,int $auth):User {$u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless(LearningAccess::manager($u)&&$u->auth_version===$auth&&$u->can('create',Apprentice::class),403);return $u;}
 public static function start(User $u,int $auth):Draft {return DB::transaction(function()use($u,$auth){self::actor($u,$auth);$d=new Draft();$d->id=(string)Str::uuid();$d->owner_id=$u->id;$d->payload=['authors'=>[$u->id],'step'=>1];$d->history=[];$d->save();return $d;});}
 public static function event(Draft $d,User $u,string $event,array $meta=[]):void {$d->history=[...($d->history??[]),['event'=>$event,'actor'=>$u->id,'at'=>now()->toIso8601String(),'revision'=>$d->version,...$meta]];}
 public static function lock(Draft $draft,int $version):Draft {$d=Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();abort_if($d->version!==$version,409,'Draft changed. Reload before saving.');return $d;}
 public static function template(array $p):?Template {$t=Template::find($p['programme']['template_id']??0);$pr=$t?Programme::find($t->programme_id):null;if(!$t||!$t->active||!$pr?->active)return null;if($t->source_hash!==Rpl68::hash($t->template)||strtoupper($pr->standard_code)!==strtoupper($t->template['standard_code'])||ltrim(strtolower($pr->version),'v')!==ltrim(strtolower($t->template['standard_version']),'v'))return null;return $t;}
 public static function employerKey(array $e):string {return isset($e['id'])&&$e['id']?'existing:'.$e['id']:'new:'.strtoupper(trim($e['reference']??'')).':'.mb_strtolower(trim($e['name']??''));}
 public static function matches(array $v,?int $except=null):array {
  $ref=strtoupper(trim($v['reference']??''));$email=mb_strtolower(trim($v['email']??''));$first=mb_strtolower(trim($v['first_name']??''));$last=mb_strtolower(trim($v['last_name']??''));
  if($ref===''&&$email===''&&($first===''||$last===''))return [];
  return Apprentice::when($except,fn($q)=>$q->whereKeyNot($except))->where(function($q)use($ref,$email,$first,$last){$q->whereRaw('1=0');if($ref!=='')$q->orWhereRaw('UPPER(reference)=?',[$ref]);if($email!=='')$q->orWhereRaw('LOWER(email)=?',[$email]);if($first!==''&&$last!=='')$q->orWhere(fn($q)=>$q->whereRaw('LOWER(first_name)=?',[$first])->whereRaw('LOWER(last_name)=?',[$last]));})->limit(20)->get(['id','reference','first_name','last_name','email'])->map(fn($a)=>['id'=>$a->id,'reference'=>$a->reference,'name'=>$a->first_name.' '.$a->last_name,'exact_reference'=>strtoupper($a->reference)===$ref,'exact_email'=>$email!==''&&mb_strtolower($a->email??'')===$email])->all();
 }
 public static function save(User $u,int $auth,Draft $draft,int $version,int $step,array $input):Draft {
  return DB::transaction(function()use($u,$auth,$draft,$version,$step,$input){$u=self::actor($u,$auth);$d=self::lock($draft,$version);abort_unless($d->state==='Draft',409,'Reopen this record with a reason before correcting it.');$p=$d->payload;$before=$p;
   if($step===1){$v=Validator::make($input,['reference'=>['nullable','string','max:40','regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],'first_name'=>['nullable','string','max:100'],'last_name'=>['nullable','string','max:100'],'email'=>['nullable','email:rfc','max:254'],'duplicate_reason'=>['nullable','string','max:500']])->validate();foreach($v as $k=>$value)$v[$k]=is_string($value)?trim($value):$value;$v['reference']=strtoupper($v['reference']??'');$p['details']=$v;if(isset($before['details'])&&$before['details']!==$v){unset($p['rpl']['remaining_confirmed'],$p['rpl']['confirmation_hash'],$p['employer']['agreement_verified']);}}
   elseif($step===2){abort_unless(SupportAccess::lead($u),403);$codes=json_decode(file_get_contents(resource_path('data/inclusion-markers.json')),true,512,JSON_THROW_ON_ERROR);$v=Validator::make($input,['state'=>['nullable','in:selected,none,pending'],'codes'=>['nullable','array'],'codes.*'=>['string',\Illuminate\Validation\Rule::in(array_keys($codes))],'other'=>['nullable','string','max:500']])->validate();if(($v['state']??null)!=='selected'){$v['codes']=[];$v['other']=null;}elseif(!in_array('IM40',$v['codes']??[],true))$v['other']=null;$v['codes']=array_values(array_unique($v['codes']??[]));$p['markers']=$v;}
   elseif($step===3){$v=Validator::make($input,['template_id'=>['nullable','integer'],'start_date'=>['nullable','date_format:Y-m-d'],'tutor_id'=>['nullable','integer'],'tutor_group_id'=>['nullable','integer'],'mentor_id'=>['nullable','integer'],'careers_adviser_id'=>['nullable','integer'],'maths_tutor_id'=>['nullable','integer'],'english_tutor_id'=>['nullable','integer']])->validate();$p['programme']=$v;$t=self::template($p);if(($v['template_id']??null)&&!$t)Rpl68::fail('Choose an active, controlled template matching the programme standard/version.');$p['programme']['template_hash']=$t?->source_hash;if(collect($before['programme']??[])->only(['template_id','template_hash'])->all()!==collect($p['programme'])->only(['template_id','template_hash'])->all()){unset($p['rpl']['remaining_confirmed'],$p['rpl']['confirmation_hash'],$p['employer']['agreement_verified']);if(($before['programme']['template_id']??null)!==($v['template_id']??null))$p['rpl']=[];}}
   elseif($step===4){$t=self::template($p);if(!$t)Rpl68::fail('Select the apprenticeship template first.');$v=Validator::make($input,['mode'=>['nullable','in:none,applies'],'assessment_ref'=>['nullable','string','max:1000'],'adjustments'=>['nullable','array'],'remaining_confirmed'=>['nullable','boolean']])->validate();$v['adjustments']=$v['adjustments']??[];if(($v['mode']??null)==='none')$v=['mode'=>'none','adjustments'=>[],'remaining_confirmed'=>true];Rpl68::calculate($t->template,$v);if(!empty($v['remaining_confirmed']))$v['confirmation_hash']=Rpl68::hash([$t->source_hash,$v['mode']??null,$v['assessment_ref']??null,$v['adjustments']]);if(($p['rpl']??[])!==$v)unset($p['employer']['agreement_verified']);$p['rpl']=$v;}
   elseif($step===5){$v=Validator::make($input,['id'=>['nullable','integer'],'reference'=>['nullable','string','max:40','regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'],'name'=>['nullable','string','max:200'],'contact_name'=>['nullable','string','max:150'],'contact_email'=>['nullable','email:rfc','max:254'],'contact_phone'=>['nullable','string','max:50'],'size_band'=>['nullable',\Illuminate\Validation\Rule::in(['1–9','10–49','50–249','250+','Unknown'])],'use_contact'=>['nullable','boolean'],'agreement_ref'=>['nullable','string','max:1000'],'agreement_verified'=>['nullable','boolean'],'duplicate_reason'=>['nullable','string','max:500']])->validate();if(!empty($v['id'])){$e=Employer::find($v['id']);if(!$e?->active)Rpl68::fail('Choose an active employer.');$v=array_intersect_key($v,array_flip(['id','use_contact','agreement_ref','agreement_verified']));$v['record_version']=$e->record_version;}else $v['reference']=strtoupper(trim($v['reference']??''));
    if(self::employerKey($p['employer']??[])!==self::employerKey($v)||($p['employer']['record_version']??null)!==($v['record_version']??null))$v['agreement_verified']=false;
    if(!empty($v['agreement_verified']))$v['agreement_binding']=Rpl68::hash([self::employerKey($v),$v['record_version']??null,$p['rpl']??[],$v['agreement_ref']??null]);$p['employer']=$v;
   }else Rpl68::fail('Choose a setup step from one to five.');
   if($d->apprentice_id&&!isset($p['apprentice_version']))$p['apprentice_version']=Apprentice::findOrFail($d->apprentice_id)->version;
   $p['step']=$step;$p['authors']=array_values(array_unique([...($p['authors']??[]),$u->id]));$d->payload=$p;$d->version++;self::event($d,$u,'step_saved',['step'=>$step,...($step===4?['rpl_before'=>$before['rpl']??[],'rpl_after'=>$p['rpl']??[]]:[])]);$d->save();return $d;
  });
 }
 public static function savePlanDates(User $u, int $auth, Draft $draft, int $version, array $input): Draft
 {
  return DB::transaction(function () use ($u, $auth, $draft, $version, $input) {
   self::actor($u, $auth);
   $d = self::lock($draft, $version);
   abort_unless($d->state === 'Draft', 409);
   $p = $d->payload;
   $template = self::template($p);
   $policy = $template ? RplPlanDates69::policy($template->template) : null;
   if (!$policy || empty($p['programme']['start_date'])) Rpl68::fail('Choose the approved programme and start date first.');

   $dates = Validator::make($input, [
    'source' => ['required', 'string', 'max:250'],
    'agreed_hours' => ['required', 'numeric', 'min:0.01', 'max:10000'],
    'practical_end' => ['required', 'date_format:Y-m-d'],
    'programme_end' => ['required', 'date_format:Y-m-d', 'after:practical_end'],
    'pause_start' => ['nullable', 'required_with:pause_end', 'date_format:Y-m-d'],
    'pause_end' => ['nullable', 'required_with:pause_start', 'date_format:Y-m-d'],
    'schedule' => ['nullable', 'string', 'max:1000'],
   ])->validate();
   $dates['binding'] = RplPlanDates69::binding($p['programme'], $p['rpl'] ?? [], $policy);
   $old = $p['plan_dates69'] ?? [];
   $p['plan_dates69'] = $dates;
   $p['authors'] = array_values(array_unique([...($p['authors'] ?? []), $u->id]));
   $d->payload = $p;
   $d->version++;
   self::event($d, $u, 'training_plan_dates_saved', ['previous_dates' => $old, 'new_dates' => $dates]);
   $d->save();

   return $d;
  });
 }
 public static function review(Draft $d):array {
  $p=$d->payload;$issues=[];$add=function(int $step,string $text)use(&$issues){$issues[]=['step'=>$step,'message'=>$text];};$details=$p['details']??[];
  foreach(['reference','first_name','last_name'] as $k)if(!filled($details[$k]??null))$add(1,'Enter '.$k.'.');
  $matches=self::matches($details,$d->apprentice_id);foreach($matches as $m){if($m['exact_reference']||$m['exact_email'])$add(1,'Existing apprentice '.$m['reference'].' matches. Open that record instead of creating another.');elseif(!filled($details['duplicate_reason']??null))$add(1,'Review the possible name match '.$m['reference'].' and explain why this is a different person.');}
  $markers=$p['markers']??[];if(!in_array($markers['state']??null,['selected','none','pending'],true))$add(2,'Choose markers, none or pending.');if(($markers['state']??null)==='selected'&&!count($markers['codes']??[]))$add(2,'Select at least one marker, or choose none/pending.');if(in_array('IM40',$markers['codes']??[],true)&&!filled($markers['other']??null))$add(2,'Briefly specify the Other marker.');
  $t=self::template($p);$calc=null;
  if(!$t)$add(3,'Select an active controlled programme template.');
  elseif(!TaskCatalogue::keyFor(new Apprentice(['programme_id'=>$t->programme_id])))$add(3,'This programme has no loaded task catalogue. Load its controlled source before confirming.');elseif($t->source_hash!==($p['programme']['template_hash']??null))$add(3,'The template changed. Reselect the apprenticeship and confirm RPL again.');
  if(!empty($p['programme']['tutor_group_id'])&&$t){$group=TutorGroup::find($p['programme']['tutor_group_id']);if(!$group?->active||($group->programme_id&&(int)$group->programme_id!==$t->programme_id))$add(3,'Choose an active tutor group for the selected programme.');}
  if(!filled($p['programme']['start_date']??null))$add(3,'Enter the first day in learning.');
  $r=$p['rpl']??[];if(!in_array($r['mode']??null,['none','applies'],true))$add(4,'Confirm no relevant prior learning or enter the approved RPL outcomes.');
  if($t){try{$calc=Rpl68::calculate($t->template,$r);}catch(\Illuminate\Validation\ValidationException $ex){$add(4,$ex->getMessage());}}
  if(($r['mode']??null)==='applies'&&!filled($r['assessment_ref']??null))$add(4,'Enter the completed assessment reference once.');
  if(!($r['remaining_confirmed']??false)||($t&&($r['confirmation_hash']??null)!==Rpl68::hash([$t->source_hash,$r['mode']??null,$r['assessment_ref']??null,$r['adjustments']??[]])))$add(4,'Confirm the remaining KSBs have no reduction.');
  if($calc&&$calc['unresolved'])foreach($calc['unresolved'] as $n=>$codes)$add(4,'Block '.$n.' needs a controlled allocation for '.implode(', ',$codes).'.');
  if($calc&&$calc['revised_hours']<=0)$add(4,'No training remains; refer this case for programme review rather than enrolling automatically.');
  $e=$p['employer']??[];$employer=null;
  if(!empty($e['id'])){$employer=Employer::find($e['id']);if(!$employer?->active||$employer->record_version!==($e['record_version']??null))$add(5,'Employer changed or is inactive. Reselect and verify its agreement.');}
  else {if(!filled($e['reference']??null)||!filled($e['name']??null))$add(5,'Select an existing employer or enter the new employer reference and name.');else foreach(Employer::where(fn($q)=>$q->whereRaw('UPPER(reference)=?',[strtoupper($e['reference'])])->orWhereRaw('LOWER(name)=?',[mb_strtolower(trim($e['name']))]))->get() as $candidate){if(strtoupper($candidate->reference)===strtoupper($e['reference']))$add(5,'Employer reference already exists; select its existing record.');elseif(!filled($e['duplicate_reason']??null))$add(5,'Possible employer name match: '.$candidate->name.'. Select it or explain the separate organisation.');}}
  if(($r['mode']??null)==='applies'&&(!filled($e['agreement_ref']??null)||empty($e['agreement_verified'])||($e['agreement_binding']??null)!==Rpl68::hash([self::employerKey($e),$e['record_version']??null,$r,$e['agreement_ref']??null])))$add(5,'Verify the employer agreement reference against this linked employer and RPL revision.');
  $dates = $t ? RplPlanDates69::review($t->template, $p['programme'], $r, $calc, $p['plan_dates69'] ?? []) : null;
  if ($d->state !== 'Complete' && !empty($dates['issue'])) $add(4, $dates['issue']);
  return ['issues'=>$issues,'calculation'=>$calc,'template'=>$t,'employer'=>$employer,'matches'=>$matches,'dates69'=>$dates];
 }
 public static function submit(User $u,int $auth,Draft $draft,int $version):Draft {return DB::transaction(function()use($u,$auth,$draft,$version){self::actor($u,$auth);$d=self::lock($draft,$version);abort_unless($d->state==='Draft',409);if(self::review($d)['issues'])Rpl68::fail('Complete the linked missing items before requesting plan approval.');$d->state='Ready';$d->version++;self::event($d,$u,'submitted_for_independent_review');$d->save();return $d;});}
 public static function reopen(User $u,int $auth,Draft $draft,int $version,string $reason):Draft {return DB::transaction(function()use($u,$auth,$draft,$version,$reason){self::actor($u,$auth);if(!filled(trim($reason)))Rpl68::fail('Record the reason for this correction.');$d=self::lock($draft,$version);abort_unless(in_array($d->state,['Ready','Complete'],true),409);$p=$d->payload;$p['authors']=[$u->id];$p['correction_reason']=trim($reason);$d->payload=$p;$d->state='Draft';$d->version++;self::event($d,$u,'reopened',['reason'=>trim($reason)]);$d->save();return $d;});}
 public static function confirm(User $u,int $auth,Draft $draft,int $version):Draft {
  return DB::transaction(function()use($u,$auth,$draft,$version){$u=self::actor($u,$auth);DB::table('setup68_locks')->where('key','commit')->lockForUpdate()->first();$d=Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();if($d->state==='Complete')return $d;$d=self::lock($d,$version);abort_unless($d->state==='Ready',409);$p=$d->payload;abort_if(in_array($u->id,$p['authors']??[],true),422,'The existing independent plan-review rule requires an administrator or CEO who did not prepare this draft.');$view=self::review($d);if($view['issues'])Rpl68::fail('Source or records changed; reopen and resolve the missing items.');$t=$view['template'];$calc=$view['calculation'];$e=$p['employer'];$employer=$view['employer'];
   if(!$employer){$employer=EmployerWorkflow68::create($u,$auth,$d->id,$e);}
   $fields=collect($p['details'])->only(['reference','first_name','last_name','email'])->all();$fields=[...$fields,...collect($p['programme'])->only(['tutor_id','tutor_group_id','mentor_id','careers_adviser_id','maths_tutor_id','english_tutor_id'])->all(),'programme_id'=>$t->programme_id,'employer_id'=>$employer->id,'start_date'=>$p['programme']['start_date']];$end=CarbonImmutable::parse($fields['start_date'])->addDays(collect($t->template['blocks'])->max('end_day'))->toDateString();$agreedDates=$view['dates69']['approved']??null;$fields['planned_end_date']=$agreedDates['programme_end']??$end;
   $a=$d->apprentice_id?Apprentice::whereKey($d->apprentice_id)->lockForUpdate()->firstOrFail():new Apprentice();
   if($a->exists&&$a->version!==($p['apprentice_version']??null))Rpl68::fail('The apprentice changed since this setup was opened; reconcile the record first.');
   if($a->exists&&($a->programme_id!==$t->programme_id)&&LearningTask::where('apprentice_id',$a->id)->exists())Rpl68::fail('Use the controlled programme-transfer process for existing allocated learning.');
   // Reuse the full current apprentice request rules for linked roles/groups and all original field constraints.
   $req=new \App\Http\Requests\SaveApprenticeRequest();$req->merge($fields);$rules=$req->rules();unset($rules['version']);$rules['reference']=['required','string','max:40','regex:/^[A-Z0-9][A-Z0-9_-]*$/',\Illuminate\Validation\Rule::unique('apprentices','reference')->ignore($a->id)];$fields=Validator::make($fields,$rules)->validate();
   $before=$a->exists?$a->only(AssignmentHistory::KEYS):null;$a->fill($fields);$a->inclusion_status68=$p['markers']['state'];if($a->exists)$a->version++;$a->save();AssignmentHistory::record($a,$u->id,$before);
   abort_unless(SupportAccess::lead($u,$a),403);$old=MarkerHistory::codes($a->id);$markers=$p['markers'];if($markers['state']!=='pending'){$selected=$markers['state']==='none'?[]:$markers['codes'];DB::table('apprentice_markers')->where('apprentice_id',$a->id)->whereNotIn('code',$selected)->delete();foreach(array_diff($selected,$old) as $code)DB::table('apprentice_markers')->insert(['apprentice_id'=>$a->id,'code'=>$code,'recorded_by'=>$u->id,'created_at'=>now(),'updated_at'=>now()]);MarkerHistory::record($a->id,$u->id,'guided_setup68',$old,'Recorded during apprentice setup');}
   $w=TrainingPlanWorkspace::firstOrNew(['apprentice_id'=>$a->id]);$rpl=[...$p['rpl'],'remaining_confirmed'=>true,'template_id'=>$t->id,'template_hash'=>$t->source_hash,'calculation'=>$calc,'assessment_ref'=>$p['rpl']['assessment_ref']??null,'employer_id'=>$employer->id,'employer_agreement_ref'=>$e['agreement_ref']??null,'approved_by'=>$u->id,'approved_at'=>now()->toIso8601String()];$w->setup_rpl=$rpl;$w->source_hash=$t->source_hash;$w->proposal=$w->proposal??[];$w->rpl=$w->rpl??[];$w->history=[...($w->history??[]),['event'=>'setup68_rpl_confirmed','by'=>$u->id,'at'=>now()->toIso8601String(),'draft'=>$d->id,'reason'=>$p['correction_reason']??null]];if($w->exists)$w->version++;$w->save();
   $blocks=[];foreach($calc['blocks'] as $b)$blocks[]=['number'=>$b['number'],'title'=>$b['title'],'hours'=>(string)$b['hours'],'start'=>CarbonImmutable::parse($a->start_date)->addDays($b['start_day'])->toDateString(),'end'=>CarbonImmutable::parse($a->start_date)->addDays($b['end_day'])->toDateString()];
   if ($agreedDates) $blocks = $agreedDates['blocks'];
   $plan=IndividualDeliveryPlan::firstOrNew(['apprentice_id'=>$a->id]);$previousPlan=$plan->approved;$approved=['identity'=>DeliveryPlan::identity($a),'catalogue_hash'=>DeliveryPlan::catalogueHash(TaskCatalogue::keyFor($a)),'blocks'=>$blocks,'details'=>['target_hours'=>$calc['revised_hours'],'basis'=>$rpl['mode']==='none'?'Full training retained':'Controlled individual plan','source_ref'=>$t->template['source_ref'],'ksb_ref'=>$t->template['ksb_ref'],'qualifications_ref'=>$t->template['qualifications_ref'],'release_ref'=>$t->template['release_ref'],...($agreedDates?['practical_end_date'=>$agreedDates['practical_end'],'apprenticeship_end_date'=>$agreedDates['programme_end'],'agreed_dates_source'=>$agreedDates['source']]:[])],'rpl_binding'=>Rpl68::hash($rpl),'authors'=>$p['authors'],'approved_by'=>$u->id,'approved_at'=>now()->toIso8601String(),'approval_scope'=>'Existing independent provider operational plan review; participant signatures and costing remain separate'];$approved['hash']=Rpl68::hash($approved);$plan->draft=$approved;$plan->approved=$approved;$plan->active=true;$plan->history=[...($plan->history??[]),['event'=>'setup68_plan_confirmed','by'=>$u->id,'at'=>now()->toIso8601String(),'approved_hash'=>$approved['hash'],...($agreedDates&&$previousPlan?['previous_dates'=>$previousPlan['details'],'previous_schedule'=>$previousPlan['blocks']]:[])]];if($plan->exists)$plan->version++;$plan->save();
   $p['employer']=['id'=>$employer->id,'record_version'=>$employer->record_version,'use_contact'=>$e['use_contact']??false,'agreement_ref'=>$e['agreement_ref']??null,'agreement_verified'=>$e['agreement_verified']??false]; if($p['employer']['agreement_verified'])$p['employer']['agreement_binding']=Rpl68::hash([self::employerKey($p['employer']),$employer->record_version,$p['rpl'],$e['agreement_ref']??null]);
   $p['apprentice_version']=$a->version;$p['approved_hours']=$calc['revised_hours'];$d->payload=$p;$d->apprentice_id=$a->id;$d->state='Complete';$d->version++;self::event($d,$u,'setup_confirmed',['apprentice_id'=>$a->id,'plan_hash'=>$approved['hash']]);$d->save();DB::table('audit_events')->insert(['user_id'=>$u->id,'apprentice_id'=>$a->id,'event'=>'guided_setup68_confirmed','created_at'=>now()]);return $d;
  });
 }
}
