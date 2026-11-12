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
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProgressReviewController extends Controller
{
    private function allowed(Request $r, Apprentice $a): bool
    {
        return Access::manager($r->user()) || Access::tutor($r->user(), $a) || ($r->user()->role === 'mentor' && $a->mentor_id === $r->user()->id);
    }

    public function index(Request $r): View
    {
        abort_unless(in_array($r->user()->role, ['admin', 'ceo', 'tutor', 'mentor'], true), 403);
        $q = Apprentice::with(['mentor', 'tutor']);
        if (! Access::manager($r->user())) {
            $q->where($r->user()->role === 'mentor' ? 'mentor_id' : 'tutor_id', $r->user()->id);
        }

        return view('reviews.index', ['apprentices' => $q->orderBy('last_name')->get()]);
    }

    public function form(Request $r, Apprentice $apprentice, ?ProgressReviewDraft $review = null): View
    {
        abort_unless($this->allowed($r, $apprentice), 403);
        if ($review) {
            abort_unless($review->apprentice_id === $apprentice->id, 404);
        }

        return view('reviews.form', ['apprentice' => $apprentice, 'review' => $review ?? new ProgressReviewDraft, 'snapshot' => $review?->source_snapshot ?? $this->snapshot($apprentice, []), 'actions' => SmartAction::with('task')->where('apprentice_id', $apprentice->id)->get(), 'existing' => ProgressReviewDraft::where('apprentice_id', $apprentice->id)->orderByDesc('updated_at')->get()]);
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
            $row = $review ? ProgressReviewDraft::whereKey($review->id)->lockForUpdate()->firstOrFail() : new ProgressReviewDraft(['apprentice_id' => $apprentice->id, 'author_id' => $r->user()->id]);
            if ($row->exists && $row->version !== $r->integer('version')) {
                throw ValidationException::withMessages(['version' => 'This review draft changed. Reopen before saving.']);
            }$history = $row->history ?? [];
            if ($row->exists) {
                $history[] = ['fields' => $row->fields, 'source_snapshot' => $row->source_snapshot, 'saved_by' => $r->user()->id, 'saved_at' => now()->toIso8601String()];
                $row->version++;
            }$row->fields = $fields;
            $row->source_snapshot = $this->snapshot($apprentice, $fields);
            $row->history = $history;
            $row->save();
            DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $apprentice->id, 'kind' => 'review_draft', 'record_id' => $row->id, 'event' => 'draft_saved', 'created_at' => now()]);

            return $row;
        });

        return to_route('reviews.edit', [$apprentice, $saved])->with('status', 'Review draft saved privately. It is not completed, signed or shared, and does not reset the review cycle.');
    }

    private function snapshot(Apprentice $a, array $fields): array
    {
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

        return ['captured_at' => now()->toIso8601String(), 'scope_note' => 'Current source records captured when this draft was saved; not a reconstructed historical whole-plan assessment.', 'whole_plan' => 'Not linked — individual plan applicability and KSB/block coverage cannot be confirmed', 'approved_tasks' => $tasks->where('status', 'Approved')->count(), 'assigned_tasks' => $tasks->count(), 'blocks' => $tasks->groupBy('block')->map(fn ($rows) => ['title' => $rows->first()->theme, 'approved' => $rows->where('status', 'Approved')->count(), 'assigned' => $rows->count()])->all(), 'topics' => $topics->map(fn ($t) => $t->only('subject', 'level', 'topic', 'planned_hours', 'status', 'completion_date'))->all(), 'qualifications' => $qualifications->map(fn ($q) => [...$q->only('subject', 'title', 'level', 'status', 'result'), 'target' => $q->target()?->toDateString()])->all(), 'delivered' => $delivered, 'planned' => $planned, 'forecast_complete' => false, 'monthly_snapshots' => MonthlyScorecard::where('apprentice_id', $a->id)->when(filled($fields['trainingFrom'] ?? null), fn ($q) => $q->where('month', '>=', substr($fields['trainingFrom'], 0, 7)))->when(filled($fields['reviewDate'] ?? null), fn ($q) => $q->where('month', '<=', substr($fields['reviewDate'], 0, 7)))->get(['month', 'scores', 'comment'])->toArray(),
            'training_plan_source' => 'Reference remains the supplied scheme of work; no substitute individual plan created'];
    }
}
