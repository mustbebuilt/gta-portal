<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\Qualification;
use App\Models\TrainingPlanWorkspace as Workspace;
use App\Models\TrainingTopic;
use App\Support\LearningAccess as Access;
use App\Support\TrainingPlanSource as Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TrainingPlanController extends Controller
{
    private function locked(Request $r, Apprentice $a): Workspace
    {
        Apprentice::whereKey($a->id)->lockForUpdate()->firstOrFail();
        $row = Workspace::where('apprentice_id', $a->id)->lockForUpdate()->first();
        if ($r->integer('version') !== ($row?->version ?? 0)) {
            throw ValidationException::withMessages(['version' => 'This planning workspace changed. Reopen before saving.']);
        }
        if ($row && $row->source_hash !== Source::hash()) {
            throw ValidationException::withMessages(['version' => 'The source changed. Controlled source reconciliation is required; no records were overwritten.']);
        }

        return $row ?? new Workspace(['apprentice_id' => $a->id, 'source_hash' => Source::hash(), 'rpl' => [], 'proposal' => []]);
    }

    private function commit(Request $r, Workspace $row, string $event, array $before = []): void
    {
        $h = $row->history ?? [];
        $h[] = ['event' => $event, 'before' => $before, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
        $row->history = $h;
        if ($row->exists) {
            $row->version++;
        } $row->save();
        DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $row->apprentice_id, 'kind' => 'training_plan', 'record_id' => $row->id, 'event' => $event, 'created_at' => now()]);
    }

    public function show(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::training($r->user(), $apprentice), 403);
        $row = Workspace::where('apprentice_id', $apprentice->id)->first();
        $manage = Access::manager($r->user());
        $reference = Source::load('training-plan-reference');
        $code = $r->query('code', $reference['ksbs'][0]['code']);
        if (! in_array($code, array_column($reference['ksbs'], 'code'), true)) {
            $code = $reference['ksbs'][0]['code'];
        }

        return view('plans.workspace', ['apprentice' => $apprentice, 'reference' => $reference, 'baseline' => Source::load('programme-baseline'), 'manage' => $manage, 'proposal' => $row?->proposal ?? [], 'version' => $row?->version ?? 0, 'sourceOk' => ! $row || $row->source_hash === Source::hash(), 'rpl' => $manage ? $row?->rpl ?? [] : [], 'position' => $manage ? Source::position($row) : null, 'history' => $manage ? $row?->history ?? [] : [], 'reviews' => $manage ? $row?->reviews ?? [] : [], 'selected' => $code, 'topics' => TrainingTopic::where('apprentice_id', $apprentice->id)->orderBy('subject')->get(), 'qualifications' => Qualification::where('apprentice_id', $apprentice->id)->get()]);
    }

    public function proposal(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(Access::manager($r->user()), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'programmeRef' => ['nullable', Rule::in([Source::load('training-plan-reference')['id']])], 'practicalEnd' => ['nullable', 'date_format:Y-m-d'], 'otjHours' => ['nullable', 'numeric', 'gt:0', 'max:999999'], 'basis' => ['nullable', Rule::in(Source::BASES)]]);
        if (($data['practicalEnd'] ?? null) && $apprentice->start_date && $data['practicalEnd'] < $apprentice->start_date->toDateString()) {
            throw ValidationException::withMessages(['practicalEnd' => 'The proposed practical end cannot precede the recorded first learning day.']);
        }
        if (! filled($data['programmeRef'] ?? null) && (filled($data['practicalEnd'] ?? null) || filled($data['otjHours'] ?? null) || filled($data['basis'] ?? null))) {
            throw ValidationException::withMessages(['programmeRef' => 'Choose the loaded programme/version before entering a proposal.']);
        }
        if ((filled($data['practicalEnd'] ?? null) || filled($data['otjHours'] ?? null)) && ! filled($data['basis'] ?? null)) {
            throw ValidationException::withMessages(['basis' => 'Choose the planning basis for proposed dates or hours.']);
        }
        DB::transaction(function () use ($r, $apprentice, $data): void {
            $row = $this->locked($r, $apprentice);
            $before = $row->proposal ?? [];
            $row->proposal = collect($data)->except('version')->all();
            $this->commit($r, $row, 'plan_proposal_saved', $before);
        });

        return back()->with('status', 'Draft proposal saved. No individual target, block date, price or achievement changed.');
    }

    public function rpl(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(Access::manager($r->user()), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'code' => ['required', Rule::in(array_column(Source::load('training-plan-reference')['ksbs'], 'code'))], 'outcome' => ['required', Rule::in(array_keys(Source::outcomes()))], 'source' => ['nullable', Rule::in(Source::EVIDENCE)], 'evidence' => ['nullable', 'string', 'max:2000'], 'omission' => ['required', Rule::in(Source::OMISSIONS)], 'content' => ['nullable', 'string', 'max:2000']]);
        Source::percent($data);
        DB::transaction(function () use ($r, $apprentice, $data): void {
            $row = $this->locked($r, $apprentice);
            $records = $row->rpl ?? [];
            $before = $records[$data['code']] ?? [];
            $values = collect($data)->except('version', 'code')->all();
            if ($before && collect(['outcome', 'source', 'evidence', 'omission', 'content'])->every(fn ($key) => ($before[$key] ?? null) === ($values[$key] ?? null))) {
                return;
            }
            $records[$data['code']] = [...$values, 'revision' => ($before['revision'] ?? 0) + 1, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $row->rpl = $records;
            $this->commit($r, $row, 'rpl_assessment_saved', ['code' => $data['code'], 'assessment' => $before]);
        });

        return back()->with('status', 'Individual RPL assessment saved. Approval and applying a revised training plan are separate steps.');
    }

    public function review(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(Access::manager($r->user()), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'binding' => ['required', 'string', 'size:64'], 'decision' => ['required', Rule::in(['approve', 'return', 'withdraw'])], 'reason' => ['nullable', Rule::in(array_keys(Source::REVIEW_REASONS))], 'code' => ['nullable', Rule::in(['all', ...array_column(Source::load('training-plan-reference')['ksbs'], 'code')])], 'assessment_checked' => ['nullable', 'boolean'], 'omissions_checked' => ['nullable', 'boolean']]);
        DB::transaction(function () use ($r, $apprentice, $data): void {
            $row = $this->locked($r, $apprentice);
            $p = Source::position($row);
            if ($p['binding'] !== $data['binding'] || ! $p['recorded']) {
                throw ValidationException::withMessages(['binding' => 'The assessment changed or no valid KSB decisions are saved. Reopen the review.']);
            }
            if ($data['decision'] === 'approve') {
                $r->validate(['assessment_checked' => ['accepted'], 'omissions_checked' => ['accepted']]);
                if (! $p['complete'] || $p['approved'] || ($p['current'] && $p['review']['status'] === 'Changes needed')) {
                    throw ValidationException::withMessages(['decision' => 'All current-source decisions must be complete and changed where requested before a new approval.']);
                }$status = 'Assessment approved';
            } else {
                $r->validate(['reason' => ['required'], 'code' => ['required']]);
                if (($data['decision'] === 'withdraw' && ! $p['approved']) || ($data['decision'] === 'return' && $p['approved'])) {
                    throw ValidationException::withMessages(['decision' => 'Withdraw an existing current approval before returning it, or approve the assessed version before withdrawal.']);
                } $status = $data['decision'] === 'return' ? 'Changes needed' : 'Approval withdrawn';
            }
            $items = $row->reviews ?? [];
            $items[] = ['status' => $status, 'binding' => $p['binding'], 'reason' => $data['reason'] ?? null, 'code' => $data['code'] ?? null, 'by' => $r->user()->id, 'at' => now()->toIso8601String(), 'recorded' => $p['recorded'], 'checks' => $data['decision'] === 'approve' ? ['assessment' => true, 'omissions' => true] : null];
            $row->reviews = $items;
            $this->commit($r, $row, 'rpl_review_recorded');
        });

        return back()->with('status', 'Assessment review saved. No individual training, date, target or financial reduction was applied.');
    }
}
