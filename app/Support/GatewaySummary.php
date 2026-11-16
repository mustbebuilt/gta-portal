<?php
namespace App\Support;
use App\Models\{User,Apprentice};
/** Safe small projection; never changes or approves criteria or readiness checks. */
class GatewaySummary {
 public static function for(User $user,Apprentice $a,?array $position=null):array {
  abort_unless(GatewayReadiness::allowed($user,$a),403);$p=$position??GatewayReadiness::position($a);$counts=['Met'=>0,'Missing'=>0,'Unknown'=>0];$outstanding=[];
  if($p['current']){foreach($p['rows']as$row){$state=in_array($row['state'],array_keys($counts),true)?$row['state']:'Unknown';$counts[$state]++;if($state==='Met'){continue;}$c=$row['criterion'];$outstanding[]=['label'=>$c['label'],'category'=>GatewayReadiness::CATEGORIES[$c['category']],'state'=>$state,'url'=>route('gateway.show',$a).'#gateway-criterion-'.$c['key']];}}
  // Explicit missing requirements first, then checks whose position is not known.
  usort($outstanding,fn($x,$y)=>($x['state']==='Missing'?0:1)<=>($y['state']==='Missing'?0:1));
  return ['current'=>(bool)$p['current'],'counts'=>$counts,'total'=>array_sum($counts),'all_met'=>(bool)($p['current']&&$p['all_met']),'outstanding'=>$outstanding,'preview'=>array_slice($outstanding,0,3),'url'=>route('gateway.show',$a)];
 }
}
