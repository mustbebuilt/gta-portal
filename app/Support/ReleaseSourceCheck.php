<?php
namespace App\Support;
use Illuminate\Support\Facades\{Route,DB};
class ReleaseSourceCheck
{
 public static function report(bool $environment=false,bool $database=false):array
 {
  $checks=[];$assets=[];
  try{$manifest=json_decode(file_get_contents(resource_path('data/visual-assets45.json')),true,512,JSON_THROW_ON_ERROR);foreach($manifest as$name=>$receipt){if(!preg_match('/^[a-z0-9-]+\.(?:svg|webp)$/D',$name)){throw new \RuntimeException();}$p=public_path('assets/'.$name);$assets[$name]=is_file($p)&&!is_link($p)&&hash_file('sha256',$p)===$receipt['sha256']&&filesize($p)===$receipt['bytes'];}$checks['visual_assets']=count($assets)===3&&!in_array(false,$assets,true);}catch(\Throwable){$checks['visual_assets']=false;}
  foreach(['login','login.store','password.request','privacy.public','accessibility.index','visual-reports.index','visual-reports.pdf','work-moves.index','work-moves.show','work-moves.apply','quick-actions.index','saved-work.index','record-checks.index']as$name){$checks['route:'.$name]=Route::has($name);}
  try{$checks['help_catalogue']=count(HelpCatalogue::all())===42&&isset(HelpCatalogue::all()['retention-recruitment']);}catch(\Throwable){$checks['help_catalogue']=false;}
  $checks['pdf_renderer_available']=class_exists(\Dompdf\Dompdf::class);
  $checks['voice_availability_statement_removed']=!str_contains(file_get_contents(public_path('assets/portal.js')),'No Yorkshire-labelled voice');
  $configuration=$environment?['checked'=>true,'errors'=>StagingAccess::errors()]:['checked'=>false,'errors'=>[]];
  $schema=$database?ApplicationSchema::inspect(DB::connection()):ApplicationSchema::unavailable();
  $failed=in_array(false,$checks,true)||$configuration['errors']||$database&&$schema['status']!=='pass';
  return ['pack'=>'45','status'=>$failed?'needs_attention':'requested_checks_passed','checks'=>$checks,'asset_checks'=>$assets,'configuration'=>$configuration,'schema'=>$schema,'writes_performed'=>false,'messages_sent'=>false,'actual_target_acceptance'=>false,'meaning'=>'Requested source/configuration/schema-name checks only. Actual native constraints/concurrency, Cloud, tenant, browser/accessibility and retained-key restore still require target evidence.'];
 }
}
