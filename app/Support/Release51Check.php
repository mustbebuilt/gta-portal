<?php
namespace App\Support;
class Release51Check {
 public static function report(bool $environment=false,bool $database=false):array{$r=Release50Check::report($environment,$database);$r['pack']='51';$r['checks']['nearby_date_bound']=TimetableOptimiser::MAX_DATE_FLEX===2&&TimetableOptimiser::MAX_FLEX_PLANS===4&&TimetableOptimiser::MAX_FLEX_OPTIONS===240;$r['date_scope']='Reviewed nearby dates for future non-teaching whole plans; Teaching dates and staff unchanged. No automatic allocation or messages';if(in_array(false,$r['checks'],true))$r['status']='needs_attention';return $r;}
}
