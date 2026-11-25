<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\MonthlyScorecard;
use App\Models\ProgressReviewDraft;
use App\Models\Qualification;
use App\Models\SmartAction;
use App\Models\TrainingSession;
use App\Models\TrainingTopic;
use App\Support\LearningAccess as Access;
use App\Support\PortalPdf;
use App\Support\DeliveryPlan;
use App\Support\ReviewTriage;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProgressReviewController extends Controller
{
    private function allowed(Request $r, Apprentice $a): bool
    {
        return Access::manager($r->user()) || Access::tutor($r->user(), $a) || \App\Support\StaffDuties::assigned($r->user(), $a, 'mentor');
    }

    public function index(Request $r): View
    {
        abort_unless(\App\Support\ReviewReminders::allowed($r->user()), 403);
        $q = Apprentice::with(['mentor', 'tutor']);
        if (! Access::manager($r->user())) {
            $q->where(function($q) use($r) {
                if($r->user()->role==='tutor'){$q->where('tutor_id',$r->user()->id);}
                if(\App\Support\StaffDuties::has($r->user(),'mentor')){
                    $r->user()->role==='tutor' ? $q->orWhere('mentor_id',$r->user()->id) : $q->where('mentor_id',$r->user()->id);
                }
            });
        }

        $input=$r->validate(['search'=>['nullable','string','max:250'],'view'=>['nullable','string',Rule::in([...\App\Support\ReviewFollowup::VIEWS, ...ReviewTriage::VIEWS, 'preparation'])],'group'=>['nullable','integer','min:1'],'apprentice'=>['nullable','integer','min:1'],'page'=>['nullable','integer','min:1','max:1000000']]);
        if($r->filled('group')){$q->where('tutor_group_id',(int)$input['group']);}
        if($r->filled('apprentice')){abort_unless((clone $q)->whereKey((int)$input['apprentice'])->exists(),404);$q->whereKey((int)$input['apprentice']);}
        $search=trim($input['search']??'');
        if($search!==''){$q->where(fn($n)=>$n->where('reference','like','%'.$search.'%')->orWhere('first_name','like','%'.$search.'%')->orWhere('last_name','like','%'.$search.'%'));}
        $preparationView = ($input['view'] ?? null) === 'preparation';
        if ($preparationView) {
            $actor = \App\Support\PortalActions::actor($r->user());
        }
        $people=$q->orderBy('last_name')->orderBy('id')->get();
        $followup = \App\Support\ReviewFollowup::for($r->user(), $people, true);
        $view = $input['view'] ?? 'all';
        $triage = ReviewTriage::for($followup['rows'], $followup['positions']);
        $triageCounts = [];
        foreach (ReviewTriage::VIEWS as $key) {
            $triageCounts[$key] = ReviewTriage::filter($triage, $key)->count();
        }
        $triageView = in_array($view, ReviewTriage::VIEWS, true);
        $filtered = $triageView
            ? ReviewTriage::filter($triage, $view)
            : \App\Support\ReviewFollowup::filter($followup['rows'], $preparationView ? 'all' : $view)->values();
        $pageSize = $preparationView ? 20 : 25;
        $page=\Illuminate\Pagination\Paginator::resolveCurrentPage();$page=min($page,max(1,(int)ceil($filtered->count()/$pageSize)));
        $reviewRows=new \Illuminate\Pagination\LengthAwarePaginator($filtered->forPage($page,$pageSize)->values(),$filtered->count(),$pageSize,$page,['path'=>$r->url(),'query'=>$r->except('page')]);
        $apprentices=$reviewRows->getCollection()->pluck('apprentice');$positions=$followup['positions'];
        $preparation = $preparationView ? $apprentices->mapWithKeys(fn ($person) =>
            [$person->id => \App\Support\ReviewWorkspace::position($actor, $person)]
        ) : collect();
        return view('reviews.index', ['preparation' => $preparation, 'preparationView' => $preparationView, 'apprentices'=>$apprentices,'positions'=>$positions,'triageCounts'=>$triageCounts,'triageView'=>$triageView,'reviewRows'=>$reviewRows,'reviewCounts'=>$followup['counts'],'reviewView'=>$view,'reviewSearch'=>$search,'manager'=>Access::manager($r->user()),'reminderPreview'=>Access::manager($r->user()) ? \App\Support\ReviewReminders::generate() : null,'reminderScheduleEnabled'=>(bool)config('portal_reminders.scheduled',false),'lastReminderRun'=>Access::manager($r->user()) ? DB::table('portal_reminder_runs')->latest('id')->first() : null]);
    }

    public function form(Request $r, Apprentice $apprentice, ?ProgressReviewDraft $review = null): View
    {
        abort_unless($this->allowed($r, $apprentice), 403);
        if ($review) {
            abort_unless($review->apprentice_id === $apprentice->id, 404);
        }

        $signedRecords=$review ? \App\Models\SignedReviewRecord::where('progress_review_draft_id',$review->id)->whereNull('withdrawn_at')->get() : collect();
        $signedConfirmed=$signedRecords->contains(fn($r)=>$r->checked_at && \App\Support\SignedReviews::intact($r));

        $simple83=!$review?->exists || $review->state==='Draft' || ($review->fields['format83']??null)==='simple83';
        $defaults = \App\Support\ReviewAutomation::defaults($apprentice);
        $snapshot = $review?->source_snapshot ?? \App\Support\ReviewSnapshot::capture($apprentice,
            \Illuminate\Support\Arr::only($defaults, ['trainingFrom']));
        $recordedFacts = !$review?->exists ? \App\Support\ReviewFacts83::fill([], $snapshot) : [];
        return view($simple83?'reviews.simple83':'reviews.form', ['recordedFacts' => $recordedFacts, 'automatedDefaults'=>$defaults,'preparation'=>($review?->exists&&$review->state==='Draft')?\App\Support\ReviewPreparation::inspect([...($review->fields??[]),'format83'=>'simple83'],$review->source_snapshot??[],DeliveryPlan::approved($apprentice)):null,'signedConfirmed'=>$signedConfirmed,'hasSignedSource'=>$signedRecords->isNotEmpty(),'apprentice' => $apprentice, 'review' => $review ?? new ProgressReviewDraft, 'snapshot' => $snapshot, 'actions' => SmartAction::with('task')->where('apprentice_id', $apprentice->id)->get(), 'existing' => ProgressReviewDraft::where('apprentice_id', $apprentice->id)->orderByDesc('updated_at')->get()]);
    }

    public function pdf(Request $r, Apprentice $apprentice, ProgressReviewDraft $review): Response
    {
        abort_unless($this->allowed($r, $apprentice), 403);
        abort_unless($review->apprentice_id === $apprentice->id, 404);

        return PortalPdf::response(($review->fields['format83']??null)==='simple83'?'pdf.review-simple83':'pdf.review', compact('review', 'apprentice'), ($review->state==='Ready for signatures'?'GTA-Review-For-Signing-':'GTA-Review-DRAFT-').$apprentice->reference.'-v'.$review->version);
    }

    public function save(Request $r, Apprentice $apprentice, ?ProgressReviewDraft $review = null): RedirectResponse
    {
        abort_unless($this->allowed($r, $apprentice), 403);
        if ($review) {
            abort_unless($review->apprentice_id === $apprentice->id, 404);
        }
        $simple83=$r->input('fields.format83')==='simple83';
        $continueToSignoff = $simple83 && $r->input('after_save') === 'signoff';
        if ($continueToSignoff) {
            $r->merge(['after_save' => 'prepare']);
        }
        if ($simple83 && $r->input('after_save')==='prepare') $r->merge(['prepare_confirmed'=>$r->input('fields.checked83',false)]);
        $rules = ['fields.format83'=>['nullable','in:simple83'],'fields.checked83'=>['sometimes','boolean'],'prepare_plan_hash'=>['exclude_unless:after_save,prepare','required','string','size:64'],'after_save'=>['nullable','in:stay,prepare'],'prepare_confirmed'=>['exclude_unless:after_save,prepare','required','accepted'],'use_recorded_facts'=>['sometimes','boolean'],'version' => $review ? ['required', 'integer'] : ['prohibited'], 'fields' => ['required', 'array']];
        foreach (['reviewDate', 'trainingFrom'] as $key) {
            $rules['fields.'.$key] = ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'];
        }
        foreach (['mentor', 'tutor', 'employerRep', 'absence', 'learning', 'progressDiscussion', 'previousNotes', 'planChangeNotes', 'sharedSupport', 'summary'] as $key) {
            $rules['fields.'.$key] = ['nullable', 'string', 'max:20000'];
        }
        foreach (['method' => ['Face to face', 'Video meeting', 'Telephone', 'Email'], 'attendance' => ['Present', 'Absent — opportunity to contribute recorded'], 'judgement' => ['On track', 'Behind plan', 'Unable to confirm'], 'previousCheck' => ['Existing action plan checked', 'First review — no previous review actions', 'Unable to check'], 'planChange' => ['No', 'Yes'], 'newNeeds' => ['No', 'Yes — follow up in restricted support record']] as $key => $values) {
            $rules['fields.'.$key] = ['nullable', Rule::in($values)];
        }
        foreach (['trainingChecked', 'shareSupport', 'targetsAgreed', 'providerPrepared'] as $key) {
            $rules['fields.'.$key] = ['sometimes', 'boolean'];
        }
        $rules['fields.safetyAnswer'] = ['nullable', Rule::in(['yes','no'])];
        $rules['fields.safetyFollowup'] = ['nullable', 'string', 'max:250'];
        $data = $r->validate($rules);
        $keys = array_map(fn ($key) => substr($key, 7), array_filter(array_keys($rules), fn ($key) => str_starts_with($key, 'fields.')));
        $fields = collect($data['fields'])->only($keys)->all();
        if (filled($fields['trainingFrom'] ?? null) && filled($fields['reviewDate'] ?? null) && $fields['trainingFrom'] > $fields['reviewDate']) {
            throw ValidationException::withMessages(['fields.trainingFrom' => 'The training period cannot start after the actual meeting date.']);
        }
        if (($fields['safetyAnswer'] ?? null) !== 'yes') {
            unset($fields['safetyFollowup']);
        }
        if($simple83){$checked=$r->boolean('fields.checked83');foreach(['trainingChecked','targetsAgreed','providerPrepared'] as $key)$fields[$key]=$checked;$fields['checked83']=$checked;}
        $fields['nextReview'] = filled($fields['reviewDate'] ?? null) ? Carbon::parse($fields['reviewDate'])->addDays(70)->toDateString() : null;
        $saved = DB::transaction(function () use ($r, $apprentice, $review, $fields) {
            $fresh = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->allowed($r, $fresh), 403);
            if ($fresh->programme_id) { $fresh->programmeRecord()->lockForUpdate()->first(); }
            \App\Models\IndividualDeliveryPlan::where('apprentice_id', $fresh->id)->lockForUpdate()->first();
            $row = $review ? ProgressReviewDraft::whereKey($review->id)->lockForUpdate()->firstOrFail() : new ProgressReviewDraft(['apprentice_id' => $apprentice->id, 'author_id' => $r->user()->id]);
            if ($row->exists && $row->state !== 'Draft') {
                throw ValidationException::withMessages(['version' => 'This revision is frozen for signatures. Reopen it with a reason before making changes.']);
            }
            if ($row->exists && $row->version !== $r->integer('version')) {
                throw ValidationException::withMessages(['version' => 'This review draft changed. Reopen before saving.']);
            }$previousPlanHash83=$row->source_snapshot['delivery_plan']['hash']??null;$history = $row->history ?? [];
            if ($row->exists) {
                $history[] = ['fields' => $row->fields, 'source_snapshot' => $row->source_snapshot, 'saved_by' => $r->user()->id, 'saved_at' => now()->toIso8601String()];
                $row->version++;
            }
            if (($fields['format83']??null)==='simple83') {
                // Omitted optional legacy notes stay in the same record; explicit edits win.
                $fields=array_replace($row->fields??[],$fields);
                if(filled($fields['trainingFrom']??null)&&filled($fields['reviewDate']??null)&&$fields['trainingFrom']>$fields['reviewDate'])throw ValidationException::withMessages(['fields.trainingFrom'=>'The training period cannot start after the actual meeting date.']);
                $fields['nextReview']=filled($fields['reviewDate']??null)?Carbon::parse($fields['reviewDate'])->addDays(70)->toDateString():null;
                if (($fields['safetyAnswer']??null)!=='yes') unset($fields['safetyFollowup']);
            }
            $row->fields = $fields;
            $row->source_snapshot = \App\Support\ReviewSnapshot::capture($fresh, $fields);
            if ($r->boolean('use_recorded_facts')) $row->fields = \App\Support\ReviewFacts83::fill($fields,$row->source_snapshot);
            if ($r->input('after_save')==='prepare') {
                $currentPlan=DeliveryPlan::approved($fresh);
                if (!$currentPlan || !hash_equals($currentPlan['hash'],(string)$r->input('prepare_plan_hash')) || ($review && $previousPlanHash83!==$currentPlan['hash'])) {
                    throw ValidationException::withMessages(['prepare_confirmed'=>'The linked training plan changed or is unavailable. Save the draft, check the refreshed plan and prepare signatures again.']);
                }
                \App\Support\ReviewPreparation::requireReady($row->fields??[],$row->source_snapshot??[],$currentPlan);
                $history[]=['event'=>'ready_for_signatures','fields'=>$row->fields,'source_snapshot'=>$row->source_snapshot,'saved_by'=>$r->user()->id,'saved_at'=>now()->toIso8601String()];
                $row->state='Ready for signatures';$row->version++;
            }
            $row->history = $history;
            $row->save();
            DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $apprentice->id, 'kind' => 'review_draft', 'record_id' => $row->id, 'event' => 'draft_saved', 'created_at' => now()]);

            if ($r->input('after_save')==='prepare') DB::table('learning_events')->insert(['user_id'=>$r->user()->id,'apprentice_id'=>$fresh->id,'kind'=>'review_draft','record_id'=>$row->id,'event'=>'ready_for_signatures','created_at'=>now()]);
            return $row;
        });

        if ($continueToSignoff) {
            return to_route('electronic-reviews.prepare-form', $saved)
                ->with('status', 'Review saved. Choose how to prepare it for signing.');
        }

        return to_route('reviews.edit', [$apprentice, $saved])->with('status', $saved->state==='Ready for signatures'?'Review saved and prepared for participant signatures. No signature or sharing has been recorded.':'Review draft saved. Check the saved notes before preparing signatures.');
    }

    public function ready(Request $r, Apprentice $apprentice, ProgressReviewDraft $review): RedirectResponse
    {
        abort_unless($this->allowed($r, $apprentice), 403);
        abort_unless($review->apprentice_id === $apprentice->id, 404);
        $r->validate(['version' => ['required', 'integer'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $apprentice, $review): void {
            $a = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->allowed($r, $a), 403);
            if ($a->programme_id) { $a->programmeRecord()->lockForUpdate()->first(); }
            \App\Models\IndividualDeliveryPlan::where('apprentice_id', $a->id)->lockForUpdate()->first();
            $row = ProgressReviewDraft::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($row->version !== $r->integer('version') || $row->state !== 'Draft') { throw ValidationException::withMessages(['version' => 'This review changed or is already frozen. Reopen the current revision.']); }
            $plan = DeliveryPlan::approved($a);
            \App\Support\ReviewPreparation::requireReady($row->fields??[],$row->source_snapshot??[],$plan);
            $row->history = [...($row->history ?? []), ['event' => 'ready_for_signatures', 'fields' => $row->fields, 'source_snapshot' => $row->source_snapshot, 'saved_by' => $r->user()->id, 'saved_at' => now()->toIso8601String()]];
            $row->state = 'Ready for signatures'; $row->version++; $row->save();
            DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $a->id, 'kind' => 'review_draft', 'record_id' => $row->id, 'event' => 'ready_for_signatures', 'created_at' => now()]);
        });

        return back()->with('status', 'This review revision is frozen and ready for participant signatures. It is not completed or shared and does not reset the review deadline.');
    }

    public function reopen(Request $r, Apprentice $apprentice, ProgressReviewDraft $review): RedirectResponse
    {
        abort_unless($this->allowed($r, $apprentice), 403);
        abort_unless($review->apprentice_id === $apprentice->id, 404);
        $r->validate(['version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($r, $apprentice, $review): void {
            $fresh = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->allowed($r, $fresh), 403);
            $row = ProgressReviewDraft::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if(\App\Models\ElectronicReviewPacket::where('active_review_id',$row->id)->exists()){throw ValidationException::withMessages(['version'=>'Withdraw the active participant signing record before reopening this review.']);}
            if (\App\Models\SignedReviewRecord::where('progress_review_draft_id',$row->id)->whereNull('withdrawn_at')->exists()) { throw ValidationException::withMessages(['version'=>'Withdraw the current signed-copy evidence/confirmation before reopening this frozen review.']); }
            if ($row->version !== $r->integer('version') || $row->state !== 'Ready for signatures') { throw ValidationException::withMessages(['version' => 'Only the current frozen revision can be reopened.']); }
            $row->history = [...($row->history ?? []), ['event' => 'reopened', 'reason' => $r->input('reason'), 'fields' => $row->fields, 'source_snapshot' => $row->source_snapshot, 'saved_by' => $r->user()->id, 'saved_at' => now()->toIso8601String()]];
            $row->state = 'Draft'; $row->version++; $row->save();
            DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $apprentice->id, 'kind' => 'review_draft', 'record_id' => $row->id, 'event' => 'review_reopened', 'created_at' => now()]);
        });

        return back()->with('status', 'Review reopened as a draft. The frozen revision and reason are retained.');
    }

}
