<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\MonthlyScorecard;
use App\Models\TrainingSession;
use App\Support\LearningAccess as Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MonthlyScorecardController extends Controller
{
    public const AREAS = ['engagement' => 'Attitude and engagement in learning', 'preparation' => 'Preparation and punctuality', 'respect' => 'Respect and professional conduct', 'safety' => 'Safe working and PPE', 'instructions' => 'Following agreed instructions and managing distractions'];

    public function index(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::training($r->user(), $apprentice), 403);
        $month = $r->query('month', today()->format('Y-m'));
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || $month > today()->format('Y-m')) {
            throw ValidationException::withMessages(['month' => 'Choose a valid current or past calendar month.']);
        }$records = MonthlyScorecard::where('apprentice_id', $apprentice->id)->orderBy('month')->get();

        return view('scorecards.index', ['apprentice' => $apprentice, 'month' => $month, 'record' => $records->firstWhere('month', $month), 'records' => $records, 'write' => Access::manager($r->user()) || Access::tutor($r->user(), $apprentice)]);
    }

    public function save(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(Access::manager($r->user()) || Access::tutor($r->user(), $apprentice), 403);
        $rules = ['version' => ['required', 'integer'], 'month' => ['required', 'date_format:Y-m'], 'training_session_id' => ['nullable', 'integer'], 'comment' => ['nullable', 'string', 'max:5000'], 'observed' => ['accepted']];
        foreach (self::AREAS as $key => $label) {
            $rules['scores.'.$key] = ['nullable', 'integer', 'between:0,5'];
        }$data = $r->validate($rules);
        if ($apprentice->start_date && $data['month'] < $apprentice->start_date->format('Y-m')) {
            throw ValidationException::withMessages(['month' => 'A monthly snapshot cannot precede the first day in learning.']);
        }
        if ($data['month'] > today()->format('Y-m')) {
            throw ValidationException::withMessages(['month' => 'Do not score a future month.']);
        }$scores = [];
        foreach (self::AREAS as $key => $label) {
            $scores[$key] = isset($data['scores'][$key]) ? (int) $data['scores'][$key] : null;
        }if ((! count(array_filter($scores, fn ($v) => $v !== null)) || count(array_filter($scores, fn ($v) => $v !== null && $v <= 1))) && ! filled($data['comment'] ?? null)) {
            throw ValidationException::withMessages(['comment' => 'Briefly explain low scores or why there was not enough observed evidence.']);
        }
        if (filled($data['training_session_id'] ?? null)) {
            $s = TrainingSession::find($data['training_session_id']);
            if (! $s || $s->state === 'Cancelled' || ! $s->ended() || ! in_array($apprentice->id, $s->members, true) || $s->session_date->format('Y-m') !== $data['month']) {
                throw ValidationException::withMessages(['training_session_id' => 'Choose an original register containing this apprentice in this calendar month.']);
            }
        }
        DB::transaction(function () use ($r, $apprentice, $data, $scores): void {
            Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            $row = MonthlyScorecard::where('apprentice_id', $apprentice->id)->where('month', $data['month'])->lockForUpdate()->first();
            if ($r->integer('version') !== ($row?->version ?? 0)) {
                throw ValidationException::withMessages(['version' => 'This monthly snapshot changed. Reopen before saving.']);
            }$row ??= new MonthlyScorecard(['apprentice_id' => $apprentice->id, 'month' => $data['month'], 'tutor_id' => $r->user()->id]);
            $h = $row->history ?? [];
            $h[] = ['before' => $row->only('scores', 'comment', 'tutor_id', 'training_session_id'), 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $row->scores = $scores;
            $row->comment = $data['comment'] ?? null;
            $row->training_session_id = $data['training_session_id'] ?? null;
            $row->tutor_id = $r->user()->id;
            $row->history = $h;
            if ($row->exists) {
                $row->version++;
            }$row->save();
            DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $apprentice->id, 'kind' => 'monthly_snapshot', 'record_id' => $row->id, 'event' => 'observed_snapshot_saved', 'created_at' => now()]);
        });

        return back()->with('status', 'Monthly learning snapshot saved. No overall behaviour grade, disciplinary decision or apprentice ranking was generated.');
    }
}
