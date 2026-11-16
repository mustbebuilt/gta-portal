<?php
namespace App\Support;
use App\Models\{User,Apprentice,SignedReviewRecord,ReviewAppointment};
/** One read-only review presentation for apprentice home and journey. */
class ReviewNextStep {
 public static function for(User $user,Apprentice $a):array {
  abort_unless(LearningAccess::training($user,$a),403);
  $checks=0;$milestones=[];
  foreach(SignedReviewRecord::where('apprentice_id',$a->id)->whereNotNull('checked_at')->whereNull('withdrawn_at')->orderBy('meeting_date')->orderBy('id')->get()as$record){
   try{$intact=SignedReviews::intact($record)&&$record->checked_at->lte(now())&&$record->checked_by&&$record->checked_by!==$record->prepared_by;}catch(\Throwable){$intact=false;}
   if(!$intact){$checks++;continue;}
   $milestones[]=['date'=>$record->meeting_date->toDateString(),'type'=>'Progress review recorded','title'=>'Checked progress review','detail'=>'An independently checked signed-review record is held. Meeting date shown; training achievement and Gateway decisions remain separate.','url'=>SignedReviews::allowed($user,$a)?route('reviews.signed.form',$record->progress_review_draft_id):null];
  }
  $cycle=SignedReviews::cycle($a);if($checks){$cycle['next_due']=null;$cycle['status']='Evidence integrity needs checking';}
  $candidates=ReviewAppointment::where('apprentice_id',$a->id)->where('state','Published')->where('ends_at','>',now()->utc())->orderBy('starts_at')->orderBy('id')->limit(51)->get();$appointment=null;
  foreach($candidates->take(50)as$p){if(ReviewAppointments::published($p)&&ReviewAppointments::current($p)&&ReviewAppointments::visible($user,$p)){$appointment=$p;break;}}
  return ['cycle'=>$cycle,'appointment'=>$appointment,'limited'=>$candidates->count()>50,'checks'=>$checks,'milestones'=>$milestones];
 }
}
