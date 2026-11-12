<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\ExtraTraining;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Support\AttendanceMath as Math;
use App\Support\LearningAccess as Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    private function groupAllowed(Request $r, TutorGroup $group): bool
    {
        return Access::manager($r->user()) || ($r->user()->role === 'tutor' && $group->tutor_id === $r->user()->id);
    }

    private function sessionAllowed(Request $r, TrainingSession $s): bool
    {
        return $this->groupAllowed($r, $s->group) && (Access::manager($r->user()) || Apprentice::whereIn('id', $s->members)->where(fn ($q) => $q->whereNull('tutor_id')->orWhere('tutor_id', '!=', $r->user()->id))->doesntExist());
    }

    public function index(Request $r): View
    {
        abort_unless(Access::manager($r->user()) || $r->user()->role === 'tutor', 403);
        $groups = TutorGroup::where('active', true)->when(! Access::manager($r->user()), fn ($q) => $q->where('tutor_id', $r->user()->id))->orderBy('name')->get();
        $sessions = TrainingSession::with('group')->whereIn('tutor_group_id', $groups->pluck('id'))->orderByDesc('session_date')->get()->filter(fn ($s) => $this->sessionAllowed($r, $s));

        return view('attendance.index', compact('groups', 'sessions'));
    }

    public function create(Request $r): RedirectResponse
    {
        abort_unless(Access::manager($r->user()) || $r->user()->role === 'tutor', 403);
        $data = $r->validate(['tutor_group_id' => ['required', 'integer', Rule::exists('tutor_groups', 'id')->where('active', true)], 'name' => ['required', 'string', 'max:250'], 'session_date' => ['required', 'date_format:Y-m-d'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i', 'after:start_time'], 'planned_learning' => ['required', 'string', 'max:10000'], 'breaks_checked' => ['accepted'], 'breaks' => ['nullable', 'array', 'max:10'], 'breaks.*.start' => ['nullable', 'required_with:breaks.*.end', 'date_format:H:i'], 'breaks.*.end' => ['nullable', 'required_with:breaks.*.start', 'date_format:H:i']]);
        $group = TutorGroup::findOrFail($data['tutor_group_id']);
        abort_unless($this->groupAllowed($r, $group), 403);
        $members = Apprentice::where('tutor_group_id', $group->id)->orderBy('id')->get();
        if ($members->isEmpty()) {
            throw ValidationException::withMessages(['tutor_group_id' => 'This group has no apprentices. Assign members first.']);
        }
        if (! Access::manager($r->user()) && $members->contains(fn ($a) => $a->tutor_id !== $r->user()->id)) {
            throw ValidationException::withMessages(['tutor_group_id' => 'Some group members have a different main tutor. An administrator must check this roster.']);
        }
        $breaks = array_values(array_filter($data['breaks'] ?? [], fn ($b) => filled($b['start'] ?? null) || filled($b['end'] ?? null)));
        foreach ($breaks as $b) {
            if ($b['start'] < $data['start_time'] || $b['end'] > $data['end_time'] || $b['end'] <= $b['start']) {
                throw ValidationException::withMessages(['breaks' => 'Excluded periods must be within the session and end after they start.']);
            }
        }
        $s = DB::transaction(function () use ($r, $data, $members, $breaks) {
            $s = TrainingSession::create(collect($data)->only('tutor_group_id', 'name', 'session_date', 'start_time', 'end_time', 'planned_learning')->all() + ['created_by' => $r->user()->id, 'members' => $members->pluck('id')->all(), 'breaks' => $breaks]);
            $this->audit($r, $s, 'planned');

            return $s;
        });

        return to_route('attendance.show', $s)->with('status', 'Session planned. Roster saved; no attendance or OTJ hours inferred.');
    }

    public function show(Request $r, TrainingSession $session): View
    {
        abort_unless($this->sessionAllowed($r, $session), 403);

        return view('attendance.session', ['session' => $session->load('marks'), 'members' => Apprentice::whereIn('id', $session->members)->orderBy('last_name')->get()]);
    }

    public function save(Request $r, TrainingSession $session): RedirectResponse
    {
        abort_unless($this->sessionAllowed($r, $session), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'delivered_learning' => ['nullable', 'string', 'max:10000'], 'marks' => ['required', 'array'], 'marks.*.code' => ['nullable', Rule::in(array_keys(Math::CODES))], 'marks.*.arrival' => ['nullable', 'date_format:H:i'], 'marks.*.departure' => ['nullable', 'date_format:H:i'], 'marks.*.otj_confirmed' => ['nullable', 'boolean'], 'marks.*.minutes' => ['nullable', 'integer', 'min:0'], 'marks.*.comment' => ['nullable', 'string', 'max:2000'], 'marks.*.adjustment' => ['nullable', 'string', 'max:2000']]);
        DB::transaction(function () use ($r, $session, $data): void {
            $s = TrainingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $s);
            if ($s->state === 'Cancelled' || ! $s->ended()) {
                throw ValidationException::withMessages(['marks' => 'Save the actual register after this session has ended. Cancelled sessions cannot be marked.']);
            }
            $ids = $s->members;
            sort($ids);
            Apprentice::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if (array_diff(array_map('intval', array_keys($data['marks'])), $ids)) {
                throw ValidationException::withMessages(['marks' => 'A mark was supplied for someone outside this roster.']);
            }
            $before = $s->marks()->get()->toArray();
            foreach ($ids as $id) {
                $v = $data['marks'][$id] ?? [];
                $code = $v['code'] ?? null;
                $confirmed = (bool) ($v['otj_confirmed'] ?? false);
                $parts = [];
                $minutes = null;
                if (in_array($code, ['O', 'T'], true)) {
                    if (! filled($v['arrival'] ?? null) || ! filled($v['departure'] ?? null) || $v['departure'] <= $v['arrival']) {
                        throw ValidationException::withMessages(['marks' => 'Record actual arrival and departure for attended training.']);
                    }$parts = Math::parts($v['arrival'], $v['departure'], $s->start_time, $s->end_time, $s->breaks);
                    if (! $parts) {
                        throw ValidationException::withMessages(['marks' => 'Attendance must overlap the session and leave eligible time after exclusions.']);
                    }
                    if ($confirmed) {
                        if (! filled($data['delivered_learning'] ?? null)) {
                            throw ValidationException::withMessages(['delivered_learning' => 'Record the eligible learning delivered before confirming OTJ.']);
                        }$proposal = array_sum(array_map(fn ($p) => $p[1] - $p[0], $parts));
                        $minutes = filled($v['minutes'] ?? null) ? (int) $v['minutes'] : $proposal;
                        if ($minutes > $proposal) {
                            throw ValidationException::withMessages(['marks' => 'Confirmed minutes cannot exceed actual eligible attendance.']);
                        }if ($minutes < $proposal && ! filled($v['adjustment'] ?? null)) {
                            throw ValidationException::withMessages(['marks' => 'Explain the reduction from proposed eligible minutes.']);
                        }
                        if ($minutes > 0) {
                            $extras = ExtraTraining::where('apprentice_id', $id)->whereDate('training_date', $s->session_date)->whereNull('withdrawn_at')->whereNotNull('approval')->get();
                            foreach ($extras as $extra) {
                                if (Math::overlap($parts, $extra->parts)) {
                                    throw ValidationException::withMessages(['marks' => 'This time overlaps approved extra-training OTJ for the apprentice.']);
                                }
                            }
                        }
                        $existing = AttendanceMark::with('session')->where('apprentice_id', $id)->where('otj_confirmed', true)->where('otj_minutes', '>', 0)->where('training_session_id', '!=', $s->id)->whereHas('session', fn ($q) => $q->whereDate('session_date', $s->session_date)->where('state', '!=', 'Cancelled'))->get();
                        foreach ($existing as $other) {
                            if ($minutes > 0 && Math::overlap($parts, $other->parts ?? [])) {
                                throw ValidationException::withMessages(['marks' => 'This time overlaps another confirmed OTJ entry for the apprentice.']);
                            }
                        }
                    }
                } else {
                    if ($confirmed || (int) ($v['minutes'] ?? 0) > 0) {
                        throw ValidationException::withMessages(['marks' => 'This attendance code cannot generate OTJ hours.']);
                    }$minutes = $code ? 0 : null;
                }
                AttendanceMark::updateOrCreate(['training_session_id' => $s->id, 'apprentice_id' => $id], ['code' => $code, 'arrival' => in_array($code, ['O', 'T'], true) ? $v['arrival'] : null, 'departure' => in_array($code, ['O', 'T'], true) ? $v['departure'] : null, 'parts' => $parts, 'otj_minutes' => $minutes, 'otj_confirmed' => $confirmed, 'recorded_by' => $r->user()->id, 'comment' => $v['comment'] ?? null, 'adjustment' => $v['adjustment'] ?? null]);
            }
            $h = $s->history ?? [];
            $h[] = ['event' => 'register_saved', 'by' => $r->user()->id, 'at' => now()->toIso8601String(), 'before_marks' => $before, 'before_delivered' => $s->delivered_learning];
            $s->history = $h;
            $s->delivered_learning = $data['delivered_learning'] ?? null;
            $s->version++;
            $s->save();
            $this->audit($r, $s, 'register_saved');
        });

        return back()->with('status', 'Register saved. Only explicitly confirmed eligible minutes enter the session OTJ subtotal.');
    }

    public function cancel(Request $r, TrainingSession $session): RedirectResponse
    {
        abort_unless($this->sessionAllowed($r, $session), 403);
        $r->validate(['version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($r, $session): void {
            $s = TrainingSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $s);
            $s->state = 'Cancelled';
            $s->cancellation_reason = $r->input('reason');
            $s->version++;
            $s->save();
            $this->audit($r, $s, 'cancelled');
        });

        return back()->with('status', 'Session cancelled. It is excluded from attendance percentages and OTJ totals.');
    }

    public function apprentice(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::training($r->user(), $apprentice), 403);
        $rows = [];
        $sessions = TrainingSession::with(['marks' => fn ($q) => $q->where('apprentice_id', $apprentice->id)])->whereJsonContains('members', $apprentice->id)->orderBy('session_date')->get();
        foreach ($sessions as $s) {
            if (! $s->ended()) {
                continue;
            }$m = $s->marks->first();
            $rows[] = ['id' => $s->id, 'name' => $s->name, 'date' => $s->session_date->toDateString(), 'code' => $m?->code, 'minutes' => $s->state === 'Cancelled' ? 0 : ($m?->otj_confirmed ? $m->otj_minutes : null), 'cancelled' => $s->state === 'Cancelled', 'delivered' => $s->delivered_learning];
        }

        $extraMinutes = ExtraTraining::where('apprentice_id', $apprentice->id)->whereNull('withdrawn_at')->whereNotNull('approval')->get()->filter(fn ($e) => $e->occurred())->sum('minutes');

        return view('attendance.apprentice', ['apprentice' => $apprentice, 'extraMinutes' => $extraMinutes, 'rows' => $rows, 'report' => Math::report($rows)]);
    }

    private function version(Request $r, TrainingSession $s): void
    {
        if ($s->version !== $r->integer('version')) {
            throw ValidationException::withMessages(['version' => 'This session changed. Reopen before saving.']);
        }
    }

    private function audit(Request $r, TrainingSession $s, string $event): void
    {
        foreach ($s->members as $id) {
            DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $id, 'kind' => 'session', 'record_id' => $s->id, 'event' => $event, 'created_at' => now()]);
        }
    }
}
