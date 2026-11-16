<?php
namespace App\Support;
use App\Models\{Apprentice,User,SignedReviewRecord,ReviewAppointment};
/** Read-only presentation: plans are not achievements and appointments are not signed reviews. */
class JourneyOverview {
 public static function for(User $user,Apprentice $a):array {
  abort_unless(LearningAccess::training($user,$a),403);
  $review=ReviewNextStep::for($user,$a);$events=array_merge(JourneyEvents::for($a),$review['milestones']);
  usort($events,fn($x,$y)=>strcmp($x['date'],$y['date'])?:strcmp($x['title'],$y['title']));
  $position=ApprenticeExperience::home($a);$position['review']=$review['cycle'];
  return ['events'=>$events,'blocks'=>$position['blocks'],'nextStep'=>$position,'appointment'=>$review['appointment'],'appointmentLimited'=>$review['limited'],'reviewChecks'=>$review['checks'],'reviewSchedule'=>$review,'gatewaySummary'=>GatewaySummary::for($user,$a)];
 }
}
