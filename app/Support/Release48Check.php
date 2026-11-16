<?php
namespace App\Support;
use Illuminate\Support\Facades\Route;
class Release48Check {
 public static function report(bool $environment=false,bool $database=false):array {$r=Release47Check::report($environment,$database);$r['pack']='48';foreach(['historical-cohorts.index','historical-cohorts.preview','historical-cohorts.create','historical-cohorts.show','historical-cohorts.edit','historical-cohorts.batch','historical-cohorts.decide','historical-cohorts.report','historical-cohorts.download','historical-cohorts.pdf','historical-cohorts.compare']as$route){$r['checks']['route:'.$route]=Route::has($route);}$r['checks']['historical_cohort_migration_present']=is_file(database_path('migrations/2026_11_15_480000_create_historical_cohorts.php'));if(in_array(false,$r['checks'],true)){$r['status']='needs_attention';}$r['historical_comparisons']='Reviewed frozen planned-end cohorts; evidence-based historical attribution; not inferred current-tutor rankings or official QAR';return $r;}
}
