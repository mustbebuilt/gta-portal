<?php
namespace App\Support;
use Illuminate\Support\Facades\Route;
class Release49Check {
 public static function report(bool $environment=false,bool $database=false):array {$r=Release48Check::report($environment,$database);$r['pack']='49';foreach(['centre-access.index','centre-access.preview','centre-access.prepare','centre-access.show','centre-access.review','centre-access.apply','centre-access.revoke','centre-oversight.index','centre-oversight.show','centre-oversight.followup','centre-followups.index','centre-followups.show','centre-followups.update']as$route){$r['checks']['route:'.$route]=Route::has($route);}$r['checks']['centre_manager_migration_present']=is_file(database_path('migrations/2026_11_15_490000_create_centre_manager_access.php'));if(in_array(false,$r['checks'],true)){$r['status']='needs_attention';}$r['centre_manager_scope']='Reviewed expiring group/area oversight and source-linked coordination; canonical admin/tutor/signing/private-support permissions unchanged';return $r;}
}
