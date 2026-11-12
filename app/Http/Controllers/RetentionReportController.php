<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\ProgrammeOutcome;
use App\Models\SupportConcern;
use App\Support\LearningAccess as Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RetentionReportController extends Controller
{
    private function choices(): array
    {
        return json_decode(file_get_contents(resource_path('data/retention-choices.json')), true);
    }

    public function edit(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::manager($r->user()), 403);

        return view('retention.edit', ['apprentice' => $apprentice, 'outcome' => ProgrammeOutcome::where('apprentice_id', $apprentice->id)->first(), 'choices' => $this->choices(), 'employers' => Employer::orderBy('name')->get()]);
    }

    public function save(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(Access::manager($r->user()), 403);
        $choices = $this->choices();
        $rules = ['version' => ['required', 'integer']];
        foreach (['status', 'achievement', 'reason', 'reasonBasis', 'voice', 'destination', 'destinationBasis', 'age', 'size', 'maths', 'english', 'additional', 'ehcp'] as $key) {
            $list = in_array($key, ['maths', 'english', 'additional', 'ehcp'], true) ? $choices['flag'] : $choices[$key];
            $rules['context.'.$key] = ['sometimes', 'required', Rule::in($list)];
        }foreach (['endDate', 'achievementDate', 'destinationDate', 'sizeDate'] as $key) {
            $rules['context.'.$key] = ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'];
        }foreach (['sourceRef', 'contextRef', 'destinationRef'] as $key) {
            $rules['context.'.$key] = ['nullable', 'string', 'max:1000'];
        }$rules['context.sizeEmployerId'] = ['nullable', 'integer', Rule::exists('employers', 'id')];
        $data = $r->validate($rules);
        $v = $data['context'] ?? [];
        $v = array_merge(['status' => 'Unknown', 'achievement' => 'Unknown', 'reason' => 'Not established', 'reasonBasis' => 'Not confirmed', 'voice' => 'Unknown', 'destination' => 'Unknown', 'destinationBasis' => 'Unknown', 'age' => 'Unknown', 'size' => 'Unknown', 'maths' => 'Unknown', 'english' => 'Unknown', 'additional' => 'Unknown', 'ehcp' => 'Unknown'], $v);
        foreach ($v as $key => $value) {
            if ($value === null && ! in_array($key, ['endDate', 'achievementDate', 'destinationDate', 'sizeDate', 'sizeEmployerId', 'sourceRef', 'contextRef', 'destinationRef'], true)) {
                $v[$key] = 'Unknown';
            }
        }
        $ended = in_array($v['status'], ['Completed', 'Withdrawn', 'Transferred'], true);
        if ($ended && (! filled($v['endDate'] ?? null) || ! $apprentice->start_date || $v['endDate'] < $apprentice->start_date->toDateString())) {
            throw ValidationException::withMessages(['context.endDate' => 'Record a valid end date on or after the known first learning day.']);
        }
        if (! $ended && filled($v['endDate'] ?? null)) {
            throw ValidationException::withMessages(['context.endDate' => 'An active programme or break has no programme end date.']);
        }
        if ($v['status'] !== 'Unknown' && ! filled($v['sourceRef'] ?? null)) {
            throw ValidationException::withMessages(['context.sourceRef' => 'Record the source for the programme status.']);
        }
        if ($v['status'] !== 'Completed' && ($v['achievement'] !== 'Unknown' || filled($v['achievementDate'] ?? null))) {
            throw ValidationException::withMessages(['context.achievement' => 'Programme completion and confirmed achievement must be recorded separately.']);
        }
        if ($v['achievement'] === 'Achieved' && (! filled($v['achievementDate'] ?? null) || $v['achievementDate'] < ($v['endDate'] ?? ''))) {
            throw ValidationException::withMessages(['context.achievementDate' => 'Record confirmed achievement on or after the programme end date.']);
        }
        if ($v['achievement'] !== 'Achieved' && filled($v['achievementDate'] ?? null)) {
            throw ValidationException::withMessages(['context.achievementDate' => 'Only confirmed achievement has an achievement date.']);
        }
        if ($v['status'] !== 'Withdrawn' && ($v['reason'] !== 'Not established' || $v['reasonBasis'] !== 'Not confirmed')) {
            throw ValidationException::withMessages(['context.reason' => 'Leaving reasons belong to a withdrawal record.']);
        }
        if ($v['reason'] !== 'Not established' && $v['reasonBasis'] === 'Not confirmed') {
            throw ValidationException::withMessages(['context.reasonBasis' => 'Record whose account supports the reason; do not treat assumptions as confirmed.']);
        }
        if ($v['ehcp'] === 'Yes' && $v['additional'] === 'No') {
            throw ValidationException::withMessages(['context.additional' => 'Check this context: an EHC plan cannot be recorded with no additional needs.']);
        }
        if (count(array_filter(['age', 'maths', 'english', 'additional', 'ehcp'], fn ($k) => $v[$k] !== 'Unknown')) && ! filled($v['contextRef'] ?? null)) {
            throw ValidationException::withMessages(['context.contextRef' => 'Record the existing source for this reporting context.']);
        }
        if ($v['size'] !== 'Unknown' && (! filled($v['sizeDate'] ?? null) || ! filled($v['sizeEmployerId'] ?? null) || ! filled($v['contextRef'] ?? null))) {
            throw ValidationException::withMessages(['context.size' => 'Headcount band needs its reference date, employer and source.']);
        }
        if ($v['size'] === 'Unknown' && (filled($v['sizeDate'] ?? null) || filled($v['sizeEmployerId'] ?? null))) {
            throw ValidationException::withMessages(['context.size' => 'Clear employer size details when the band is unknown.']);
        }
        if ($v['destination'] !== 'Unknown' && (! $ended || ! filled($v['destinationDate'] ?? null) || $v['destinationDate'] < $v['endDate'] || ! filled($v['destinationRef'] ?? null) || $v['destinationBasis'] === 'Unknown')) {
            throw ValidationException::withMessages(['context.destination' => 'A destination needs a dated source after the programme ended and a reported/verified basis.']);
        }
        if ($v['destination'] === 'Unknown' && (filled($v['destinationDate'] ?? null) || filled($v['destinationRef'] ?? null) || $v['destinationBasis'] !== 'Unknown')) {
            throw ValidationException::withMessages(['context.destination' => 'Clear destination details when it is unknown.']);
        }
        DB::transaction(function () use ($r, $apprentice, $v): void {
            Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            $row = ProgrammeOutcome::where('apprentice_id', $apprentice->id)->lockForUpdate()->first();
            if ($r->integer('version') !== ($row?->version ?? 0)) {
                throw ValidationException::withMessages(['version' => 'This outcome/context record changed. Reopen before saving.']);
            }$row ??= new ProgrammeOutcome(['apprentice_id' => $apprentice->id]);
            $h = $row->history ?? [];
            $h[] = ['before' => $row->context, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $row->context = $v;
            $row->history = $h;
            if ($row->exists) {
                $row->version++;
            }$row->save();
            DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $apprentice->id, 'kind' => 'outcome_context', 'record_id' => $row->id, 'event' => 'context_recorded', 'created_at' => now()]);
        });

        return back()->with('status', 'Reporting context saved. The live ILR, qualification results and training plan were not changed.');
    }

    public function report(Request $r): View
    {
        abort_unless(Access::manager($r->user()), 403);
        $r->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'factor' => ['nullable', Rule::in(['programme', 'age', 'size', 'maths', 'english', 'additional', 'ehcp', 'reason'])]]);
        $apprentices = Apprentice::with(['programmeRecord', 'employerRecord'])->orderBy('last_name')->get();
        $contexts = ProgrammeOutcome::all()->keyBy('apprentice_id');
        $factor = $r->query('factor', 'programme');
        $caseCounts = SupportConcern::selectRaw('apprentice_id,COUNT(*) AS total')->groupBy('apprentice_id')->pluck('total', 'apprentice_id');
        $rows = [];
        $excluded = 0;
        foreach ($apprentices as $a) {
            if (($r->filled('from') || $r->filled('to')) && (! $a->start_date || ($r->filled('from') && $a->start_date->toDateString() < $r->query('from')) || ($r->filled('to') && $a->start_date->toDateString() > $r->query('to')))) {
                $excluded++;

                continue;
            }$c = $contexts->get($a->id)?->context ?? [];
            $status = $c['status'] ?? 'Unknown';
            $row = ['apprentice' => $a, 'status' => $status, 'achievement' => $c['achievement'] ?? 'Unknown', 'factor' => $factor === 'programme' ? ($a->programmeLabel() ?? 'Unknown') : ($c[$factor] ?? 'Unknown'), 'reason' => $status === 'Withdrawn' ? ($c['reason'] ?? 'Not established') : 'Not applicable', 'reason_basis' => $c['reasonBasis'] ?? 'Not confirmed', 'employers' => 'Unknown — full history not connected', 'tutors' => 'Unknown — full history not connected', 'concern_cases' => (int) ($caseCounts[$a->id] ?? 0), 'concern_days' => null];
            $rows[] = $row;
        }
        $groups = collect($rows)->groupBy('factor')->map(fn ($g) => ['total' => $g->count(), 'completed' => $g->where('status', 'Completed')->count(), 'withdrawn' => $g->where('status', 'Withdrawn')->count(), 'achieved' => $g->where('achievement', 'Achieved')->count(), 'unknown' => $g->where('status', 'Unknown')->count()]);

        return view('retention.report',['rows' => $rows, 'groups' => $groups, 'factor' => $factor, 'excluded' => $excluded]);
    }
}
