<?php
namespace App\Support;
use Illuminate\Support\Facades\Route;
class Release52Check {
 public static function report(bool $environment=false,bool $database=false):array{$r=Release51Check::report($environment,$database);$r['pack']='52';foreach(['historical-cohorts.benchmark.show','historical-cohorts.benchmark.download','historical-cohorts.benchmark.pdf']as$name)$r['checks']['route:'.$name]=Route::has($name);$r['checks']['benchmark_method']=HistoricalBenchmarks::FORMAT==='gta-common-planned-duration52'&&HistoricalCohorts::MIN_SAMPLE===5&&HistoricalBenchmarks::MAX_STAFF===40;$r['comparison_scope']='Descriptive common planned-duration mix from independently approved historical cohorts; no causal ranking or official return';if(in_array(false,$r['checks'],true))$r['status']='needs_attention';return $r;}
}
