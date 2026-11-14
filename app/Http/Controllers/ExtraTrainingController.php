<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\ExtraTraining;
use App\Support\AttendanceMath as Math;
use App\Support\LearningAccess as Access;
use App\Support\PrivateEvidence;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExtraTrainingController extends Controller
{
    public const AGREEMENT_SOURCES = ['Apprentice email', 'Recorded discussion with apprentice'];

    public const COMPENSATION_SOURCES = ['Employer email', 'Progress review discussion with employer', 'Employer written confirmation'];

    private function write(Request $r, Apprentice $a): bool
    {
        return Access::manager($r->user()) || Access::tutor($r->user(), $a);
    }

    private function locked(Request $r, ExtraTraining $extra, bool $agreement = false): ExtraTraining
    {
        $a = Apprentice::whereKey($extra->apprentice_id)->lockForUpdate()->firstOrFail();
        abort_unless($this->write($r, $a) || ($agreement && Access::learner($r->user(), $a)), 403);
        $row = ExtraTraining::whereKey($extra->id)->lockForUpdate()->firstOrFail();
        if ($row->version !== $r->integer('version')) {
            throw ValidationException::withMessages(['version' => 'This extra-training record changed. Reopen before saving.']);
        }

        return $row;
    }

    private function audit(Request $r, ExtraTraining $row, string $event, array $details = []): void
    {
        $h = $row->history ?? [];
        $h[] = ['event' => $event, 'by' => $r->user()->id, 'at' => now()->toIso8601String(), ...$details];
        $row->history = $h;
        if ($row->exists) {
            $row->version++;
        }$row->save();
        DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $row->apprentice_id, 'kind' => 'extra_training', 'record_id' => $row->id, 'event' => match ($event) {
            'Actual extra training recorded' => 'recorded', 'Apprentice agreement recorded' => 'agreement_recorded', 'Employer compensation confirmation recorded' => 'compensation_recorded', 'Eligible extra OTJ approved' => 'approved', 'Extra training withdrawn' => 'withdrawn'
        }, 'created_at' => now()]);
    }

    private function pending(ExtraTraining $r): void
    {
        if ($r->withdrawn_at || $r->approval || $r->working !== 'outside') {
            throw ValidationException::withMessages(['confirmed' => 'This record does not need a new outside-hours confirmation.']);
        }
    }

    public function index(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::training($r->user(), $apprentice), 403);
        $views = ['all' => 'All records', 'pending' => 'All pending checks', 'agreement' => 'Awaiting apprentice agreement', 'compensation' => 'Awaiting employer confirmation', 'ready' => 'Ready for tutor check', 'approved' => 'Approved OTJ', 'withdrawn' => 'Withdrawn'];
        $data = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'show' => ['nullable', Rule::in(array_keys($views))], 'page' => ['nullable', 'integer', 'min:1'], 'record' => ['nullable', 'integer', 'min:1']]);
        $rows = ExtraTraining::where('apprentice_id', $apprentice->id)->orderByDesc('training_date')->orderByDesc('id')->get();
        $approvedMinutes = $rows->filter(fn ($x) => $x->approval && ! $x->withdrawn_at && $x->occurred())->sum('minutes');
        $search = trim($data['search'] ?? '');
        $show = $data['show'] ?? 'all';
        $pinned = isset($data['record']) ? $rows->firstWhere('id', (int) $data['record']) : null;
        abort_if(isset($data['record']) && ! $pinned, 404);
        $searched = $search === '' ? $rows : $rows->filter(fn ($x) => Str::contains(Str::lower($x->topic.' '.$x->provider), Str::lower($search)));
        $matches = fn ($x, $view) => match ($view) {
            'all' => true,
            'pending' => ! $x->withdrawn_at && ! $x->approval,
            default => $x->position() === $views[$view],
        };
        $counts = [];
        foreach (array_keys($views) as $view) { $counts[$view] = $searched->filter(fn ($x) => $matches($x, $view))->count(); }
        $selected = $pinned ? collect([$pinned]) : $searched->filter(fn ($x) => $matches($x, $show))->values();
        $page = min((int) ($data['page'] ?? 1), max(1, (int) ceil($selected->count() / 20)));
        $records = new LengthAwarePaginator($selected->slice(($page - 1) * 20, 20)->values(), $selected->count(), 20, $page, ['path' => $r->url(), 'query' => $r->except('page')]);
        return view('attendance.extra', ['apprentice' => $apprentice, 'records' => $records, 'write' => $this->write($r, $apprentice), 'learner' => Access::learner($r->user(), $apprentice), 'approvedMinutes' => $approvedMinutes, 'views' => $views, 'counts' => $counts, 'search' => $search, 'show' => $show, 'pinnedRecord' => $pinned?->id]);
    }

    public function create(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless($this->write($r, $apprentice), 403);
        $data = $r->validate(['training_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i', 'after:start_time'], 'excluded_minutes' => ['required', 'integer', 'min:0'], 'topic' => ['required', 'string', 'max:250'], 'provider' => ['required', 'string', 'max:250'], 'learning' => ['required', 'string', 'max:10000'], 'evidence' => ['required', 'string', 'max:10000'], 'working' => ['required', Rule::in(['normal', 'outside'])], 'file' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg,txt', 'extensions:pdf,png,jpg,jpeg,txt', 'max:5120']]);
        if (Carbon::parse($data['training_date'].' '.$data['end_time'], config('app.timezone'))->gt(now())) {
            throw ValidationException::withMessages(['end_time' => 'Record actual training after it has finished.']);
        }
        $from = Math::time($data['start_time']);
        $to = Math::time($data['end_time']);
        $minutes = $to - $from - (int) $data['excluded_minutes'];
        if ($minutes <= 0) {
            throw ValidationException::withMessages(['excluded_minutes' => 'Excluded minutes must leave positive actual training time.']);
        }
        $file = null;
        try {
            DB::transaction(function () use ($r, $apprentice, $data, $from, $to, $minutes, &$file): void {
                $a = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->write($r, $a), 403);
                if (! $a->tutor_group_id) {
                    throw ValidationException::withMessages(['topic' => 'Assign the apprentice to a recorded tutor group before recording this training.']);
                }if ($a->start_date && $data['training_date'] < $a->start_date->toDateString()) {
                    throw ValidationException::withMessages(['training_date' => 'This training predates the recorded first day in learning.']);
                }
                $row = new ExtraTraining([...collect($data)->except('file')->all(), 'apprentice_id' => $a->id, 'tutor_group_id' => $a->tutor_group_id, 'minutes' => $minutes, 'parts' => [[$from, $to]], 'recorded_by' => $r->user()->id]);
                if ($r->hasFile('file')) {
                    $file = PrivateEvidence::store($r->file('file'), 'extra-training-evidence', $r->user()->id);
                    $row->file = $file;
                }$this->audit($r, $row, 'Actual extra training recorded');
            });
        } catch (\Throwable $e) {
            if ($file) {
                PrivateEvidence::delete($file);
            }throw $e;
        }

        return back()->with('status', 'Actual extra training saved pending checks. No OTJ minutes were credited.');
    }

    public function agreement(Request $r, ExtraTraining $extra): RedirectResponse
    {
        abort_unless($this->write($r, $extra->apprentice) || Access::learner($r->user(), $extra->apprentice), 403);
        $staff = $this->write($r, $extra->apprentice);
        $data = $r->validate(['version' => ['required', 'integer'], 'confirmed' => ['accepted'], 'source' => $staff ? ['required', Rule::in(self::AGREEMENT_SOURCES)] : ['prohibited'], 'reference' => $staff ? ['required', 'string', 'max:2000'] : ['prohibited']]);
        DB::transaction(function () use ($r, $extra, $data, $staff): void {
            $row = $this->locked($r, $extra, true);
            $this->pending($row);
            if ($row->agreement) {
                throw ValidationException::withMessages(['confirmed' => 'Apprentice agreement is already recorded.']);
            }$row->agreement = ['by' => $r->user()->id, 'at' => now()->toIso8601String(), 'reported' => $staff, 'source' => $staff ? $data['source'] : 'Apprentice portal confirmation', 'reference' => $data['reference'] ?? null];
            $this->audit($r, $row, 'Apprentice agreement recorded');
        });

        return back()->with('status', 'Apprentice agreement recorded. Employer compensation and tutor eligibility remain separate.');
    }

    public function compensation(Request $r, ExtraTraining $extra): RedirectResponse
    {
        abort_unless($this->write($r, $extra->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'method' => ['required', Rule::in(['paid', 'toil'])], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'covered' => ['required', 'integer', 'min:1', 'max:1440'], 'employer_name' => ['required', 'string', 'max:250'], 'source' => ['required', Rule::in(self::COMPENSATION_SOURCES)], 'reference' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($r, $extra, $data): void {
            $row = $this->locked($r, $extra);
            $this->pending($row);
            $before = $row->compensation;
            $row->compensation = [...collect($data)->except('version')->all(), 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $this->audit($r, $row, 'Employer compensation confirmation recorded', ['before' => $before]);
        });

        return back()->with('status', 'Evidence of compensation actually received recorded. Future or insufficient compensation does not qualify for tutor approval.');
    }

    public function approve(Request $r, ExtraTraining $extra): RedirectResponse
    {
        abort_unless($this->write($r, $extra->apprentice), 403);
        $r->validate(['version' => ['required', 'integer'], 'eligible' => ['accepted']]);
        DB::transaction(function () use ($r, $extra): void {
            $row = $this->locked($r, $extra);
            if ($row->position() !== 'Ready for tutor check' || ! $row->occurred()) {
                throw ValidationException::withMessages(['eligible' => 'Complete all pending checks and record actual training before tutor approval.']);
            }
            $normal = AttendanceMark::with('session')->where('apprentice_id', $row->apprentice_id)->where('otj_confirmed', true)->where('otj_minutes', '>', 0)->whereHas('session', fn ($q) => $q->whereDate('session_date', $row->training_date)->where('state', '!=', 'Cancelled'))->get();
            foreach ($normal as $entry) {
                if (Math::overlap($row->parts, $entry->parts ?? [])) {
                    throw ValidationException::withMessages(['eligible' => 'This time overlaps confirmed session OTJ. Do not count it twice.']);
                }
            }
            $others = ExtraTraining::where('apprentice_id', $row->apprentice_id)->whereDate('training_date', $row->training_date)->whereNull('withdrawn_at')->whereNotNull('approval')->where('id', '!=', $row->id)->get();
            foreach ($others as $entry) {
                if (Math::overlap($row->parts, $entry->parts)) {
                    throw ValidationException::withMessages(['eligible' => 'This time overlaps another approved extra-training record.']);
                }
            }
            $row->approval = ['by' => $r->user()->id, 'at' => now()->toIso8601String(), 'eligible' => true];
            $this->audit($r, $row, 'Eligible extra OTJ approved');
        });

        return back()->with('status', 'Eligible extra-training minutes approved. This remains a recorded subtotal, not proof of the whole individual target or funding compliance.');
    }

    public function withdraw(Request $r, ExtraTraining $extra): RedirectResponse
    {
        abort_unless($this->write($r, $extra->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:5000']]);
        DB::transaction(function () use ($r, $extra, $data): void {
            $row = $this->locked($r, $extra);
            if ($row->withdrawn_at) {
                throw ValidationException::withMessages(['reason' => 'This record is already withdrawn.']);
            }$row->withdrawn_at = now();
            $row->withdrawal_reason = $data['reason'];
            $this->audit($r, $row, 'Extra training withdrawn', ['reason' => $data['reason']]);
        });

        return back()->with('status', 'Record withdrawn from confirmed OTJ totals. Original details and approval history remain.');
    }

    public function download(Request $r, ExtraTraining $extra): StreamedResponse
    {
        abort_unless(Access::training($r->user(), $extra->apprentice), 403);
        abort_unless($extra->file && PrivateEvidence::exists($extra->file), 404);

        return PrivateEvidence::download($extra->file);
    }
}
