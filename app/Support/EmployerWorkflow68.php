<?php
namespace App\Support;
use App\Models\{Employer,User};
use Illuminate\Support\Facades\{DB,Validator};
class EmployerWorkflow68 {
 public static function create(User $u,int $auth,string $token,array $input):Employer {
  return DB::transaction(function()use($u,$auth,$token,$input){GuidedSetup68::actor($u,$auth);DB::table('setup68_locks')->where('key','commit')->lockForUpdate()->first();
   $input['reference']=strtoupper(trim($input['reference']??''));$input['name']=trim($input['name']??'');
   $v=Validator::make($input,['active'=>['nullable','boolean'],'reference'=>['required','string','max:40','regex:/^[A-Z0-9][A-Z0-9_-]*$/'],'name'=>['required','string','max:200'],'contact_name'=>['nullable','string','max:150'],'contact_email'=>['nullable','email:rfc','max:254'],'contact_phone'=>['nullable','string','max:50'],'size_band'=>['nullable','in:1–9,10–49,50–249,250+,Unknown'],'duplicate_reason'=>['nullable','string','max:500']])->validate();
   $hash=Rpl68::hash($v);$old=DB::table('employer_requests68')->where('token',$token)->first();if($old){abort_unless((int)$old->actor_id===$u->id&&$old->payload_hash===$hash,409,'This employer request changed. Reload before saving.');return Employer::findOrFail($old->employer_id);}
   if(Employer::whereRaw('UPPER(reference)=?',[$v['reference']])->exists())Rpl68::fail('Employer reference already exists. Select the existing employer.');
   if(Employer::whereRaw('LOWER(name)=?',[mb_strtolower($v['name'])])->exists()&&!filled($v['duplicate_reason']??null))Rpl68::fail('Possible employer name match. Select the existing employer or explain the separate organisation.');
   $e=Employer::create(collect($v)->except('duplicate_reason')->all());DB::table('employer_requests68')->insert(['token'=>$token,'actor_id'=>$u->id,'employer_id'=>$e->id,'payload_hash'=>$hash,'duplicate_reason'=>filled($v['duplicate_reason']??null)?\Illuminate\Support\Facades\Crypt::encryptString($v['duplicate_reason']):null,'created_at'=>now()]);
   DB::table('directory_events')->insert(['kind'=>'employers','record_id'=>$e->id,'event'=>'created','user_id'=>$u->id,'created_at'=>now()]);return $e;
  });
 }
}
