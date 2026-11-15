<?php
namespace App\Support;
use App\Models\{User,Apprentice,ProgressReviewDraft,ReviewPreparationBrief};
class ReviewWorkspace
{
 public static function people(User $u){abort_unless(ReviewReminders::allowed($u),403);return PortalActions::people($u,'reviews');}
 public static function position(User $u,Apprentice $a):array
 {
  abort_unless(SignedReviews::allowed($u,$a),403);$draft=ProgressReviewDraft::where('apprentice_id',$a->id)->where('state','Draft')->orderByDesc('updated_at')->orderByDesc('id')->first();$latest=$draft??ProgressReviewDraft::where('apprentice_id',$a->id)->orderByDesc('updated_at')->orderByDesc('id')->first();$plan=DeliveryPlan::approved($a);
  $preparation=$draft?ReviewPreparation::inspect($draft->fields??[],$draft->source_snapshot??[],$plan):null;
  return ['apprentice'=>$a,'draft_id'=>$draft?->id,'latest_id'=>$latest?->id,'state'=>$draft?'Draft':($latest?->state??'No saved draft'),'version'=>$latest?->version,'pending'=>$preparation?array_column($preparation['pending'],'label'):[],'ready'=>$preparation['ready']??false,'current_plan'=>(bool)$plan,'captures'=>$draft?ReviewPreparationBrief::where('progress_review_draft_id',$draft->id)->count():0,'url'=>$latest?route('reviews.edit',[$a,$latest]):route('reviews.create',$a)];
 }
 public static function worksheet(User $u,Apprentice $a,string $from,string $to):array
 {
  abort_unless(SignedReviews::allowed($u,$a),403);validator(compact('from','to'),['from'=>'required|date_format:Y-m-d|before_or_equal:today','to'=>'required|date_format:Y-m-d|after_or_equal:from|before_or_equal:today'])->validate();abort_if(\Carbon\Carbon::parse($from)->diffInDays(\Carbon\Carbon::parse($to))>366,422,'Choose a training period of up to366 days.');
  $draft=ProgressReviewDraft::where('apprentice_id',$a->id)->where('state','Draft')->orderByDesc('updated_at')->orderByDesc('id')->first()??new ProgressReviewDraft(['apprentice_id'=>$a->id,'fields'=>[],'source_snapshot'=>[]]);
  $source=ReviewBriefs::source($a,$draft,$from,$to);$suggested=ReviewBriefs::suggest($source);
  return ['identity'=>$a->only('id','reference','first_name','last_name'),'from'=>$from,'to'=>$to,'captured_at'=>now()->toIso8601String(),'source_hash'=>ReviewedImports::hash($source),'notes'=>$suggested,'draft_id'=>$draft->exists?$draft->id:null,'position'=>self::position($u,$a),'private_support_included'=>false,'writes_performed'=>false,'actual_meeting_recorded'=>false];
 }
}
