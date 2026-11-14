<?php
namespace App\Http\Controllers;

use App\Models\ProgressReviewDraft;
use App\Models\SignedReviewRecord;
use App\Support\LearningAccess;
use App\Support\PrivateEvidence;
use App\Support\SignedReviews;
use Illuminate\Http\Request;

class SignedReviewController extends Controller
{
    public function form(Request $r,ProgressReviewDraft $review)
    {
        $a=$review->apprentice;abort_unless(SignedReviews::allowed($r->user(),$a),403);
        return view('reviews.signed',['review'=>$review,'apprentice'=>$a,'records'=>SignedReviewRecord::where('progress_review_draft_id',$review->id)->orderByDesc('id')->get(),'manager'=>LearningAccess::manager($r->user()),'cycle'=>SignedReviews::cycle($a)]);
    }
    public function upload(Request $r,ProgressReviewDraft $review)
    {
        abort_unless(SignedReviews::allowed($r->user(),$review->apprentice),403);
        $rules=['version'=>['required','integer'],'file'=>['required','file','mimes:pdf','extensions:pdf','max:15360'],'source_reference'=>['required','string','max:2000'],'identity_reference'=>['required','string','max:2000'],'copy_checked'=>['accepted']];
        foreach(['apprentice','employer','mentor'] as $role){$rules[$role.'_name']=['required','string','max:300'];$rules[$role.'_date']=['required','date_format:Y-m-d','before_or_equal:today'];}
        $data=$r->validate($rules);SignedReviews::prepare($review,$r->user(),$r->file('file'),$data);
        return back()->with('status','Signed-copy evidence saved privately for independent checking. It has not changed the tracked review date.');
    }
    public function confirm(Request $r,SignedReviewRecord $record)
    {
        abort_unless(LearningAccess::manager($r->user()),403);
        $r->validate(['version'=>['required','integer'],'content_checked'=>['accepted'],'identity_checked'=>['accepted'],'signatures_checked'=>['accepted']]);
        SignedReviews::confirm($record,$r->user(),$r->integer('version'));
        return back()->with('status','Externally signed review copy independently checked. The tracked next review is seventy days after the actual meeting date.');
    }
    public function withdraw(Request $r,SignedReviewRecord $record)
    {
        abort_unless(LearningAccess::manager($r->user()),403);
        $data=$r->validate(['version'=>['required','integer'],'reason'=>['required','string','max:2000']]);SignedReviews::withdraw($record,$r->user(),$data['version'],$data['reason']);
        return back()->with('status','Signed-copy confirmation/evidence withdrawn. File and history remain; the tracked reference is recalculated from remaining checked meetings.');
    }
    public function download(Request $r,SignedReviewRecord $record)
    {
        $a=\App\Models\Apprentice::findOrFail($record->apprentice_id);abort_unless(SignedReviews::allowed($r->user(),$a),403);
        abort_unless(SignedReviews::intact($record),409);abort_unless(PrivateEvidence::exists($record->file),404);abort_unless(PrivateEvidence::matchesHash($record->file),409);
        return PrivateEvidence::download($record->file);
    }
}
