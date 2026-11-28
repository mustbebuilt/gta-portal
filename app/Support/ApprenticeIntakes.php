<?php
namespace App\Support;
use App\Models\{User,Apprentice,Programme,Employer,TutorGroup,LearningTask,ApprenticeIntakeBatch as Batch};
use Illuminate\Support\Facades\{DB,Crypt,Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class ApprenticeIntakes {
 public const HEADERS=['reference','first_name','last_name','email','programme_code','programme_version','employer_reference','group_reference','tutor_email','mentor_email','careers_email','maths_email','english_email','start_date','planned_end_date'];
 public const STAFF=['tutor_email'=>'tutor','mentor_email'=>'mentor','careers_email'=>'careers_adviser','maths_email'=>'maths_tutor','english_email'=>'english_tutor'];
 private static function fail(string $m):never {throw ValidationException::withMessages(['intake'=>$m]);}
 private static function csvSyntax(string $csv):void {
  $state='field';$length=strlen($csv);
  for($i=0;$i<$length;$i++){$ch=$csv[$i];
   if($state==='quoted'){if($ch==='"'){if($i+1<$length&&$csv[$i+1]==='"'){$i++;}else{$state='after';}}continue;}
   if($ch==='"'){if($state!=='field'){self::fail('Malformed CSV quoting.');}$state='quoted';continue;}
   if($ch===','){$state='field';continue;}
   if($ch==="\n"||$ch==="\r"){if($ch==="\r"){if($i+1>=$length||$csv[$i+1]!=="\n"){self::fail('Use LF or CRLF row endings.');}$i++;}$state='field';continue;}
   if($state==='after'){self::fail('Characters after a quoted CSV field are not allowed.');}$state='unquoted';
  }if($state==='quoted'){self::fail('Unclosed CSV quoted field.');}
 }

 public static function parse(string $csv):array {
  if(strlen($csv)>500000||str_contains($csv,"\0")||!mb_check_encoding($csv,'UTF-8')){self::fail('Use UTF-8 CSV up to 500,000 bytes without binary/NUL content.');}$csv=preg_replace('/^\xEF\xBB\xBF/','',$csv);self::csvSyntax($csv);$f=fopen('php://temp','r+');fwrite($f,$csv);rewind($f);$head=fgetcsv($f,null,',','"','');if($head!==self::HEADERS){fclose($f);self::fail('Use the exact template headers/order.');}$rows=[];
  while(($v=fgetcsv($f,null,',','"',''))!==false){if(count($rows)>=200||count($v)!==count(self::HEADERS)||in_array(null,$v,true)){fclose($f);self::fail('Use 1–200 complete rows with 15 fields and no blank lines.');}$v=array_combine(self::HEADERS,array_map('trim',$v));$v['reference']=strtoupper($v['reference']);foreach(['email',...array_keys(self::STAFF)]as$key){$v[$key]=strtolower($v[$key]);}$rows[]=$v;}fclose($f);if(!$rows){self::fail('Add at least one apprentice row.');}return $rows;
 }
 private static function one($q,bool $lock):?object {$rows=$q->when($lock,fn($q)=>$q->lockForUpdate())->limit(2)->get();return $rows->count()===1?$rows->first():null;}
 public static function assess(array $inputs,bool $lock=false):array {
  $out=[];$seen=[];$emails=[];
  foreach($inputs as$i=>$v){$errors=[];$warnings=[];$bindings=[];$data=[];
   $rules=['reference'=>['required','string','max:40','regex:/^[A-Z0-9][A-Z0-9_-]*$/D'],'first_name'=>['required','string','max:100'],'last_name'=>['required','string','max:100'],'email'=>['nullable','email:rfc','max:254'],'programme_code'=>['required','string','max:40'],'programme_version'=>['required','string','max:40'],'start_date'=>['required','date_format:Y-m-d'],'planned_end_date'=>['required','date_format:Y-m-d','after_or_equal:start_date'],'employer_reference'=>['nullable','string','max:40'],'group_reference'=>['nullable','string','max:40']];foreach(self::STAFF as$k=>$slot){$rules[$k]=[$k==='tutor_email'?'required':'nullable','email:rfc','max:254'];}$validator=Validator::make($v,$rules);if($validator->fails()){$errors=$validator->errors()->all();}
   if(isset($seen[$v['reference']])){$errors[]='Duplicate learner reference in this file.';}$seen[$v['reference']]=true;
   if(Apprentice::whereRaw('UPPER(reference) = ?',[$v['reference']])->exists()){$errors[]='Reference already exists. Intake is create-only; use the existing apprentice record.';}
   if($v['email']!==''&&isset($emails[$v['email']])){$errors[]='Email repeated in this file; check individual identity.';}if($v['email']!==''){$emails[$v['email']]=true;if(Apprentice::whereRaw('LOWER(email) = ?',[$v['email']])->exists()){$errors[]='Email already used by an apprentice profile; reconcile identity first.';}}
   $p=self::one(Programme::where('standard_code',$v['programme_code'])->where('version',$v['programme_version'])->where('active',true),$lock);if(!$p){$errors[]='Choose one exact active programme code/version from the catalogue.';}else{$data['programme_id']=$p->id;$bindings['programme']=ReviewedImports::hash($p->getAttributes());}
   foreach(['employer_reference'=>[Employer::class,'employer_id'],'group_reference'=>[TutorGroup::class,'tutor_group_id']]as$key=>[$class,$field]){if($v[$key]===''){$data[$field]=null;continue;}$x=self::one($class::where('reference',$v[$key])->where('active',true),$lock);if(!$x){$errors[]=$key.': choose one exact active catalogue reference.';}else{$data[$field]=$x->id;$bindings[$field]=ReviewedImports::hash($x->getAttributes());if($field==='tutor_group_id'&&$p&&$x->programme_id!==$p->id){$errors[]='Group programme does not match the selected programme/version.';}}}
   foreach(self::STAFF as$key=>$slot){$field=$slot.'_id';if($v[$key]===''){$data[$field]=null;continue;}$u=self::one(User::whereRaw('LOWER(email) = ?',[$v[$key]]),$lock);if(!$u||!StaffHandover::eligible($u,$slot)){$errors[]=$key.': choose one active staff account eligible for that exact responsibility.';}else{$data[$field]=$u->id;$bindings[$field]=StaffHandover::staffSeal($u);$cap=SupportAccess::settings($u);if(in_array($cap['duty'],['member','lead'],true)&&$cap['scope']==='assigned'){$warnings[]='Assignment to an existing Inclusion staff member can affect later private-support scope; verify intended authority.';}}}
   if(($data['tutor_group_id']??null)&&($g=TutorGroup::find($data['tutor_group_id']))&&$g->tutor_id!==($data['tutor_id']??null)){$warnings[]='Group tutor differs from main tutor; existing group-access rules remain separate.';}
   if($v['email']===''){$warnings[]='Individual learner email is missing; account setup requires correction.';}if($v['employer_reference']===''){$warnings[]='No employer linked; GTA must check actual employment/support needs.';}
   foreach(['mentor_email','careers_email','maths_email','english_email']as$key){if($v[$key]===''){$warnings[]=$key.' not assigned; check actual staffing requirements.';}}
   foreach(['reference','first_name','last_name','start_date','planned_end_date']as$key){$data[$key]=$v[$key];}$data['email']=$v['email']?:null;
   $out[]=['line'=>$i+2,'input'=>$v,'data'=>$data,'bindings'=>$bindings,'errors'=>array_values(array_unique($errors)),'warnings'=>array_values(array_unique($warnings))];
  }return $out;
 }
 private static function event(Batch $b,User $u,string $event,array $payload=[]):void {DB::table('apprentice_intake_events')->insert(['batch_id'=>$b->id,'actor_id'=>$u->id,'event'=>$event,'record_version'=>$b->version,'payload'=>Crypt::encryptString(json_encode($payload,JSON_THROW_ON_ERROR)),'created_at'=>now()]);}
 private static function actor(User $u,int $auth):User {$u=User::whereKey($u->id)->lockForUpdate()->firstOrFail();abort_unless(LearningAccess::manager($u)&&$u->auth_version===$auth,403);return $u;}
 public static function intact(Batch $b):bool {try{$s=$b->source;return $s&&ReviewedImports::hash($s)===$b->source_hash&&$s['creator']['id']===$b->created_by&&$s['request']===$b->request_token&&$s['expires_at']===$b->expires_at->toIso8601String();}catch(\Throwable){return false;}}
 public static function prepare(User $actor,int $auth,string $request,string $csv,string $title,string $reference):Batch {
  if(!Str::isUuid($request)||trim($title)===''||mb_strlen($title)>160||trim($reference)===''||mb_strlen($reference)>2000){self::fail('Use a valid request, intake title and controlled source reference.');}$inputs=self::parse($csv);
  return DB::transaction(function()use($actor,$auth,$request,$inputs,$csv,$title,$reference):Batch {$m=self::actor($actor,$auth);$old=Batch::where('request_token',$request)->lockForUpdate()->first();if($old){if(self::intact($old)&&$old->created_by===$m->id&&$old->source['creator']['auth_version']===$m->auth_version&&$old->source['input_hash']===hash('sha256',$csv)&&$old->source['title']===$title&&$old->source['reference']===$reference){return $old;}self::fail('Request already used. Start a new upload.');}
   $expires=now()->addDay()->setMicrosecond(0);$source=['request'=>$request,'creator'=>['id'=>$m->id,'auth_version'=>$m->auth_version],'input_hash'=>hash('sha256',$csv),'title'=>$title,'reference'=>$reference,'expires_at'=>$expires->toIso8601String(),'rows'=>self::assess($inputs,true)];$b=new Batch(['created_by'=>$m->id,'request_token'=>$request,'state'=>'Pending','version'=>1,'source'=>$source,'source_hash'=>ReviewedImports::hash($source),'expires_at'=>$expires]);$b->id=(string)Str::uuid();$b->save();self::event($b,$m,'preview_prepared',['source_hash'=>$b->source_hash]);return $b;
  },3);
 }
 public static function fresh(Batch $b,bool $lock=false):bool {try{if(!self::intact($b)||$b->state!=='Pending'||now()->greaterThanOrEqualTo($b->expires_at)){return false;}$s=$b->source;$u=User::find($b->created_by);if(!$u||!LearningAccess::manager($u)||$u->auth_version!==$s['creator']['auth_version']||collect($s['rows'])->contains(fn($r)=>count($r['errors'])>0)){return false;}return ReviewedImports::hash(self::assess(array_column($s['rows'],'input'),$lock))===ReviewedImports::hash($s['rows']);}catch(\Throwable){return false;}}
 public static function applied(Batch $b):bool {try{$p=$b->receipt;return self::intact($b)&&$b->state==='Applied'&&$b->reviewed_by&&$b->reviewed_by!==$b->created_by&&$b->reviewed_at&&$p&&$p['source_hash']===$b->source_hash&&$p['version']===$b->version&&$p['by']===$b->reviewed_by&&$p['at']===$b->reviewed_at->toIso8601String()&&count($p['created'])===count($b->source['rows']);}catch(\Throwable){return false;}}
 public static function decide(User $actor,int $auth,Batch $record,int $version,string $decision,string $note):void {
  DB::transaction(function()use($actor,$auth,$record,$version,$decision,$note):void {User::whereIn('id',[$actor->id,$record->created_by])->orderBy('id')->lockForUpdate()->get();$m=self::actor($actor,$auth);$b=Batch::whereKey($record->id)->lockForUpdate()->firstOrFail();if(!self::intact($b)||$b->state!=='Pending'||$b->version!==$version||!in_array($decision,['apply','reject','withdraw'],true)){self::fail('Use the current intact pending intake.');}if($decision!=='withdraw'&&$m->id===$b->created_by){self::fail('A different administrator/CEO must check and apply/reject.');}$created=[];
   if($decision==='apply'){TeachingAllocationChecks::lock();if(!self::fresh($b,true)){self::fail('Intake has errors, expired or its catalogue/source/identity changed. Upload a fresh corrected file.');}foreach($b->source['rows']as$row){$a=Apprentice::create($row['data']);
    try {
        TeachingAllocationChecks::checkAssignment($a);
    } catch (\Illuminate\Validation\ValidationException $error) {
        self::fail('CSV row '.$row['line'].' ('.$a->reference.'): '.implode(' ', $error->errors()['tutor_group_id']));
    }
    AssignmentHistory::record($a,$m->id,null);DB::table('audit_events')->insert(['user_id'=>$m->id,'apprentice_id'=>$a->id,'event'=>'intake_profile_created','created_at'=>now()]);$created[]=['id'=>$a->id,'reference'=>$a->reference,'profile_hash'=>ReviewedImports::hash($a->getAttributes())];}}
   $b->state=['apply'=>'Applied','reject'=>'Rejected','withdraw'=>'Withdrawn'][$decision];$b->version++;$b->reviewed_by=$m->id;$b->reviewed_at=now()->setMicrosecond(0);$b->review_note=$note;$b->receipt=$decision==='apply'?['source_hash'=>$b->source_hash,'version'=>$b->version,'by'=>$m->id,'at'=>$b->reviewed_at->toIso8601String(),'created'=>$created]:null;$b->save();self::event($b,$m,'preview_'.$decision,['created'=>$created,'note'=>$note]);
  },3);
 }
 public static function cohort(Batch $b):array {
  if(!self::applied($b)){return [];}$out=[];foreach($b->receipt['created']as$record){$a=Apprentice::find($record['id']);if(!$a||$a->reference!==$record['reference']){$out[]=['id'=>$record['id'],'reference'=>$record['reference'],'current'=>false,'issues'=>['Created profile identity changed or is unavailable. Reconcile the existing record.']];continue;}$ready=ApprenticeOnboarding::readiness($a);$issues=$ready['issues'];foreach(StaffHandover::SLOTS as$slot=>$label){$u=$a->{$slot.'_id'}?User::find($a->{$slot.'_id'}):null;if(!$u||!StaffHandover::eligible($u,$slot)){$issues[]=$label.' assignment needs checking';}}$tasks=LearningTask::where('apprentice_id',$a->id)->count();if(!$tasks){$issues[]='No task workspace allocated; check programme/cohort/source before allocation';}if(!$a->employer_id){$issues[]='Employer not linked; check actual employment context';}$out[]=['id'=>$a->id,'reference'=>$a->reference,'current'=>true,'name'=>trim($a->first_name.' '.$a->last_name),'issues'=>array_values(array_unique($issues)),'tasks'=>$tasks,'account_state'=>$ready['state']];}return $out;
 }
}
