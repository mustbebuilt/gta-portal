<?php
namespace App\Support;
use App\Models\{ProgrammeSetupTemplate68,TrainingPlanWorkspace};
use Illuminate\Validation\ValidationException;
/** Training hours only. Each unique KSB has one equal programme share. */
class Rpl68 {
 public static function fail(string $message):never {throw ValidationException::withMessages(['setup'=>$message]);}
 public static function canonicalize(mixed $v):mixed {if(!is_array($v)){return is_float($v)&&(float)(int)$v===$v?(int)$v:$v;}if(!array_is_list($v)){ksort($v,SORT_STRING);}return array_map([self::class,'canonicalize'],$v);}
 public static function hash(array $v):string {return hash('sha256',json_encode(self::canonicalize($v),JSON_THROW_ON_ERROR));}
 public static function validateTemplate(array $t):void {
  if(!is_numeric($t['full_hours']??null)||$t['full_hours']<=0||$t['full_hours']>10000||!filled($t['source_ref']??null)||!filled($t['ksb_ref']??null)||!filled($t['qualifications_ref']??null)||!filled($t['release_ref']??null)||!filled($t['standard_code']??null)||!filled($t['standard_version']??null))self::fail('A controlled standard/version, source references and full eligible OTJ hours are required.');
  $codes=array_column($t['ksbs']??[],'code');
  if(!$codes||count($codes)!==count(array_unique($codes))||count($codes)!==count($t['ksbs']))self::fail('The controlled template must list each unique KSB exactly once.');
  $numbers=array_column($t['blocks']??[],'number');sort($numbers);
  if($numbers!==range(1,12)||abs(array_sum(array_column($t['blocks'],'hours'))-$t['full_hours'])>.00001)self::fail('The twelve baseline block hours must reconcile to the full programme hours.');
  $last=-1;
  foreach($t['blocks'] as $b){if(!is_numeric($b['hours'])||$b['hours']<0||!is_int($b['start_day']??null)||!is_int($b['end_day']??null)||$b['start_day']<0||$b['start_day']>$b['end_day']||$b['start_day']<=$last||($b['number']===12&&(float)$b['hours']!==0.0))self::fail('Template block dates must be sequential, non-overlapping; EPA has no training hours.');$last=$b['end_day'];}
  foreach($t['ksbs'] as $k){if(!is_string($k['code'])||!preg_match('/^[KSB][0-9]+$/D',$k['code'])||!filled($k['description']??null)||!is_array($k['blocks']??null)||!$k['blocks']||array_diff($k['blocks'],range(1,11)))self::fail('KSB teaching links need valid numbered training blocks.');
   if(isset($k['allocation'])){if(!is_array($k['allocation'])||abs(array_sum($k['allocation'])-1)>.000001)self::fail('A confirmed KSB block allocation must sum to one.');foreach($k['allocation'] as $n=>$weight)if(!in_array((int)$n,$k['blocks'],true)||!is_numeric($weight)||$weight<0)self::fail('Allocation must use the linked training blocks and non-negative shares.');}
  }
 }
 public static function calculate(array $t,array $r):array {
  self::validateTemplate($t);$codes=array_column($t['ksbs'],'code');$adjustments=$r['adjustments']??[];
  if(!is_array($adjustments)||array_diff(array_keys($adjustments),$codes))self::fail('An adjustment uses a KSB outside this programme/version.');
  foreach($adjustments as $v)if(!is_int($v)||$v<0||$v>100||$v%10!==0)self::fail('Use whole 10-percentage-point increments from 0% to 100%.');
  if(($r['mode']??null)==='none'&&array_sum($adjustments)>0)self::fail('No relevant prior learning cannot contain reductions.');
  $ksbs=count($codes);$unit=(float)$t['full_hours']/$ksbs;$removed=0.0;$byBlock=[];$unresolved=[];
  foreach($t['ksbs'] as $k){$pct=$adjustments[$k['code']]??0;if(!$pct)continue;$hours=$unit*$pct/100;$removed+=$hours;
   $alloc=$k['allocation']??(count($k['blocks'])===1?[(string)$k['blocks'][0]=>1]:null);
   if(!$alloc){foreach($k['blocks'] as $n)$unresolved[$n][]=$k['code'];continue;}
   foreach($alloc as $n=>$weight)$byBlock[$n]=($byBlock[$n]??0)+$hours*$weight;
  }
  $blocks=[];foreach($t['blocks'] as $b){$reduction=$byBlock[$b['number']]??0;$revised=(float)$b['hours']-$reduction;if($revised<-.00001)$unresolved[$b['number']][]='Reduction exceeds this block’s baseline';$blocks[]=[...$b,'original_hours'=>(float)$b['hours'],'removed_hours'=>round($reduction,4),'hours'=>round($revised,4),'unresolved'=>$unresolved[$b['number']]??[]];}
  $revised=round((float)$t['full_hours']-$removed,4);
  if(!$unresolved){$delta=round($revised-array_sum(array_column($blocks,'hours')),4);if($delta){foreach($blocks as &$block){if($block['number']<12&&$block['hours']+$delta>=0){$block['hours']=round($block['hours']+$delta,4);$block['removed_hours']=round($block['original_hours']-$block['hours'],4);break;}}unset($block);}}
  return ['original_hours'=>(float)$t['full_hours'],'removed_hours'=>round($removed,4),'revised_hours'=>$revised,'revised_hours_unrounded'=>(float)$t['full_hours']-$removed,'unit_hours_unrounded'=>$unit,'ksb_count'=>$ksbs,'adjusted_count'=>count(array_filter($adjustments,fn($v)=>$v>0)),'remaining_count'=>$ksbs-count(array_filter($adjustments,fn($v)=>$v>0)),'blocks'=>$blocks,'unresolved'=>$unresolved];
 }
 public static function position(TrainingPlanWorkspace $w):array {
  $v=$w->setup_rpl;$template=ProgrammeSetupTemplate68::find($v['template_id']??0);$sourceOk=$template&&$template->active&&$template->source_hash===($v['template_hash']??null)&&self::hash($template->template)===$template->source_hash;
  $binding=self::hash($v??[]);$complete=$sourceOk&&($v['remaining_confirmed']??false)&&in_array($v['mode']??null,['none','applies'],true);$calc=$v['calculation']??[];
  return ['recorded'=>$complete?($calc['ksb_count']??0):0,'total'=>$calc['ksb_count']??0,'complete'=>(bool)$complete,'source_ok'=>(bool)$sourceOk,'issues'=>$sourceOk?[]:['Controlled setup template changed; reconcile the existing RPL record.'],'binding'=>$binding,'review'=>null,'current'=>(bool)$complete,'approved'=>(bool)$complete,'recognised_hours'=>$complete?$calc['removed_hours']:null,'remaining_hours'=>$complete?$calc['revised_hours']:null,'allocation_reduction'=>null,'remaining_allocation'=>null];
 }
}
