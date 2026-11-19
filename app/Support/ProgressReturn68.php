<?php
namespace App\Support;
use App\Models\{Apprentice,User};
use Illuminate\Http\Request;
class ProgressReturn68 {
 public static function decorate(Request $r,array $report):array {
  if($report['demo']||!in_array($r->user()->role,['admin','ceo','tutor'],true))return $report;
  $report['tutors68']=in_array($r->user()->role,['admin','ceo'],true)?User::whereIn('id',Apprentice::visibleTo($r->user())->select('tutor_id')->whereNotNull('tutor_id'))->orderBy('name')->pluck('name','id')->all():[];
  $origin=$r->query('origin68');if($origin!==null){abort_unless(is_string($origin)&&strlen($origin)<=3000,422);$raw=base64_decode($origin,true);abort_unless($raw!==false,422);$f=json_decode($raw,true);abort_unless(is_array($f),422);$back=ProgressDashboard66::filters(Request::create('/','GET',$f));$report['back68']=route('progress66.index',$back).'#records';}
  $origin=base64_encode(json_encode($report['filters'],JSON_THROW_ON_ERROR));
  $detail=fn($id)=>route('progress66.index',[...$report['filters'],'apprentice'=>$id,'metric'=>'all','page'=>1,'origin68'=>$origin]);
  foreach(['rows','details'] as $set)foreach($report[$set] as &$row)$row['url']=$detail($row['id']);unset($row);
  foreach($report['actions'] as &$a)$a['progress_url68']=$detail($a['person_id']);unset($a);return $report;
 }
}
