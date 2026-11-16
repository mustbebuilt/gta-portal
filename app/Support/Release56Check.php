<?php
namespace App\Support;
class Release56Check {
 public static function report(bool $environment=false,bool $database=false):array {
  $r=Release55Check::report($environment,$database);$r['pack']='56';$r['theme_only']=false;
  $r['checks']['journey_overview_present']=class_exists(JourneyOverview::class)&&is_file(resource_path('views/dashboard/journey-next.blade.php'));
  $r['journey_scope']='Read-only next actions, saved training, current published review booking, separate review due reference and checked-review meeting milestones. No additional signing or training-completion authority.';
  $r['actual_target_acceptance']=false;$r['writes_performed']=false;if(in_array(false,$r['checks'],true))$r['status']='needs_attention';return $r;
 }
}
