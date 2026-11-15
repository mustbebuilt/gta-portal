<?php
namespace App\Support;
class AcceptanceSource {
 public const ROOTS=['app','config','routes','database/migrations','resources/views','resources/data','resources/task-pdfs','public/assets','tests'];
 public const FILES=['artisan','bootstrap/app.php','bootstrap/providers.php','composer.json','composer.lock','phpunit.xml'];
 public const REQUIRED=['app/Support/ApplicationSchema.php','app/Support/PrivateEvidence.php','app/Support/AccountAccess.php','app/Support/SupportAccess.php','app/Support/ApprenticeNotificationDelivery.php','app/Support/StagingAcceptance.php'];
 public static function fingerprint(array $files,string $runtime):string {return ReviewedImports::hash(['files'=>$files,'runtime_hash'=>$runtime]);}
 private function scan(string $root):array {
  $map=[];$bytes=0;$errors=[];$visit=function(string $path,string $relative)use(&$visit,&$map,&$bytes,&$errors,$root):void {
   if(is_link($path)){$errors[]='symlink_source';return;}
   if(is_dir($path)){
    foreach(scandir($path)?:[] as$name){if(in_array($name,['.','..','__pycache__','.pytest_cache'],true)){continue;}$visit($path.'/'.$name,$relative.'/'.$name);}return;
   }
   if(!is_file($path)){$errors[]='missing_source';return;}
   if(str_ends_with($relative,'.pyc')){return;}
   if(preg_match('/(^|\/)\.env($|\.)|\.(pem|key|sqlite)$/i',$relative)){$errors[]='private_artifact_in_source';return;}
   if(count($map)>=20000){$errors[]='source_file_limit';return;}$size=filesize($path);
   if($size===false||$size>67108864||$bytes+$size>1073741824){$errors[]='source_size_limit';return;}
   $hash=hash_file('sha256',$path);if(!$hash){$errors[]='source_read_failed';return;}$map[$relative]=$hash;$bytes+=$size;
  };
  foreach([...self::ROOTS,...self::FILES]as$name){$visit($root.'/'.$name,$name);}foreach(self::REQUIRED as$name){if(!isset($map[$name])){$errors[]='required_source_missing';}}
  ksort($map);return ['files'=>$map,'bytes'=>$bytes,'blockers'=>array_values(array_unique($errors))];
 }
 public function capture(?string $root=null):array {
  $root=$root??base_path();$blockers=[];
  try {if(is_link($root)||!is_dir($root)){throw new \RuntimeException();}$first=$this->scan($root);$second=$this->scan($root);$blockers=array_values(array_unique([...$first['blockers'],...$second['blockers']]));if($first!==$second){$blockers[]='source_changed_during_scan';}
   $runtime=['framework'=>app()->version(),'php'=>PHP_VERSION,'environment'=>app()->environment(),'origin_hash'=>hash('sha256',(string)config('app.url')),'database_driver'=>(string)config('database.default'),'cache_driver'=>(string)config('cache.default'),'session_driver'=>(string)config('session.driver'),'debug'=>config('app.debug')===true,'secure_sessions'=>config('session.secure')===true,'encrypted_sessions'=>config('session.encrypt')===true,'staging_gate'=>config('deployment.staging_enabled')===true,'configuration_seal'=>hash_hmac('sha256',serialize(collect(['mail','filesystems','database','session','cache','deployment','second_factor','apprentice_notifications','onboarding','account_access','portal_reminders','evidence','trustedproxy'])->mapWithKeys(fn($key)=>[$key=>config($key)])->all()),(string)config('app.key')),'reminder_send_enabled'=>config('apprentice_notifications.enabled')===true];
   $runtimeHash=ReviewedImports::hash($runtime);$files=$second['files'];return ['status'=>$blockers?'blocked':'captured','fingerprint'=>self::fingerprint($files,$runtimeHash),'files'=>$files,'file_count'=>count($files),'bytes'=>$second['bytes'],'runtime'=>$runtime,'runtime_hash'=>$runtimeHash,'blockers'=>$blockers,'database_accessed'=>false,'writes_performed'=>false,'secrets_collected'=>false,'file_contents_exported'=>false,'deployment_approved'=>false];
  }catch(\Throwable){return ['status'=>'blocked','fingerprint'=>null,'files'=>[],'file_count'=>0,'bytes'=>0,'blockers'=>['source_scan_failed'],'database_accessed'=>false,'writes_performed'=>false,'secrets_collected'=>false,'file_contents_exported'=>false,'deployment_approved'=>false];}
 }
 public static function valid(array $s):bool {return ($s['status']??null)==='captured'&&!($s['blockers']??[])&&is_array($s['files']??null)&&isset($s['runtime_hash'],$s['fingerprint'])&&self::fingerprint($s['files'],$s['runtime_hash'])===$s['fingerprint'];}
 public static function summary(array $s):array {unset($s['files']);return $s;}
}
