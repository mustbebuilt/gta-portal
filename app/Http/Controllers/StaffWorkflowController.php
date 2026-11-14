<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\ProgressReviewDraft;
use App\Models\ReviewPreparationBrief;
use App\Support\ReviewBriefs;
use App\Support\ReviewedImports;
use App\Support\StaffAttention;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StaffWorkflowController extends Controller
{
    public function attention(Request $r)
    {
        abort_unless(StaffAttention::allowed($r->user()), 403);
        $data = $r->validate(['view' => ['nullable', Rule::in(StaffAttention::VIEWS)], 'reason' => ['nullable', Rule::in(StaffAttention::REASONS)], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $view = $data['view'] ?? 'all';
        $reason = $data['reason'] ?? 'all';
        $q = trim($data['q'] ?? '');
        $position = StaffAttention::for($r->user());
        $rows = $position['rows']->map(function ($row) use ($view, $reason) {
            $row['reasons'] = array_values(array_filter($row['reasons'], fn ($x) => ($view === 'all' || $x['bucket'] === $view) && ($reason === 'all' || $x['kind'] === $reason)));

            return $row;
        })->filter(fn ($row) => $row['reasons'] && ($q === '' || mb_stripos($row['apprentice']->first_name.' '.$row['apprentice']->last_name.' '.$row['apprentice']->reference, $q) !== false))->sort(fn ($x, $y) => [StaffAttention::RANK[$x['reasons'][0]['bucket']], $x['reasons'][0]['date'] ?? '9999', $x['apprentice']->reference, $x['apprentice']->id] <=> [StaffAttention::RANK[$y['reasons'][0]['bucket']], $y['reasons'][0]['date'] ?? '9999', $y['apprentice']->reference, $y['apprentice']->id])->values();
        $page = min((int) ($data['page'] ?? 1), max(1, (int) ceil($rows->count() / 15)));
        $list = new LengthAwarePaginator($rows->forPage($page, 15)->values(), $rows->count(), 15, $page, ['path' => $r->url(), 'query' => $r->except('page')]);

        return response()->view('staff-workflow.attention', ['position' => $position, 'rows' => $list, 'view' => $view, 'reason' => $reason, 'q' => $q])->header('Cache-Control', 'no-store, private');
    }

    private function check(Request $r, Apprentice $a, ProgressReviewDraft $review): void
    {
        abort_unless($review->apprentice_id === $a->id, 404);
        abort_unless(ReviewBriefs::allowed($r->user(), $a), 403);
    }

    public function briefing(Request $r, Apprentice $apprentice, ProgressReviewDraft $review, ?ReviewPreparationBrief $brief = null)
    {
        $this->check($r, $apprentice, $review);
        if ($brief) {
            abort_unless($brief->apprentice_id === $apprentice->id && $brief->progress_review_draft_id === $review->id, 404);
        }
        $intact = $brief ? ReviewBriefs::intact($brief) : true;
        $current = $brief && $intact && ! $brief->applied_at ? ReviewedImports::hash(ReviewBriefs::source($apprentice, $review, $brief->period_from->toDateString(), $brief->period_to->toDateString())) === $brief->source_hash : false;

        return response()->view('staff-workflow.briefing', ['apprentice' => $apprentice, 'review' => $review, 'brief' => $brief, 'intact' => $intact, 'current' => $current, 'previous' => ReviewPreparationBrief::where('progress_review_draft_id', $review->id)->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get(['id', 'period_from', 'period_to', 'applied_at', 'created_at'])])->header('Cache-Control', 'no-store, private');
    }

    public function prepare(Request $r, Apprentice $apprentice, ProgressReviewDraft $review)
    {
        $this->check($r, $apprentice, $review);
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'period_from' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from', 'before_or_equal:today']]);
        if (Carbon::parse($data['period_from'])->diffInDays($data['period_to']) > 366) {
            throw ValidationException::withMessages(['period_from' => 'Choose a period of at most 366 days.']);
        }
        $b = ReviewBriefs::prepare($r->user(), (int) $r->session()->get('auth_version'), $review, $data);

        return to_route('review-briefs.show', [$apprentice, $review, $b])->with('status', 'Preparation captured. Check the source records, edit the proposed notes and choose which sections to use.');
    }

    public function apply(Request $r, Apprentice $apprentice, ProgressReviewDraft $review, ReviewPreparationBrief $brief)
    {
        $this->check($r, $apprentice, $review);
        abort_unless($brief->apprentice_id === $apprentice->id && $brief->progress_review_draft_id === $review->id, 404);
        $data = $r->validate(['version' => ['required', 'integer', 'min:1'], 'confirmed' => ['accepted'], 'replace_existing' => ['sometimes', 'boolean'], 'selected' => ['required', 'array', 'min:1', 'max:4'], 'selected.*' => ['required', 'distinct', Rule::in(array_keys(ReviewBriefs::FIELDS))], 'text' => ['required', 'array:learning,progressDiscussion,previousNotes,summary'], 'text.*' => ['nullable', 'string', 'max:20000']]);
        ReviewBriefs::apply($r->user(), (int) $r->session()->get('auth_version'), $brief, $data);

        return to_route('reviews.edit',[$apprentice, $review])->with('status','Checked notes added to selected draft sections. Recheck preparation items, record the actual discussion and save before signature preparation.');
    }
}
