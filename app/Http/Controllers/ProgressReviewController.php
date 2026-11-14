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

        $input=$r->validate(['search'=>['nullable','string','max:250'],'view'=>['nullable','string',Rule::in(\App\Support\ReviewFollowup::VIEWS)],'group'=>['nullable','integer','min:1'],'apprentice'=>['nullable','integer','min:1']]);
        if($r->filled('group')){$q->where('tutor_group_id',(int)$input['group']);}
        if($r->filled('apprentice')){abort_unless((clone $q)->whereKey((int)$input['apprentice'])->exists(),404);$q->whereKey((int)$input['apprentice']);}
        $search=trim($input['search']??'');
        if($search!==''){$q->where(fn($n)=>$n->where('reference','like','%'.$search.'%')->orWhere('first_name','like','%'.$search.'%')->orWhere('last_name','like','%'.$search.'%'));}
        $people=$q->orderBy('last_name')->orderBy('id')->get();
        $followup=\App\Support\ReviewFollowup::for($r->user(),$people,true);$view=$input['view']??'all';$filtered=\App\Support\ReviewFollowup::filter($followup['rows'],$view)->values();
        $page=\Illuminate\Pagination\Paginator::resolveCurrentPage();$page=min($page,max(1,(int)ceil($filtered->count()/25)));
        $reviewRows=new \Illuminate\Pagination\LengthAwarePaginator($filtered->forPage($page,25)->values(),$filtered->count(),25,$page,['path'=>$r->url(),'query'=>$r->except('page')]);
        $apprentices=$reviewRows->getCollection()->pluck('apprentice');$positions=$followup['positions'];
        return view('reviews.index', ['apprentices'=>$apprentices,'positions'=>$positions,'reviewRows'=>$reviewRows,'reviewCounts'=>$followup['counts'],'reviewView'=>$view,'reviewSearch'=>$search,'manager'=>Access::manager($r->user()),'reminderPreview'=>Access::manager($r->user()) ? \App\Support\ReviewReminders::generate() : null,'reminderScheduleEnabled'=>(bool)config('portal_reminders.scheduled',false),'lastReminderRun'=>Access::manager($r->user()) ? DB::table('portal_reminder_runs')->latest('id')->first() : null]);
    }

    public function form(Request $r, Apprentice $apprentice, ?ProgressReviewDraft $review = null): View
    {
        abort_unless($this->allowed($r, $apprentice), 403);
        if ($review) {
            abort_unless($review->apprentice_id === $apprentice->id, 404);
        }

        $signedRecords=$review ? \App\Models\SignedReviewRecord::where('progress_review_draft_id',$review->id)->whereNull('withdrawn_at')->get() : collect();
        $signedConfirmed=$signedRecords->contains(fn($r)=>$r->checked_at && \App\Support\SignedReviews::intact($r));

        return view('reviews.form', ['preparation'=>($review?->exists&&$review->state==='Draft')?\App\Support\ReviewPreparation::inspect($review->fields??[],$review->source_snapshot??[],DeliveryPlan::approved($apprentice)):null,'signedConfirmed'=>$signedConfirmed,'hasSignedSource'=>$signedRecords->isNotEmpty(),'apprentice' => $apprentice, 'review' => $review ?? new ProgressReviewDraft, 'snapshot' => $review?->source_snapshot ?? $this->snapshot($apprentice, []), 'actions' => SmartAction::with('task')->where('apprentice_id', $apprentice->id)->get(), 'existing' => ProgressReviewDraft::where('apprentice_id', $apprentice->id)->orderByDesc('updated_at')->get()]);
    }

    public function pdf(Request $r, Apprentice $apprentice, ProgressReviewDraft $review): Response
    {
        abort_unless($this->allowed($r, $apprentice), 403);
        abort_unless($review->apprentice_id === $apprentice->id, 404);

        return PortalPdf::response('pdf.review', compact('review', 'apprentice'), ($review->state==='Ready for signatures'?'GTA-Review-For-Signing-':'GTA-Review-DRAFT-').$apprentice->reference.'-v'.$review->version);
    }

    public function save(Request $r, Apprentice $apprentice, ?ProgressReviewDraft $review = null): RedirectResponse
    {
        abort_unless($this->allowed($r, $apprentice), 403);
        if ($review) {
            abort_unless($review->apprentice_id === $apprentice->id, 404);
        }
        $rules = ['version' => $review ? ['required', 'integer'] : ['prohibited'], 'fields' => ['required', 'array']];
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
        $data = $r->validate($rules);
        $keys = array_map(fn ($key) => substr($key, 7), array_filter(array_keys($rules), fn ($key) => str_starts_with($key, 'fields.')));
        $fields = collect($data['fields'])->only($keys)->all();
        if (filled($fields['trainingFrom'] ?? null) && filled($fields['reviewDate'] ?? null) && $fields['trainingFrom'] > $fields['reviewDate']) {
            throw ValidationException::withMessages(['fields.trainingFrom' => 'The training period cannot start after the actual meeting date.']);
        }
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
            }$history = $row->history ?? [];
            if ($row->exists) {
                $history[] = ['fields' => $row->fields, 'source_snapshot' => $row->source_snapshot, 'saved_by' => $r->user()->id, 'saved_at' => now()->toIso8601String()];
                $row->version++;
            }$row->fields = $fields;
            $row->source_snapshot = $this->snapshot($fresh, $fields);
            $row->history = $history;
            $row->save();
            DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $apprentice->id, 'kind' => 'review_draft', 'record_id' => $row->id, 'event' => 'draft_saved', 'created_at' => now()]);

            return $row;
        });

        return to_route('reviews.edit', [$apprentice, $saved])->with('status', 'Review draft saved privately. It is not completed, signed or shared, and does not reset the review cycle.');
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
            if (\App\Models\SignedReviewRecord::where('progress_review_draft_id',$row->id)->whereNull('withdrawn_at')->exists()) { throw ValidationException::withMessages(['version'=>'Withdraw the current signed-copy evidence/confirmation before reopening this frozen review.']); }
            if ($row->version !== $r->integer('version') || $row->state !== 'Ready for signatures') { throw ValidationException::withMessages(['version' => 'Only the current frozen revision can be reopened.']); }
            $row->history = [...($row->history ?? []), ['event' => 'reopened', 'reason' => $r->input('reason'), 'fields' => $row->fields, 'source_snapshot' => $row->source_snapshot, 'saved_by' => $r->user()->id, 'saved_at' => now()->toIso8601String()]];
            $row->state = 'Draft'; $row->version++; $row->save();
            DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $apprentice->id, 'kind' => 'review_draft', 'record_id' => $row->id, 'event' => 'review_reopened', 'created_at' => now()]);
        });

        return back()->with('status', 'Review reopened as a draft. The frozen revision and reason are retained.');
    }

    private function snapshot(Apprentice $a, array $fields): array
    {
        $plan = DeliveryPlan::approved($a);
        $tasks = LearningTask::where('apprentice_id', $a->id)->get();
        $topics = TrainingTopic::where('apprentice_id', $a->id)->get();
        $qualifications = Qualification::where('apprentice_id', $a->id)->get();
        $delivered = [];
        $planned = [];
        if (filled($fields['reviewDate'] ?? null)) {
            $end = Carbon::parse($fields['reviewDate'])->addDays(70)->toDateString();
            $sessions = TrainingSession::with(['marks' => fn ($q) => $q->where('apprentice_id', $a->id)])->whereJsonContains('members', $a->id)->where('state', '!=', 'Cancelled')->orderBy('session_date')->get();
            foreach ($sessions as $s) {
                $date = $s->session_date->toDateString();
                $row = ['date' => $date, 'name' => $s->name, 'subjects' => $s->delivered_learning];
                if (filled($fields['trainingFrom'] ?? null) && $date >= $fields['trainingFrom'] && $date <= $fields['reviewDate'] && $s->ended() && in_array($s->marks->first()?->code, ['O', 'T'], true)) {
                    $delivered[] = $row;
                }if ($date > $fields['reviewDate'] && $date <= $end) {
                    $planned[] = ['date' => $date, 'name' => $s->name, 'subjects' => $s->planned_learning];
                }
            }
        }

        return ['captured_at' => now()->toIso8601String(), 'otj_position' => \App\Support\OtjPosition::forApprentice($a)['summary'], 'scope_note' => 'Current source records captured when this draft was saved; not a reconstructed historical whole-plan assessment.', 'whole_plan' => $plan ? 'Provider delivery plan linked; participant signatures and formal KSB achievement remain separate' : 'Not linked — individual plan applicability and KSB/block coverage cannot be confirmed', 'delivery_plan' => $plan ? ['hash' => $plan['hash'], 'approved_at' => $plan['approved_at'], 'source_ref' => $plan['details']['source_ref'], 'target_hours' => $plan['details']['target_hours'], 'blocks' => $plan['blocks'], 'next_period_blocks' => filled($fields['reviewDate'] ?? null) ? array_values(array_filter($plan['blocks'], fn ($b) => $b['end'] > $fields['reviewDate'] && $b['start'] <= Carbon::parse($fields['reviewDate'])->addDays(70)->toDateString())) : []] : null, 'approved_tasks' => $tasks->where('status', 'Approved')->count(), 'assigned_tasks' => $tasks->count(), 'blocks' => $tasks->groupBy('block')->map(fn ($rows) => ['title' => $rows->first()->theme, 'approved' => $rows->where('status', 'Approved')->count(), 'assigned' => $rows->count()])->all(), 'topics' => $topics->map(fn ($t) => $t->only('subject', 'level', 'topic', 'planned_hours', 'status', 'completion_date'))->all(), 'qualifications' => $qualifications->map(fn ($q) => [...$q->only('subject', 'title', 'level', 'status', 'result'), 'target' => $q->target()?->toDateString()])->all(), 'delivered' => $delivered, 'planned' => $planned, 'forecast_complete' => false, 'monthly_snapshots' => MonthlyScorecard::where('apprentice_id', $a->id)->when(filled($fields['trainingFrom'] ?? null), fn ($q) => $q->where('month', '>=', substr($fields['trainingFrom'], 0, 7)))->when(filled($fields['reviewDate'] ?? null), fn ($q) => $q->where('month', '<=', substr($fields['reviewDate'], 0, 7)))->get(['month', 'scores', 'comment'])->toArray(),
            'action_plan' => SmartAction::with('task')->where('apprentice_id', $a->id)->get()->map(fn ($action) => ['id' => $action->id, 'title' => $action->title, 'owner' => $action->owner, 'status' => $action->currentStatus(), 'due_date' => $action->deadline()?->toDateString(), 'completion_date' => $action->completion_date?->toDateString(), 'task_id' => $action->learning_task_id])->all(),
            'known_next_period_tasks' => filled($fields['reviewDate'] ?? null) ? $tasks->filter(fn ($t) => $t->status !== 'Approved' && $t->due_date && $t->due_date->toDateString() >= $fields['reviewDate'] && $t->due_date->toDateString() <= $end)->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'block' => $t->block, 'due_date' => $t->due_date->toDateString(), 'status' => $t->status])->values()->all() : [],
            'training_plan_source' => $plan ? $plan['details']['source_ref'] : 'Reference remains the supplied scheme of work; no substitute individual plan created'];
    }
}
