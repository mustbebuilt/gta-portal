<?php
namespace App\Support;
use Illuminate\Support\Facades\Route;
class Release54Check {
 public static function report(bool$environment=false,bool$database=false):array{$r=Release53Check::report($environment,$database);$r['pack']='54';foreach(['annual.index','annual.preview','annual.prepare','annual.move']as$name)$r['checks']['route:'.$name]=Route::has($name);$r['checks']['annual_bounds']=AnnualTimetable::MAX_PLANS===128&&AnnualTimetable::MAX_ROWS===5000&&AnnualTimetable::BEAM===8;$guides=HelpCatalogue::all();foreach(['timetable-add','timetable-move','timetable-year']as$id)$r['checks']['guide:'.$id]=isset($guides[$id]);$r['timetable_scope']='Rolling year; up to53 weekly dates with explicit exclusions; reviewed dates/time/same-site rooms and nominated transferable staff. Dragging opens checked forms only. Registered/delivered history and primary teaching/marking/private responsibilities remain protected.';$r['actual_target_acceptance']=false;$r['writes_performed']=false;if(in_array(false,$r['checks'],true))$r['status']='needs_attention';return$r;}
}
