<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\SupportConcern;
use App\Models\SurveyReceipt;
use App\Support\LearningAccess as Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SurveyReceiptController extends Controller
{
    public const TYPES = ['induction' => 'Getting to know you', 'sixweek' => 'New Apprentice Wellbeing and Support Survey', 'ongoing' => 'Apprentice Wellbeing and Support Survey'];

    private function authorised(Request $r): void
    {
        abort_unless(Access::manager($r->user()) || in_array($r->user()->role, ['inclusion_lead', 'inclusion_member'], true), 403);
    }

    public function index(Request $r, Apprentice $apprentice): View
    {
        $this->authorised($r);
        $rows = SurveyReceipt::where('apprentice_id', $apprentice->id)->orderByDesc('submitted_date')->get();
        $rows = $rows->filter(fn ($row) => match ($r->query('show')) {
            'unchecked' => ! $row->currentCheck(),'followup' => ($row->currentCheck()['followup'] ?? null) === 'Required',default => true
        });

        return view('surveys.index', ['apprentice' => $apprentice, 'receipts' => $rows, 'concerns' => SupportConcern::where('apprentice_id', $apprentice->id)->get()]);
    }

    public function save(Request $r, Apprentice $apprentice, ?SurveyReceipt $receipt = null): RedirectResponse
    {
        $this->authorised($r);
        if ($receipt) {
            abort_unless($receipt->apprentice_id === $apprentice->id, 404);
        }$data = $r->validate(['kind' => ['required', Rule::in(array_keys(self::TYPES))], 'submitted_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'version' => $receipt ? ['required', 'integer'] : ['prohibited']]);
        DB::transaction(function () use ($r, $apprentice, $receipt, $data): void {
            Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            $row = $receipt ? SurveyReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail() : new SurveyReceipt(['apprentice_id' => $apprentice->id, 'recorded_by' => $r->user()->id]);
            if ($row->exists) {
                $this->version($r, $row);
                if ($row->kind !== $data['kind']) {
                    throw ValidationException::withMessages(['kind' => 'Keep the original survey type.']);
                }
            }if (SurveyReceipt::where('apprentice_id', $apprentice->id)->where('kind', $data['kind'])->whereDate('submitted_date', $data['submitted_date'])->when($row->exists, fn ($q) => $q->where('id', '!=', $row->id))->exists()) {
                throw ValidationException::withMessages(['submitted_date' => 'This survey/date already has a receipt. Open the original record.']);
            }$h = $row->history ?? [];
            $h[] = ['event' => 'Receipt saved', 'before_date' => $row->submitted_date?->toDateString(), 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $row->kind = $data['kind'];
            if ($row->exists && $row->submitted_date?->toDateString() !== $data['submitted_date']) {
                $row->receipt_revision++;
            }$row->submitted_date = $data['submitted_date'];
            $row->history = $h;
            if ($row->exists) {
                $row->version++;
            }$row->save();
            $this->audit($r, $row, 'survey_receipt');
        });

        return back()->with('status', 'Survey receipt recorded. No answers, health information or safeguarding details were stored.');
    }

    public function check(Request $r, SurveyReceipt $receipt): RedirectResponse
    {
        $this->authorised($r);
        $data = $r->validate(['version' => ['required', 'integer'], 'checked_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'followup' => ['required', Rule::in(['Required', 'Not required'])], 'concern_id' => ['nullable', 'integer', Rule::exists('support_concerns', 'id')->where('apprentice_id', $receipt->apprentice_id)]]);
        DB::transaction(function () use ($r, $receipt, $data): void {
            $row = SurveyReceipt::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $row);
            if ($data['checked_date'] < $row->submitted_date->toDateString()) {
                throw ValidationException::withMessages(['checked_date' => 'The check cannot precede submission.']);
            }if ($data['followup'] === 'Not required' && filled($data['concern_id'] ?? null)) {
                throw ValidationException::withMessages(['concern_id' => 'Clear the concern link or select Follow-up required.']);
            }$checks = $row->checks ?? [];
            $checks[] = [...collect($data)->except('version')->all(), 'receipt_date' => $row->submitted_date->toDateString(), 'receipt_revision' => $row->receipt_revision, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $row->checks = $checks;
            $row->version++;
            $row->save();
            $this->audit($r, $row, 'survey_inclusion_check');
        });

        return back()->with('status', 'Inclusion check saved. Any concern actions remain in the original concern record.');
    }

    private function version(Request $r, SurveyReceipt $row): void
    {
        if ($row->version !== $r->integer('version')) {
            throw ValidationException::withMessages(['version' => 'This survey receipt changed. Reopen before saving.']);
        }
    }

    private function audit(Request $r, SurveyReceipt $row, string $event): void
    {
        DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $row->apprentice_id, 'kind' => 'survey', 'record_id' => $row->id, 'event' => $event, 'created_at' => now()]);
    }
}
