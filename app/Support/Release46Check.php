<?php
namespace App\Support;
use Illuminate\Support\Facades\Route;
class Release46Check
{
 public static function report(bool $environment=false,bool $database=false):array{
  $r=ReleaseSourceCheck::report($environment,$database);$r['pack']='46';foreach(['restriction-recovery.index','restriction-recovery.preview','restriction-recovery.prepare','restriction-recovery.review','restriction-recovery.apply','calendar.index','calendar.download','review-workspace.index','review-workspace.show','review-workspace.pdf','communications.index','communications.own']as$route){$r['checks']['route:'.$route]=Route::has($route);}$r['checks']['additive_migration_present']=is_file(database_path('migrations/2026_11_15_460000_create_restriction_recovery_runs.php'));if(in_array(false,$r['checks'],true)){$r['status']='needs_attention';}$r['restricted_recovery_actions']='Reviewed blocking only; no automatic reactivation or database restore';$r['offline_reference_dependencies']='Optional isolated test snapshot, not a production vendor replacement';return $r;
 }
}
