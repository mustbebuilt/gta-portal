<?php
namespace App\Support;
use Illuminate\Support\Collection;
class PortalVisuals
{
 public static function tasks(Collection $tasks):array
 {
  $n=['Approved'=>0,'Awaiting marking'=>0,'Referred'=>0,'Draft / other'=>0,'Source needs checking'=>0];
  foreach($tasks as$t){$kind=$t->template['kind']??'source-pending';$key=$kind==='source-pending'?'Source needs checking':(array_key_exists($t->status,$n)?$t->status:'Draft / other');$n[$key]++;}
  return ['key'=>'tasks','title'=>'Allocated training task decisions','type'=>'donut','unit'=>'tasks','note'=>'Current allocated tasks only. Source-pending tasks are separate. This is not overall apprenticeship, block or KSB achievement.','rows'=>self::rows($n)];
 }
 public static function topics(Collection $topics):array
 {
  $rows=[];foreach(['maths','english']as$s){$set=$topics->where('subject',$s)->filter(fn($t)=>(float)$t->planned_hours>0);$rows[]=['label'=>ucfirst($s),'value'=>$set->count(),'second'=>$set->where('status','Completed')->count()];}
  return ['key'=>'topics','title'=>'Maths and English training topics','type'=>'grouped','unit'=>'topics','first'=>'Assigned with planned hours','second'=>'Completed','note'=>'Only assigned topics with positive planned hours. Zero-hour secure areas are excluded. Topic completion does not award a qualification and these hours are not added to main-aim OTJ.','rows'=>$rows];
 }
 public static function cohort(array $aggregate):array
 {
  $counts=$aggregate['counts'];return [['key'=>'programme-status','title'=>'Recorded programme status','type'=>'bars','unit'=>'apprentices','note'=>'Current recorded context for this start-date cohort. Unknown records remain visible; no official achievement rate is inferred.','rows'=>self::rows(['Active'=>$counts['active'],'Break in learning'=>$counts['break'],'Completed'=>$counts['completed'],'Withdrawn'=>$counts['withdrawn'],'Transferred'=>$counts['transferred'],'Unknown / check'=>$counts['unknown']])],['key'=>'programme-count','title'=>'Cohort by programme','type'=>'bars','unit'=>'apprentices','note'=>'All programme names in the selected cohort, including non-automotive programmes. Charts group entries after the first13; the exact table retains every entry. Current source data only.','rows'=>array_map(fn($g)=>['label'=>$g['programme'],'value'=>$g['counts']['cohort']],$aggregate['groups'])]];
 }
 public static function rows(array $n):array{$out=[];foreach($n as$label=>$value){$out[]=['label'=>$label,'value'=>$value];}return $out;}
}
