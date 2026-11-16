<?php
namespace App\Support;
use Illuminate\Support\Facades\Route;
class Release47Check {
 public static function report(bool $environment=false,bool $database=false):array{$r=Release46Check::report($environment,$database);$r['pack']='47';foreach(['optimiser.index','optimiser.preview','optimiser.prepare','optimiser.show','optimiser.apply','optimiser.cancel']as$route){$r['checks']['route:'.$route]=Route::has($route);}$r['checks']['optimiser_migration_present']=is_file(database_path('migrations/2026_11_15_470000_create_timetable_optimisation_runs.php'));if(in_array(false,$r['checks'],true)){$r['status']='needs_attention';}$r['optimiser']='Reviewed bounded same-date search; no automatic allocation, register mutation or delivery claim';return $r;}
}
