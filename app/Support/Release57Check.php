<?php
namespace App\Support;
class Release57Check {
 public static function report(bool $environment=false,bool $database=false):array {
  $r=Release56Check::report($environment,$database);$r['pack']='57';$r['theme_only']=false;
  $r['checks']['next_step_and_gateway_present']=class_exists(ReviewNextStep::class)&&class_exists(GatewaySummary::class)&&isset(HelpCatalogue::all()['gateway-preparation']);
  $r['actual_target_acceptance']=false;$r['writes_performed']=false;if(in_array(false,$r['checks'],true))$r['status']='needs_attention';return $r;
 }
}
