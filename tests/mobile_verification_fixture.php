<?php
// Called only by the private Python fixture runner. No real provider requests.
require __DIR__.'/../vendor/autoload.php';
$app=require __DIR__.'/../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\{Apprentice,ApprenticeMobileChallenge as Challenge,User};
use App\Support\{ApprenticeAlerts,MobileVerification};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Http};
if(getenv('GTA_MOBILE_FIXTURE')!=='1'||config('database.default')!=='sqlite'||basename((string)config('database.connections.sqlite.database'))!=='fictional.sqlite'||!str_starts_with(basename(dirname((string)config('database.connections.sqlite.database'))),'gta-mobile-proof-')){throw new RuntimeException('Disposable fixture guard refused.');}
Http::preventStrayRequests();$stateFile=getenv('GTA_MOBILE_FIXTURE_STATE');$phase=$argv[1]??'';
if($phase==='seed'){
 $u=User::factory()->create(['role'=>'apprentice','email'=>'sms-apprentice@example.test']);$a=Apprentice::factory()->create(['user_id'=>$u->id]);$a->alert_channel='both';$a->alerts_enabled=true;$a->alert_mobile='+447700900123';$a->save();$session=app('session')->driver();$session->start();$session->put('auth_version',$u->auth_version);$session->save();file_put_contents($stateFile,json_encode(['user'=>$u->id,'apprentice'=>$a->id,'session'=>$session->getId()]));echo json_encode(['seeded'=>true]);exit;
}
$data=json_decode(file_get_contents($stateFile),true,512,JSON_THROW_ON_ERROR);$u=User::findOrFail($data['user']);$a=Apprentice::findOrFail($data['apprentice']);$session=app('session')->driver();$session->setId($data['session']);$session->start();$r=Request::create(config('app.url').'/account/alerts','POST');$r->setLaravelSession($session);$r->setUserResolver(fn()=>$u);
if($phase==='issue'){
 $observed=false;Http::fake([MobileVerification::ENDPOINT=>function($request)use(&$data,&$observed){
  config(['database.connections.mobile_observer'=>config('database.connections.sqlite')]);$observed=DB::connection('mobile_observer')->table('apprentice_mobile_challenges')->where('id',$request['client-ref'])->value('state')==='Reserved';
  preg_match('/code: ([0-9]{8})/',$request['text'],$m);$data['code']=$m[1];return Http::response(['message-count'=>'1','messages'=>[['status'=>'0','to'=>'447700900123','message-id'=>'FICTIONAL-ACCEPTED-ID']]],200);
 }]);$result=MobileVerification::issue($r);$c=Challenge::firstOrFail();$data['challenge']=$c->id;file_put_contents($stateFile,json_encode($data));$session->save();
 echo json_encode(['state'=>$result,'committed_reservation_seen_by_independent_connection'=>$observed,'code_plaintext_stored'=>str_contains(json_encode($c->getAttributes()),$data['code']),'phone_plaintext_stored'=>str_contains(json_encode($c->getAttributes()),'447700900123')]);exit;
}
if(in_array($phase,['wrong','verify','replay'],true)){
 $rejected=false;try{MobileVerification::confirm($r,$data['challenge'],$phase==='wrong'?'00000000':$data['code']);}catch(Illuminate\Validation\ValidationException){$rejected=true;}
 $c=Challenge::findOrFail($data['challenge']);$a->refresh();$session->save();echo json_encode(['rejected'=>$rejected,'state'=>$c->state,'attempts'=>$c->attempts,'verified'=>(bool)$a->alert_mobile_verified_at,'code_hash_cleared'=>$c->code_hash===null,'channel'=>$a->alert_channel,'enabled'=>$a->alerts_enabled]);exit;
}
if($phase==='phone_change'){
 DB::transaction(function()use($a,$u):void {$a=Apprentice::whereKey($a->id)->lockForUpdate()->firstOrFail();ApprenticeAlerts::apply($a,$u,$u->auth_version,['alert_revision'=>$a->alert_revision,'alert_channel'=>'both','alerts_enabled'=>true,'alert_mobile'=>'+447700900124']);$a->version++;$a->save();});echo json_encode(['verification_cleared'=>Apprentice::findOrFail($a->id)->alert_mobile_verified_at===null]);exit;
}
throw new RuntimeException('Unknown fixture phase.');
