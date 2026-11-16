<?php
namespace App\Support;
use App\Models\{Apprentice,User,SignedReviewRecord,ReviewAppointment};
/** Read-only presentation: plans are not achievements and appointments are not signed reviews. */
class JourneyOverview {
 public static function for(User $user,Apprentice $a):array {
  abort_unless(LearningAccess::training($user,$a),403);
  $events=JourneyEvents::for($a);$reviewChecks=0;
  $records=SignedReviewRecord::where('apprentice_id',$a->id)->whereNotNull('checked_at')->whereNull('withdrawn_at')->orderBy('meeting_date')->orderBy('id')->get();
  foreach($records as$record){
   try{$intact=SignedReviews::intact($record)&&$record->checked_at->lte(now())&&$record->checked_by&&$record->checked_by!==$record->prepared_by;}catch(\Throwable){$intact=false;}
   if(!$intact){$reviewChecks++;continue;}
   $events[]=['date'=>$record->meeting_date->toDateString(),'type'=>'Progress review recorded','title'=>'Checked progress review','detail'=>'An independently checked signed-review record is held. Meeting date shown; training achievement and Gateway decisions remain separate.','url'=>SignedReviews::allowed($user,$a)?route('reviews.signed.form',$record->progress_review_draft_id):null];
  }
  usort($events,fn($x,$y)=>strcmp($x['date'],$y['date'])?:strcmp($x['title'],$y['title']));
  $position=ApprenticeExperience::home($a);
  if($reviewChecks){$position['review']['next_due']=null;$position['review']['status']='Evidence integrity needs checking';}
  $candidates=ReviewAppointment::where('apprentice_id',$a->id)->where('state','Published')->where('ends_at','>',now()->utc())->orderBy('starts_at')->orderBy('id')->limit(51)->get();$appointment=null;
  foreach($candidates->take(50)as$p){if(ReviewAppointments::published($p)&&ReviewAppointments::current($p)&&ReviewAppointments::visible($user,$p)){$appointment=$p;break;}}
  return ['events'=>$events,'blocks'=>$position['blocks'],'nextStep'=>$position,'appointment'=>$appointment,'appointmentLimited'=>$candidates->count()>50,'reviewChecks'=>$reviewChecks];
 }
}
