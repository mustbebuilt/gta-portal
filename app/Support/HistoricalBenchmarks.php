<?php
namespace App\Support;

use App\Models\{User,HistoricalCohort as Cohort};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/** Read-only context from two independently approved frozen cohorts. */
class HistoricalBenchmarks
{
 public const FORMAT='gta-common-planned-duration52';
 public const MAX_STAFF=40;
 public const BANDS=['up_to_24'=>'Up to 24 calendar months','over_24_to_36'=>'Over 24 to 36 calendar months','over_36'=>'Over 36 calendar months'];
 public static function band(string $start,string $end):string
 {
  try{$a=Carbon::createFromFormat('!Y-m-d',$start);$b=Carbon::createFromFormat('!Y-m-d',$end);}catch(\Throwable){CentrePlanning::fail('The captured planned dates need checking.');}
  if($a->toDateString()!==$start||$b->toDateString()!==$end||$b->lt($a)){CentrePlanning::fail('The captured planned dates need checking.');}
  return $b->lte($a->copy()->addMonthsNoOverflow(24))?'up_to_24':($b->lte($a->copy()->addMonthsNoOverflow(36))?'over_24_to_36':'over_36');
 }
 private static function emptyCounts(array $shape):array{return array_fill_keys(array_keys($shape),0);}
 private static function blankBands(array $shape):array{$b=[];foreach(self::BANDS as$key=>$label)$b[$key]=self::emptyCounts($shape);return $b;}
 private static function classify(array $d):string
 {
  if(!$d['mature']||$d['date_check'])CentrePlanning::fail('Both captured cohorts must be fully mature with checked planned dates.');
  if($d['outcome_after_window'])return 'after_window';
  $o=$d['outcome'];return match($o['status']){'Completed'=>match($o['achievement']){'Achieved'=>'achieved','Not achieved'=>'not_achieved','Awaiting result'=>'awaiting_result',default=>'unknown'},'Withdrawn'=>'withdrawn','Transferred'=>'transferred','Active'=>'active','Break in learning'=>'break',default=>'unknown'};
 }
 private static function rate(array $c):?float
 {
  $pending=$c['active']+$c['break']+$c['awaiting_result']+$c['after_window']+$c['unknown']+$c['date_check'];
  return $c['mature']>=HistoricalCohorts::MIN_SAMPLE&&!$pending?100*$c['achieved']/$c['mature']:null;
 }
 private static function side(?array $raw,array $bands,array $weights,bool $attributed):array
 {
  $rows=[];$covered=0;$common=array_sum(array_column($weights,'members'));$weighted=0;$reasons=[];
  foreach($weights as$w){$key=$w['key'];$c=$bands[$key];$rate=$attributed?self::rate($c):null;$reason=null;
   if($w['members']>0&&$rate===null){$reason=!$attributed?'Historical tutor is unknown or shared':($c['mature']<HistoricalCohorts::MIN_SAMPLE?'Fewer than five mature records in this band':'Pending, unknown or after-window outcomes in this band');$reasons[]=$w['label'].': '.$reason;}
   if($w['members']>0&&$rate!==null){$covered+=$w['members'];$weighted+=$w['members']*$rate;}
   $rows[]=['key'=>$key,'label'=>$w['label'],'counts'=>$c,'percentage'=>$rate,'common_weight'=>$w['weight'],'withheld_reason'=>$reason];
  }
  $present=$raw!==null&&$raw['counts']['cohort']>0;
  if(!$present)$reasons=['No records for this tutor in this cohort',...$reasons];
  $value=$present&&$attributed&&$covered===$common?$weighted/$common:null;
  return ['present'=>$present,'counts'=>$raw['counts']??self::emptyCounts($bands[array_key_first($bands)]),'raw_percentage'=>$raw['achievement_percent']??null,'same_mix_percentage'=>$value,'covered_common_members'=>$covered,'common_members'=>$common,'coverage_percentage'=>100*$covered/$common,'bands'=>$rows,'withheld_reasons'=>array_values(array_unique($reasons))];
 }
 private static function pair(string $key,string $name,?array $a,?array $b,array $bandsA,array $bandsB,array $weights,bool $attributed=true):array
 {
  $earlier=self::side($a,$bandsA,$weights,$attributed);$later=self::side($b,$bandsB,$weights,$attributed);
  $old=$earlier['same_mix_percentage'];$new=$later['same_mix_percentage'];$rawA=$earlier['raw_percentage'];$rawB=$later['raw_percentage'];
  return ['staff_key'=>$key,'staff_name'=>$name,'earlier'=>$earlier,'later'=>$later,'raw_percentage_point_change'=>$rawA!==null&&$rawB!==null?$rawB-$rawA:null,'same_mix_percentage_point_change'=>$old!==null&&$new!==null?$new-$old:null];
 }
 private static function seal(Cohort $c):array{return ['cohort_id'=>$c->id,'version'=>$c->version,'source_hash'=>$c->source_hash,'details_hash'=>$c->details_hash,'reviewed_rows_hash'=>$c->proof['decision']['rows_hash'],'approval_proof_hash'=>ReviewedImports::hash($c->proof),'approved_at'=>$c->approved_at->toIso8601String()];}
 public static function capture(User $actor,Cohort $earlier,Cohort $later):array
 {
  return DB::transaction(function()use($actor,$earlier,$later){
   $u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();HistoricalCohorts::actor($u);
   $ids=[$earlier->id,$later->id];sort($ids,SORT_STRING);$locked=Cohort::whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
   abort_unless($locked->has($earlier->id)&&$locked->has($later->id),404);
   $a=$locked[$earlier->id];$b=$locked[$later->id];$comparison=HistoricalCohorts::compare($u,$a,$b);
   if(count($comparison['groups'])>self::MAX_STAFF)CentrePlanning::fail('Choose smaller approved cohorts; this context report supports up to 40 historical staff groups. No group is silently omitted.');
   $shape=$comparison['earlier']['counts'];$all=[self::blankBands($shape),self::blankBands($shape)];$staff=[[],[]];$pool=array_fill_keys(array_keys(self::BANDS),0);
   foreach([$a,$b]as$i=>$cohort){$rows=HistoricalCohorts::rows($cohort,true);
    foreach($rows as$row){$d=$row->details['source'];$band=self::band((string)$d['start_date'],(string)$d['planned_end_date']);$kind=self::classify($d);$key=$row->historical_tutor_id?(string)$row->historical_tutor_id:'unknown';$staff[$i][$key]??=self::blankBands($shape);
     foreach(['cohort','mature',$kind]as$field){$all[$i][$band][$field]++;$staff[$i][$key][$band][$field]++;}$pool[$band]++;
    }
    // A presentation layer must reconcile exactly to its approved source totals.
    foreach($shape as$field=>$_){if(array_sum(array_column($all[$i],$field))!==$comparison[$i===0?'earlier':'later']['counts'][$field])CentrePlanning::fail('The duration analysis does not reconcile to its approved source.');}
   }
   foreach($comparison['groups']as$g){foreach(['earlier','later']as$i=>$side){foreach(($g[$side]['counts']??self::emptyCounts($shape))as$field=>$n){if(array_sum(array_column($staff[$i][$g['staff_key']]??self::blankBands($shape),$field))!==$n)CentrePlanning::fail('The tutor band totals do not reconcile to the approved source.');}}}
   $total=array_sum($pool);if(!$total)CentrePlanning::fail('No mature captured records are available.');$weights=[];foreach(self::BANDS as$key=>$label)$weights[]=['key'=>$key,'label'=>$label,'members'=>$pool[$key],'weight'=>$pool[$key]/$total];
   $overall=self::pair('all','Whole captured cohort',['counts'=>$comparison['earlier']['counts'],'achievement_percent'=>$comparison['earlier']['achievement_percent']],['counts'=>$comparison['later']['counts'],'achievement_percent'=>$comparison['later']['achievement_percent']],$all[0],$all[1],$weights);
   $groups=[];foreach($comparison['groups']as$g){$key=$g['staff_key'];$groups[]=self::pair($key,$g['staff_name'],$g['earlier'],$g['later'],$staff[0][$key]??self::blankBands($shape),$staff[1][$key]??self::blankBands($shape),$weights,$key!=='unknown');}
   $out=['format'=>self::FORMAT,'method'=>['factor'=>'Captured planned programme duration only','bands'=>self::BANDS,'weight_basis'=>'Combined mature members of the two complete approved cohorts, including unknown/shared tutor attributions','formula'=>'Sum of (common band weight x band recorded achievement percentage), using the same weights on both sides and for every tutor','minimum_mature_records_per_positive_weight_band'=>HistoricalCohorts::MIN_SAMPLE,'partial_coverage_rescaled'=>false,'missing_outcomes_imputed'=>false,'duration_is_rpl'=>false,'other_intake_factors_adjusted'=>false,'small_sample_rule_is_a_privacy_guarantee'=>false],
    'earlier'=>['meta'=>$comparison['earlier']['meta'],'source'=>self::seal($a)],'later'=>['meta'=>$comparison['later']['meta'],'source'=>self::seal($b)],'weights'=>$weights,'overall'=>$overall,'groups'=>$groups,'official_qar'=>false,'causal_staff_effectiveness'=>false,'staff_ranking'=>false,'apprentice_identities_in_export'=>false];
   $out['analysis_hash']=ReviewedImports::hash($out);return $out;
  },3);
 }
}
