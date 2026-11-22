<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\IndividualDeliveryPlan as Plan;
use App\Models\LearningTask;
use App\Models\TrainingPlanWorkspace;
use App\Support\DeliveryPlan;
use App\Support\LearningAccess as Access;
use App\Support\PortalPdf;
use App\Support\PlanCurriculum;
use App\Support\TaskCatalogue;
use App\Support\TrainingPlanSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeliveryPlanController extends Controller
{
    private function lock(Request $r, Apprentice $a, bool $requireSource = true): array
    {
        $a = Apprentice::whereKey($a->id)->lockForUpdate()->firstOrFail();
        if ($a->programme_id) { $a->programmeRecord()->lockForUpdate()->first(); }
        if ($requireSource && (! TaskCatalogue::keyFor($a) || ! TaskCatalogue::historyMatches($a))) {
            throw ValidationException::withMessages(['version' => 'The current programme/version and saved tasks must match a loaded catalogue.']);
        }
        $row = Plan::where('apprentice_id', $a->id)->lockForUpdate()->first();
        if ($r->integer('version') !== ($row?->version ?? 0)) {
            throw ValidationException::withMessages(['version' => 'This individual plan changed. Reopen before saving.']);
        }

        return [$a, $row ?? new Plan(['apprentice_id' => $a->id, 'draft' => [], 'history' => []])];
    }

    private function commit(Request $r, Plan $row, string $event, array $detail): void
    {
        $row->history = [...($row->history ?? []), ['event' => $event, 'by' => $r->user()->id, 'at' => now()->toIso8601String(), ...$detail]];
        if ($row->exists) { $row->version++; }
        $row->save();
        DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $row->apprentice_id, 'kind' => 'delivery_plan', 'record_id' => $row->id, 'event' => $event, 'created_at' => now()]);
    }

    private function draft(Plan $row, Apprentice $a, Request $r, array $values): array
    {
        $same = DeliveryPlan::identityMatches($a, $row->draft['identity'] ?? DeliveryPlan::identity($a)) && ($row->draft['catalogue_hash'] ?? DeliveryPlan::catalogueHash(TaskCatalogue::keyFor($a))) === DeliveryPlan::catalogueHash(TaskCatalogue::keyFor($a));
        $base = $same ? $row->draft : [];

        return [...$base, ...$values, 'identity' => DeliveryPlan::identity($a), 'catalogue_hash' => DeliveryPlan::catalogueHash(TaskCatalogue::keyFor($a)), 'authors' => array_values(array_unique([...($base['authors'] ?? []), $r->user()->id]))];
    }

    public function show(Request $r, Apprentice $apprentice)
    {
        abort_unless(Access::training($r->user(), $apprentice), 403);
        $row = Plan::where('apprentice_id', $apprentice->id)->first();
        $approved = DeliveryPlan::approved($apprentice);
        $manage = Access::manager($r->user());

        return view('plans.delivery', ['apprentice' => $apprentice, 'row' => $row, 'draft' => $manage ? $row?->draft ?? [] : [], 'blocks' => $manage ? $row?->draft['blocks'] ?? DeliveryPlan::blocks($apprentice) : $approved['blocks'] ?? [], 'approved' => $approved, 'manage' => $manage, 'curriculumTasks'=>$manage ? PlanCurriculum::tasks(TaskCatalogue::keyFor($apprentice)) : [],
            'curriculumTopics'=>$manage ? PlanCurriculum::topics() : [], 'preview' => $manage ? DeliveryPlan::preview($apprentice, $approved) : [], 'taskBinding' => $manage ? DeliveryPlan::taskBinding($apprentice) : null]);
    }

    public function calendar(Request $r, Apprentice $apprentice)
    {
        abort_unless(Access::manager($r->user()), 403);
        $data = $r->validate(['version' => ['required', 'integer', 'min:0'], 'blocks' => ['required', 'array', 'size:12'], 'blocks.*' => ['required', 'array:start,end,hours'], 'blocks.*.start' => ['nullable', 'date_format:Y-m-d'], 'blocks.*.end' => ['nullable', 'date_format:Y-m-d'], 'blocks.*.hours' => ['nullable', 'numeric', 'min:0', 'max:10000']]);
        DB::transaction(function () use ($r, $apprentice, $data): void {
            [$a, $row] = $this->lock($r, $apprentice);
            $blocks = DeliveryPlan::blocks($a);
            $last = null;
            foreach ($blocks as $i => &$block) {
                $input = $data['blocks'][$i] ?? null;
                if (! $input) { throw ValidationException::withMessages(['blocks' => 'Use the twelve numbered block rows.']); }
                $start = $input['start'] ?? null; $end = $input['end'] ?? null;
                if ((bool) $start !== (bool) $end || ($start && ($start > $end || ($last && $start <= $last) || ($a->start_date && $start < $a->start_date->toDateString())))) {
                    throw ValidationException::withMessages(['blocks' => 'Each dated block needs a start and end, in sequence without overlap and on or after first learning.']);
                }
                if ($end) { $last = $end; }
                if ($i === 11 && (float) ($input['hours'] ?? 0) !== 0.0) { throw ValidationException::withMessages(['blocks' => 'The EPA assessment window cannot attract planned training hours.']); }
                $block = [...$block, 'start' => $start, 'end' => $end, 'hours' => $input['hours'] ?? null];
            }
            unset($block);
            $before = $row->draft;
            $row->draft = $this->draft($row, $a, $r, ['blocks' => $blocks]);
            $this->commit($r, $row, 'calendar_saved', ['before' => $before]);
        });

        return back()->with('status', 'Draft block calendar saved. No task deadlines, attendance or approved plan changed.');
    }

    public function details(Request $r, Apprentice $apprentice)
    {
        abort_unless(Access::manager($r->user()), 403);
        $data = $r->validate(['version' => ['required', 'integer', 'min:0'], 'source_ref' => ['required', 'string', 'max:2000'], 'ksb_ref' => ['required', 'string', 'max:2000'], 'qualifications_ref' => ['required', 'string', 'max:2000'], 'release_ref' => ['required', 'string', 'max:2000'], 'target_hours' => ['required', 'numeric', 'gt:0', 'max:10000'], 'practical_end_date'=>['nullable','date_format:Y-m-d'], 'apprenticeship_end_date'=>['nullable','date_format:Y-m-d'], 'basis' => ['required', Rule::in(['Full training retained', 'Controlled individual plan', 'Current Autocare RPL assessment'])]]);
        DB::transaction(function () use ($r, $apprentice, $data): void {
            [$a, $row] = $this->lock($r, $apprentice);
            $before = $row->draft;
            $rpl = null;
            $dates = array_intersect_key($before['details'] ?? [], array_flip(['practical_end_date','apprenticeship_end_date','agreed_dates_source']));
            $data += $dates;
            $practical = $data['practical_end_date'] ?? null;
            $end = $data['apprenticeship_end_date'] ?? null;
            if (($practical || $end) && (!$a->start_date || !$practical || !$end
                || $practical < $a->start_date->toDateString() || $end < $practical)) {
                throw ValidationException::withMessages(['practical_end_date'=>'Enter both agreed finish dates in order after the apprenticeship start.']);
            }
            if (($before['rpl_binding'] ?? null) && (($before['details']['basis'] ?? null) === $data['basis'])) {
                $position = DeliveryPlan::rplPosition($a, $before['rpl_binding'], true);
                if (!$position || !DeliveryPlan::targetMatches($position, $data['target_hours'])) {
                    throw ValidationException::withMessages(['target_hours'=>'The saved prior learning approval and revised hours must agree. Reconcile them before changing plan details.']);
                }
                $rpl = $before['rpl_binding'];
            } elseif (($before['rpl_binding'] ?? null) && ($before['details']['basis'] ?? null) === 'Controlled individual plan') {
                throw ValidationException::withMessages(['basis'=>'Amend the assessed prior learning through guided apprentice setup.']);
            } elseif ($data['basis'] === 'Current Autocare RPL assessment') {
                $w = TrainingPlanWorkspace::where('apprentice_id', $a->id)->lockForUpdate()->first();
                $p = TrainingPlanSource::position($w);
                if (TaskCatalogue::keyFor($a) !== 'autocare' || ! $p['approved']) {
                    throw ValidationException::withMessages(['basis' => 'A complete current approved Autocare RPL assessment is required. Other programmes need their own controlled source.']);
                }
                if (!DeliveryPlan::targetMatches($p, $data['target_hours'])) {
                    throw ValidationException::withMessages(['target_hours'=>'Use the remaining hours from the approved prior learning assessment.']);
                }
                $rpl = $p['binding'];
            }
            if ($practical && $end && ($practical !== ($dates['practical_end_date'] ?? null) || $end !== ($dates['apprenticeship_end_date'] ?? null))) {
                $data['agreed_dates_source'] = 'Approved individual delivery plan';
            }
            if (!$practical && !$end) {
                unset($data['agreed_dates_source']);
            }
            $row->draft = $this->draft($row, $a, $r, ['details' => collect($data)->except('version')->all(), 'rpl_binding' => $rpl]);
            $this->commit($r, $row, 'plan_details_saved', ['before' => $before]);
        });

        return back()->with('status', 'Individual delivery-plan details saved for review. No price, funding or profile target changed.');
    }

    public function curriculum(Request $r, Apprentice $apprentice)
    {
        abort_unless(Access::manager($r->user()), 403);
        $data = $r->validate([
            'version'=>['required','integer','min:0'],
            'task_scope'=>['required','in:all,selected'], 'tasks'=>['nullable','array','list','max:100'],
            'tasks.*'=>['required','string','max:100'],
            'subjects'=>['nullable','array:english,maths'],
            'subjects.*'=>['array:scope,topics,due'],
            'subjects.*.scope'=>['nullable','in:none,selected'],
            'subjects.*.topics'=>['nullable','array','list','max:100'],
            'subjects.*.topics.*'=>['required','string','max:100'],
            'subjects.*.due'=>['nullable','date_format:Y-m-d'],
        ]);
        DB::transaction(function () use ($r, $apprentice, $data): void {
            [$a, $row] = $this->lock($r, $apprentice);
            $before = $row->draft;
            $scope = PlanCurriculum::prepare($a, $data);
            $row->draft = $this->draft($row, $a, $r, ['curriculum'=>$scope]);
            $this->commit($r, $row, 'curriculum_scope_saved', ['before'=>$before]);
        });

        return back()->with('status', 'Draft curriculum saved. It will feed reviews and register coverage once the plan is approved.');
    }

    public function approve(Request $r, Apprentice $apprentice)
    {
        abort_unless(Access::manager($r->user()), 403);
        $r->validate(['version' => ['required', 'integer'], 'source_checked' => ['accepted'], 'hours_checked' => ['accepted'], 'curriculum_checked' => ['accepted'], 'release_checked' => ['accepted']]);
        DB::transaction(function () use ($r, $apprentice): void {
            [$a, $row] = $this->lock($r, $apprentice); $d = $row->draft;
            if (! $row->exists || in_array($r->user()->id, $d['authors'] ?? [], true)) { throw ValidationException::withMessages(['version' => 'An independent administrator or CEO who did not prepare this draft must review it.']); }
            if (!DeliveryPlan::identityMatches($a, $d['identity'] ?? null) || ($d['catalogue_hash'] ?? null) !== DeliveryPlan::catalogueHash(TaskCatalogue::keyFor($a))) { throw ValidationException::withMessages(['version' => 'The programme, profile or source changed. Reconcile and save the draft before approval.']); }
            $blocks = $d['blocks'] ?? []; $details = $d['details'] ?? [];
            if (count($blocks) !== 12 || collect($blocks)->contains(fn ($b) => ! filled($b['start']) || ! filled($b['end']) || $b['hours'] === null) || ! $a->start_date || ! filled($a->employerLabel()) || ! filled($details['source_ref'] ?? null) || ! filled($details['ksb_ref'] ?? null) || ! filled($details['qualifications_ref'] ?? null) || ! filled($details['release_ref'] ?? null) || ! isset($details['target_hours']) || abs(array_sum(array_column($blocks, 'hours')) - (float) $details['target_hours']) > 0.001) {
                throw ValidationException::withMessages(['version' => 'Complete all twelve dated blocks/hours and the controlled source references. Planned hours must equal the occupational target; first learning and employer must be recorded.']);
            }
            if (isset($details['practical_end_date'], $details['apprenticeship_end_date'])) {
                $practical = $details['practical_end_date'];
                $practicalMatches = $practical === $blocks[10]['end']
                    || ($practical >= $blocks[9]['end'] && $practical < $blocks[10]['start']);
                if (!$practicalMatches || $details['apprenticeship_end_date'] !== $blocks[11]['end']) {
                    throw ValidationException::withMessages(['practical_end_date'=>'The agreed finish dates must match the practical training and EPA phases of the block schedule.']);
                }
            }
            if ($d['rpl_binding'] ?? null) {
                $p = DeliveryPlan::rplPosition($a, $d['rpl_binding'], true);
                if (!$p || !DeliveryPlan::targetMatches($p, $details['target_hours'])) { throw ValidationException::withMessages(['version' => 'The RPL assessment approval changed. Reconcile the plan first.']); }
            }
            if ($row->active && ($row->approved['draft_hash'] ?? null) === hash('sha256', json_encode($d, JSON_THROW_ON_ERROR))) { throw ValidationException::withMessages(['version' => 'This draft revision is already approved.']); }
            $before = $row->approved;
            $snapshot = [...$d, 'draft_hash' => hash('sha256', json_encode($d, JSON_THROW_ON_ERROR)), 'draft_version' => $row->version, 'approved_by' => $r->user()->id, 'approved_at' => now()->toIso8601String(), 'approval_scope' => 'Provider operational delivery plan; participant signatures, funding compliance and KSB achievement separate'];
            $snapshot['hash'] = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $row->approved = $snapshot; $row->active = true;
            $this->commit($r, $row, 'delivery_plan_approved', ['before' => $before, 'approved_hash' => $snapshot['hash']]);
        });

        return back()->with('status', 'Provider delivery plan approved. Task deadlines need a separate preview and confirmation. Participant signing and financial approval remain separate.');
    }

    public function withdraw(Request $r, Apprentice $apprentice)
    {
        abort_unless(Access::manager($r->user()), 403);
        $r->validate(['version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($r, $apprentice): void {
            [$a, $row] = $this->lock($r, $apprentice, false);
            if (! $row->active) { throw ValidationException::withMessages(['version' => 'There is no active approval to withdraw.']); }
            $row->active = false;
            $this->commit($r, $row, 'delivery_plan_withdrawn', ['reason' => $r->input('reason'), 'approved_hash' => $row->approved['hash']]);
        });

        return back()->with('status', 'Approval withdrawn. Its snapshot, existing deadlines and review history are retained.');
    }

    public function apply(Request $r, Apprentice $apprentice)
    {
        abort_unless(Access::manager($r->user()), 403);
        $r->validate(['version' => ['required', 'integer'], 'approval_hash' => ['required', 'string', 'size:64'], 'task_binding' => ['required', 'string', 'size:64'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $apprentice): void {
            [$a, $row] = $this->lock($r, $apprentice);
            $actor=\App\Support\TaskDeadlineHistory::actor($r->user(),$r->session()->get('auth_version'),$a,true);
            $tasks = LearningTask::where('apprentice_id', $a->id)->orderBy('id')->lockForUpdate()->get(); $plan = DeliveryPlan::approved($a);
            if (! $plan || $plan['hash'] !== $r->input('approval_hash') || DeliveryPlan::taskBinding($a) !== $r->input('task_binding')) { throw ValidationException::withMessages(['version' => 'The approved plan or task records changed. Reopen the preview.']); }
            $changes = [];
            foreach ($tasks as $t) {
                $due = DeliveryPlan::deadline($t, $plan);
                if (! $due) { continue; }
                $changes[] = ['task_id' => $t->id, 'before_version' => $t->version, 'due_date' => $due];
                \App\Support\TaskDeadlineHistory::record($t,$due,$actor,'approved_plan',null,['plan_id'=>$row->id,'approved_hash'=>$plan['hash']]);
                $t->version++; $t->save();
                DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $a->id, 'kind' => 'task', 'record_id' => $t->id, 'event' => 'plan_deadline_applied', 'created_at' => now()]);
            }
            if ($changes) { $this->commit($r, $row, 'plan_deadlines_applied', ['approved_hash' => $plan['hash'], 'changes' => $changes]); }
        });

        return back()->with('status', 'Unset draft/referred task deadlines filled from the approved preview. Existing dates and all submitted/approved work were preserved.');
    }

    public function pdf(Request $r, Apprentice $apprentice)
    {
        abort_unless(Access::training($r->user(), $apprentice), 403);
        $approved = DeliveryPlan::approved($apprentice); abort_unless($approved, 404);

        return PortalPdf::response('pdf.delivery-plan', compact('apprentice', 'approved'), 'GTA-Delivery-Plan-'.$apprentice->reference);
    }
}
