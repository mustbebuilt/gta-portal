<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\StaffDevelopmentProfile as Profile;
use App\Models\User;
use App\Support\PrivateEvidence;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffDevelopmentController extends Controller
{
    public const KINDS = ['cpd' => 'Training / CPD', 'observation' => 'Teaching observation (full record)', 'observation-note' => 'Brief teaching note', 'one-to-one' => 'One-to-one', 'appraisal' => 'Appraisal', 'note' => 'General note'];

    public const TUTOR_FIELDS = ['setbacks' => 'What has not gone well since the last one-to-one?', 'solutions' => 'What solutions have you put in place?', 'progressComments' => 'Learner progression / feedback / PDR comments', 'overdueActions' => 'Actions in place for overdue learner work', 'learningApplied' => 'What key learning has taken place, and how have you applied it?', 'managerComments' => 'Quality Assurance Team Leader overall comments'];

    private function staff(User $u): bool
    {
        return ! in_array($u->role, ['apprentice', 'employer'], true);
    }

    private function manage(Request $r, User $target): bool
    {
        return $r->user()->role === 'ceo' || ($r->user()->id !== $target->id && Profile::where('user_id', $target->id)->value('manager_id') === $r->user()->id);
    }

    private function assertView(Request $r, User $target): void
    {
        abort_unless($this->staff($r->user()) && $this->staff($target) && ($target->id === $r->user()->id || $this->manage($r, $target)), 403);
    }

    private function locked(Request $r, User $target): Profile
    {
        User::whereKey($target->id)->lockForUpdate()->firstOrFail();
        $p = Profile::where('user_id', $target->id)->lockForUpdate()->first();
        abort_unless($this->staff($r->user()) && $this->staff($target) && ($r->user()->id === $target->id || $r->user()->role === 'ceo' || $p?->manager_id === $r->user()->id), 403);
        if ($r->integer('version') !== ($p?->version ?? 0)) {
            throw ValidationException::withMessages(['version' => 'This staff record changed. Reopen it before saving.']);
        }

        return $p ?? new Profile(['user_id' => $target->id, 'payload' => ['entries' => [], 'actions' => [], 'schedules' => [], 'months' => [], 'impacts' => []]]);
    }

    private function commit(Request $r, User $target, Profile $p, string $event, array $reference = []): void
    {
        if ($p->exists) {
            $p->version++;
        }$p->save();
        DB::table('staff_development_events')->insert(['user_id' => $target->id, 'actor_id' => $r->user()->id, 'event' => $event, 'reference' => json_encode($reference), 'created_at' => now()]);
    }

    public function index(Request $r): View
    {
        abort_unless($this->staff($r->user()), 403);
        $q = User::whereNotIn('role', ['apprentice', 'employer']);
        if ($r->user()->role !== 'ceo') {
            $ids = Profile::where('manager_id', $r->user()->id)->pluck('user_id');
            $q->where(fn ($q) => $q->where('id', $r->user()->id)->orWhereIn('id', $ids));
        }

        return view('staff.index', ['people' => $q->orderBy('name')->get()]);
    }

    public function show(Request $r, User $target): View
    {
        $this->assertView($r, $target);
        $canManage = $this->manage($r, $target);
        if ($r->query('view') === 'manager') {
            abort_unless($canManage, 403);
        }$manager = $canManage && $r->query('view') === 'manager';
        $p = Profile::where('user_id', $target->id)->first();
        $data = $p?->payload ?? ['entries' => [], 'actions' => [], 'schedules' => [], 'months' => [], 'impacts' => []];
        $data['entries'] = array_values(array_filter($data['entries'], fn ($e) => $manager || $e['kind'] === 'cpd' || $e['shared']));
        if (! $manager) {
            foreach ($data['entries'] as &$e) {
                unset($e['private_notes'],$e['history']);
                $e['files'] = array_map(fn ($f) => collect($f)->except('path')->all(), $e['files'] ?? []);
            }unset($e);
            foreach ($data['months'] as &$m) {
                unset($m['details']);
            }unset($m);
            $data['impacts'] = array_values(array_filter($data['impacts'] ?? [], fn ($i) => $i['shared'] && collect($data['entries'])->contains(fn ($e) => $e['id'] === $i['entry_id'])));
            foreach ($data['impacts'] as &$i) {
                unset($i['private_notes']);
            }unset($i);
        }

        return view('staff.show', ['target' => $target, 'data' => $data, 'version' => $p?->version ?? 0, 'canManage' => $canManage, 'manager' => $manager, 'lineManager' => User::find($p?->manager_id), 'editing' => collect($data['entries'])->first(fn ($e) => $e['id'] === $r->query('entry') && ($manager || ($target->id === $r->user()->id && $e['kind'] === 'cpd'))), 'people' => $r->user()->role === 'ceo' ? User::where('active', true)->whereNotIn('role', ['apprentice', 'employer'])->where('id', '!=', $target->id)->get() : collect()]);
    }

    public function manager(Request $r, User $target): RedirectResponse
    {
        abort_unless($r->user()->role === 'ceo' && $this->staff($target), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('active', true)->whereNotIn('role', ['apprentice', 'employer'])], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($r, $target, $data): void {
            User::orderBy('id')->lockForUpdate()->get(['id']);
            $p = $this->locked($r, $target);
            $id = $data['manager_id'] ?? null;
            $seen = [$target->id];
            while ($id) {
                if (in_array((int) $id, $seen, true)) {
                    throw ValidationException::withMessages(['manager_id' => 'This would create a circular line-management assignment.']);
                }$seen[] = (int) $id;
                $id = Profile::where('user_id', $id)->value('manager_id');
            }$before = $p->manager_id;
            $p->manager_id = $data['manager_id'] ?? null;
            $this->commit($r, $target, $p, 'manager_assigned', ['before' => $before, 'after' => $p->manager_id, 'reason' => $data['reason']]);
        });

        return back()->with('status', 'Line manager assigned. Portal/support permissions were not expanded.');
    }

    public function entry(Request $r, User $target): RedirectResponse
    {
        $this->assertView($r, $target);
        $manage = $this->manage($r, $target);
        $data = $r->validate(['version' => ['required', 'integer'], 'entry_id' => ['nullable', 'string'], 'kind' => ['required', Rule::in(array_keys(self::KINDS))], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'title' => ['required', 'string', 'max:250'], 'summary' => ['required', 'string', 'max:20000'], 'status' => ['nullable', Rule::in(['Planned', 'In progress', 'Completed'])], 'hours' => ['nullable', 'numeric', 'min:0', 'max:9999'], 'evidence' => ['nullable', 'string', 'max:5000'], 'private_notes' => ['nullable', 'string', 'max:20000'], 'shared' => ['nullable', 'boolean'], 'schedule_id' => ['nullable', 'string'], 'tutor121' => ['nullable', 'array']]);
        if (! $manage) {
            abort_unless($data['kind'] === 'cpd', 403);
            if (filled($data['private_notes'] ?? null)) {
                abort(403);
            }
        }
        $tutorFields = [];
        foreach (self::TUTOR_FIELDS as $key => $label) {
            $values = $r->validate(['tutor121.'.$key => ['nullable', 'string', 'max:10000']]);
            if (filled($values['tutor121'][$key] ?? null)) {
                $tutorFields[$key] = $values['tutor121'][$key];
            }
        }
        if ($tutorFields && ($data['kind'] !== 'one-to-one' || $target->role !== 'tutor' || ! $manage)) {
            throw ValidationException::withMessages(['tutor121' => 'HR3.9 headings apply only to a tutor one-to-one recorded by the manager.']);
        }
        DB::transaction(function () use ($r, $target, $data, $manage, $tutorFields): void {
            $p = $this->locked($r, $target);
            $payload = $p->payload;
            $entries = $payload['entries'];
            $index = null;
            foreach ($entries as $k => $e) {
                if ($e['id'] === ($data['entry_id'] ?? null)) {
                    $index = $k;
                }
            }if (filled($data['entry_id'] ?? null) && $index === null) {
                throw ValidationException::withMessages(['entry_id' => 'Choose an existing entry in this staff record.']);
            }$old = $index !== null ? $entries[$index] : null;
            if ($old && ! $manage && $old['kind'] !== 'cpd') {
                abort(403);
            }if ($old && ($old['kind'] !== $data['kind'])) {
                throw ValidationException::withMessages(['kind' => 'Keep an existing entry with its original record type. Add a new entry for another purpose.']);
            }
            $status = $data['kind'] === 'cpd' ? ($data['status'] ?? null) : 'Recorded';
            if ($data['kind'] === 'cpd' && ! $status) {
                throw ValidationException::withMessages(['status' => 'Choose the CPD status.']);
            }if ($data['kind'] === 'cpd' && $status === 'Completed' && ! filled($data['evidence'] ?? null) && ! count($old['files'] ?? [])) {
                throw ValidationException::withMessages(['evidence' => 'Completed CPD needs evidence or a certificate reference.']);
            }
            $id = $old['id'] ?? (string) Str::uuid();
            $scheduleId = $data['schedule_id'] ?? ($old['schedule_id'] ?? null);
            $schedules = $payload['schedules'];
            $scheduleIndex = null;
            if ($scheduleId) {
                if ($old && $old['schedule_id'] && $old['schedule_id'] !== $scheduleId) {
                    throw ValidationException::withMessages(['schedule_id' => 'Keep the original planned-review link.']);
                }foreach ($schedules as $i => $s) {
                    if ($s['id'] === $scheduleId) {
                        $scheduleIndex = $i;
                    }
                }if (! $manage || $scheduleIndex === null || $schedules[$scheduleIndex]['kind'] !== $data['kind'] || $schedules[$scheduleIndex]['status'] === 'Cancelled' || ($schedules[$scheduleIndex]['status'] === 'Completed' && ($schedules[$scheduleIndex]['entry_id'] ?? null) !== $id) || $data['date'] < substr($schedules[$scheduleIndex]['created_at'], 0, 10)) {
                    throw ValidationException::withMessages(['schedule_id' => 'Choose a current planned review of this exact type, using an actual date on or after booking.']);
                }
            }
            $history = $old['history'] ?? [];
            if ($old) {
                $snapshot = $old;
                unset($snapshot['history']);
                $history[] = ['at' => now()->toIso8601String(), 'by' => $r->user()->id, 'before' => $snapshot];
            }
            $entry = ['id' => $id, 'kind' => $data['kind'], 'revision' => ($old['revision'] ?? 0) + 1, 'date' => $data['date'], 'title' => $data['title'], 'summary' => $data['summary'], 'status' => $status, 'hours' => $data['kind'] === 'cpd' ? ($data['hours'] ?? null) : null, 'evidence' => $data['evidence'] ?? null, 'shared' => $data['kind'] === 'cpd' || (bool) ($data['shared'] ?? false), 'private_notes' => $manage ? ($data['private_notes'] ?? null) : ($old['private_notes'] ?? null), 'author' => $old['author'] ?? $r->user()->name, 'comments' => $old['comments'] ?? [], 'history' => $history, 'files' => $old['files'] ?? [], 'tutor121' => $tutorFields ?: ($old['tutor121'] ?? []), 'schedule_id' => $scheduleId, 'recording_mode' => $scheduleId ? 'Planned' : ($data['kind'] === 'observation-note' ? 'Brief teaching note' : (in_array($data['kind'], ['observation', 'one-to-one', 'appraisal'], true) ? 'Unplanned' : ''))];
            if ($index === null) {
                $entries[] = $entry;
            } else {
                $entries[$index] = $entry;
            }
            if ($scheduleIndex !== null) {
                $schedules[$scheduleIndex]['status'] = 'Completed';
                $schedules[$scheduleIndex]['entry_id'] = $id;
                $schedules[$scheduleIndex]['completed_date'] = $data['date'];
            }
            $payload['entries'] = $entries;
            $payload['schedules'] = $schedules;
            $p->payload = $payload;
            $this->commit($r, $target, $p, 'entry_saved', ['entry_id' => $id]);
        });

        return back()->with('status', 'Staff development record saved. Completion of activity does not automatically demonstrate impact.');
    }

    public function comment(Request $r, User $target): RedirectResponse
    {
        $this->assertView($r, $target);
        $data = $r->validate(['version' => ['required', 'integer'], 'entry_id' => ['required', 'string'], 'comment' => ['required', 'string', 'max:10000']]);
        $manage = $this->manage($r, $target);
        DB::transaction(function () use ($r, $target, $data, $manage): void {
            $p = $this->locked($r, $target);
            $v = $p->payload;
            $found = false;
            foreach ($v['entries'] as &$e) {
                if ($e['id'] === $data['entry_id']) {
                    abort_unless($manage || $e['kind'] === 'cpd' || $e['shared'], 403);
                    $e['comments'][] = ['by' => $r->user()->name, 'at' => now()->toIso8601String(), 'text' => $data['comment']];
                    $found = true;
                }
            }unset($e);
            if (! $found) {
                throw ValidationException::withMessages(['entry_id' => 'Choose a visible record.']);
            }$p->payload = $v;
            $this->commit($r, $target, $p, 'staff_response');
        });

        return back()->with('status', 'Response saved to the visible staff record.');
    }

    public function action(Request $r, User $target): RedirectResponse
    {
        abort_unless($this->manage($r, $target) && $this->staff($target), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'title' => ['required', 'string', 'max:2000'], 'measure' => ['required', 'string', 'max:5000'], 'reason' => ['required', 'string', 'max:2000'], 'support' => ['required', 'string', 'max:2000'], 'due' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'achievable' => ['accepted'], 'agreed' => ['accepted']]);
        DB::transaction(function () use ($r, $target, $data): void {
            $p = $this->locked($r, $target);
            $v = $p->payload;
            $v['actions'][] = [...collect($data)->except('version')->all(), 'id' => (string) Str::uuid(), 'status' => 'In progress', 'completed' => null, 'updates' => []];
            $p->payload = $v;
            $this->commit($r, $target, $p, 'development_action_agreed');
        });

        return back()->with('status', 'SMART staff-development action agreed.');
    }

    public function actionProgress(Request $r, User $target): RedirectResponse
    {
        $this->assertView($r, $target);
        $manage = $this->manage($r, $target);
        $data = $r->validate(['version' => ['required', 'integer'], 'action_id' => ['required', 'string'], 'status' => ['required', Rule::in(['In progress', 'Ready for manager check', 'Completed'])], 'note' => ['required', 'string', 'max:10000']]);
        if ($data['status'] === 'Completed') {
            abort_unless($manage, 403);
        }DB::transaction(function () use ($r, $target, $data, $manage): void {
            $p = $this->locked($r, $target);
            $v = $p->payload;
            $found = false;
            foreach ($v['actions'] as &$a) {
                if ($a['id'] === $data['action_id']) {
                    if ($a['status'] === 'Completed' && ! $manage) {
                        abort(403);
                    }$a['status'] = $data['status'];
                    $a['completed'] = $data['status'] === 'Completed' ? today()->toDateString() : null;
                    $a['updates'][] = ['status' => $data['status'], 'note' => $data['note'], 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
                    $found = true;
                }
            }unset($a);
            if (! $found) {
                throw ValidationException::withMessages(['action_id' => 'Choose this staff member’s existing action.']);
            }$p->payload = $v;
            $this->commit($r, $target, $p, 'development_action_updated');
        });

        return back()->with('status', 'Development progress saved.');
    }

    public function schedule(Request $r, User $target): RedirectResponse
    {
        abort_unless($this->manage($r, $target) && $this->staff($target), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'kind' => ['required', Rule::in(['observation', 'one-to-one', 'appraisal'])], 'due' => ['required', 'date_format:Y-m-d', 'after_or_equal:today']]);
        DB::transaction(function () use ($r, $target, $data): void {
            $p = $this->locked($r, $target);
            $v = $p->payload;
            $v['schedules'][] = ['id' => (string) Str::uuid(), 'kind' => $data['kind'], 'due' => $data['due'], 'status' => 'Planned', 'created_at' => now()->toIso8601String(), 'entry_id' => null, 'completed_date' => null, 'history' => []];
            $p->payload = $v;
            $this->commit($r, $target, $p, 'staff_review_planned');
        });

        return back()->with('status', 'Individual staff review planned. A booking does not mean it happened.');
    }

    public function changeSchedule(Request $r, User $target): RedirectResponse
    {
        abort_unless($this->manage($r, $target) && $this->staff($target), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'schedule_id' => ['required', 'string'], 'change' => ['required', Rule::in(['reschedule', 'cancel'])], 'due' => ['nullable', 'required_if:change,reschedule', 'date_format:Y-m-d', 'after_or_equal:today'], 'reason' => ['required', 'string', 'max:5000']]);
        DB::transaction(function () use ($r, $target, $data): void {
            $p = $this->locked($r, $target);
            $v = $p->payload;
            $index = null;
            foreach ($v['schedules'] as $key => $item) {
                if ($item['id'] === $data['schedule_id']) {
                    $index = $key;
                }
            }
            if ($index === null || $v['schedules'][$index]['status'] !== 'Planned') {
                throw ValidationException::withMessages(['schedule_id' => 'Only an outstanding planned review can be changed.']);
            }
            $s = $v['schedules'][$index];
            if ($data['change'] === 'reschedule' && $s['due'] === $data['due']) {
                throw ValidationException::withMessages(['due' => 'Choose a different date today or later.']);
            }
            $s['history'][] = ['event' => $data['change'], 'before_due' => $s['due'], 'after_due' => $data['change'] === 'reschedule' ? $data['due'] : $s['due'], 'reason' => $data['reason'], 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            if ($data['change'] === 'reschedule') {
                $s['due'] = $data['due'];
            } else {
                $s['status'] = 'Cancelled';
            }
            $v['schedules'][$index] = $s;
            $p->payload = $v;
            $this->commit($r, $target, $p, 'staff_review_'.$data['change'], ['schedule_id' => $s['id']]);
        });

        return back()->with('status', 'Planned review changed with its reason retained. No meeting or observation was marked complete.');
    }

    public const IMPACT_OUTCOMES = ['improved' => 'Improved', 'unchanged' => 'No change', 'worsened' => 'Worsened', 'too_early' => 'Too early to judge', 'unable' => 'Unable to confirm'];

    public const IMPACT_BASES = ['observation' => 'Staff observation', 'work' => 'Review of work / existing evidence', 'feedback' => 'Apprentice or employer feedback only', 'account' => 'Employee account only', 'unchecked' => 'Not yet checked'];

    public function impact(Request $r, User $target): RedirectResponse
    {
        abort_unless($this->manage($r, $target) && $this->staff($target), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'entry_id' => ['required', 'string'], 'source_revision' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'baseline_status' => ['required', Rule::in(['recorded', 'missing'])], 'baseline' => ['required', 'string', 'max:10000'], 'outcome' => ['required', Rule::in(array_keys(self::IMPACT_OUTCOMES))], 'basis' => ['required', Rule::in(array_keys(self::IMPACT_BASES))], 'summary' => ['required', 'string', 'max:10000'], 'evidence' => ['required', 'string', 'max:10000'], 'followup' => ['required', Rule::in(['none', 'target', 'review'])], 'action_id' => ['nullable', 'required_if:followup,target', 'string'], 'next_date' => ['nullable', 'required_if:followup,review', 'date_format:Y-m-d', 'after:date'], 'shared' => ['nullable', 'boolean'], 'private_notes' => ['nullable', 'string', 'max:10000']]);
        DB::transaction(function () use ($r, $target, $data): void {
            $p = $this->locked($r, $target);
            $v = $p->payload;
            $source = collect($v['entries'])->firstWhere('id', $data['entry_id']);
            if (! $source || ! in_array($source['kind'], ['cpd', 'observation'], true) || $source['revision'] !== $r->integer('source_revision')) {
                throw ValidationException::withMessages(['entry_id' => 'Choose current CPD or a full teaching observation from this staff record. Reopen if it changed.']);
            }
            $earlier = collect($v['impacts'] ?? [])->where('entry_id', $source['id'])->last();
            if ($data['date'] < $source['date'] || ($earlier && $data['date'] < $earlier['date'])) {
                throw ValidationException::withMessages(['date' => 'Keep impact reviews on or after the source date and in chronological order.']);
            }
            if (($data['baseline_status'] === 'missing' || $data['basis'] === 'unchecked' || ($source['kind'] === 'cpd' && $source['status'] === 'Planned')) && ! in_array($data['outcome'], ['too_early', 'unable'], true)) {
                throw ValidationException::withMessages(['outcome' => 'Use Too early to judge or Unable to confirm without a recorded baseline, checked basis or started CPD.']);
            }
            if (($data['followup'] === 'target') && ! collect($v['actions'])->contains('id', $data['action_id'])) {
                throw ValidationException::withMessages(['action_id' => 'Choose an existing development target from this staff record.']);
            }
            if (($data['shared'] ?? false) && $source['kind'] !== 'cpd' && ! $source['shared']) {
                throw ValidationException::withMessages(['shared' => 'Share the observation feedback first, or keep its impact review management-only.']);
            }
            $id = (string) Str::uuid();
            $v['impacts'][] = [...collect($data)->except('version')->all(), 'id' => $id, 'source' => collect($source)->only('id', 'title', 'kind', 'date', 'revision', 'status')->all(), 'shared' => (bool) ($data['shared'] ?? false), 'action_id' => $data['followup'] === 'target' ? $data['action_id'] : null, 'next_date' => $data['followup'] === 'review' ? $data['next_date'] : null, 'by' => $r->user()->name, 'at' => now()->toIso8601String()];
            $p->payload = $v;
            $this->commit($r, $target, $p, 'development_impact_reviewed', ['impact_id' => $id]);
        });

        return back()->with('status', 'Development impact assessment recorded. No action was automatically completed and no inspection grade was assigned.');
    }

    public function captureMonth(Request $r, User $target): RedirectResponse
    {
        abort_unless($this->manage($r, $target) && $target->role === 'tutor', 403);
        $r->validate(['version' => ['required', 'integer']]);
        DB::transaction(function () use ($r, $target): void {
            $p = $this->locked($r, $target);
            $ids = Apprentice::where('tutor_id', $target->id)->pluck('id');
            $tasks = LearningTask::whereIn('apprentice_id', $ids)->get();
            $v = $p->payload;
            $v['months'][] = ['id' => (string) Str::uuid(), 'month' => today()->format('Y-m'), 'at' => now()->toIso8601String(), 'by' => $r->user()->id, 'summary' => ['caseload' => $ids->count(), 'approved' => $tasks->where('status', 'Approved')->count(), 'tasks' => $tasks->count(), 'waiting' => $tasks->where('status', 'Awaiting marking')->count(), 'overdue_marking' => $tasks->filter(fn ($t) => $t->markingOverdue())->count(), 'full_plan_on_target' => null, 'full_otj_on_target' => null, 'review_compliance' => null]];
            $p->payload = $v;
            $this->commit($r, $target, $p, 'monthly_position_captured');
        });

        return back()->with('status', 'Current dated tutor caseload position captured. Historical monthly performance and missing plan/OTJ figures were not inferred.');
    }

    public function upload(Request $r, User $target): RedirectResponse
    {
        $this->assertView($r, $target);
        $manage = $this->manage($r, $target);
        $data = $r->validate(['version' => ['required', 'integer'], 'entry_id' => ['required', 'string'], 'file' => ['required', 'file', 'mimes:pdf,docx,png,jpg,jpeg,txt', 'extensions:pdf,docx,png,jpg,jpeg,txt', 'max:20480']]);
        $path = null;
        try {
            DB::transaction(function () use ($r, $target, $data, $manage, &$path): void {
                $p = $this->locked($r, $target);
                $v = $p->payload;
                $index = null;
                foreach ($v['entries'] as $k => $e) {
                    if ($e['id'] === $data['entry_id']) {
                        $index = $k;
                    }
                }if ($index === null || ! in_array($v['entries'][$index]['kind'], ['cpd', 'observation', 'observation-note', 'one-to-one', 'appraisal'], true)) {
                    throw ValidationException::withMessages(['entry_id' => 'Choose a CPD, observation, one-to-one or appraisal record.']);
                }if (! $manage && $v['entries'][$index]['kind'] !== 'cpd') {
                    abort(403);
                }$path = $r->file('file')->store('staff-evidence', PrivateEvidence::diskName());
                if (! $path) {
                    throw new \RuntimeException('Evidence could not be stored.');
                }$v['entries'][$index]['files'][] = ['id' => (string) Str::uuid(), 'path' => $path, 'name' => mb_substr(basename(str_replace('\\', '/', $r->file('file')->getClientOriginalName())), 0, 255), 'bytes' => $r->file('file')->getSize(), 'sha256' => hash_file('sha256', $r->file('file')->getRealPath()), 'at' => now()->toIso8601String(), 'by' => $r->user()->id];
                $p->payload = $v;
                $this->commit($r, $target, $p, 'staff_evidence_added');
            });
        } catch (\Throwable $e) {
            if ($path) {
                PrivateEvidence::delete($path);
            }throw $e;
        }

        return back()->with('status', 'Staff evidence saved privately.');
    }

    public function download(Request $r, User $target, string $entry, string $file): StreamedResponse
    {
        $this->assertView($r, $target);
        $p = Profile::where('user_id', $target->id)->firstOrFail();
        $e = collect($p->payload['entries'])->firstWhere('id', $entry);
        abort_unless($e && ($this->manage($r, $target) || $e['kind'] === 'cpd' || $e['shared']), 403);
        $f = collect($e['files'] ?? [])->firstWhere('id', $file);
        abort_unless($f && PrivateEvidence::exists($f['path']), 404);

        return PrivateEvidence::download($f['path'], $f['name']);
    }
}
