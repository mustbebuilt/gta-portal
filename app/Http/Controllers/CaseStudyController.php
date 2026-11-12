<?php

namespace App\Http\Controllers;

use App\Models\CaseStudy;
use App\Support\LearningAccess as Access;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CaseStudyController extends Controller
{
    private function config(): array
    {
        return json_decode(file_get_contents(resource_path('data/case-study-fields.json')), true);
    }

    private function allowed(Request $r, ?CaseStudy $case = null): bool
    {
        return $r->user()->role !== 'apprentice' && (! $case || Access::manager($r->user()) || $case->author_id === $r->user()->id);
    }

    public function index(Request $r): View
    {
        abort_unless($this->allowed($r), 403);
        $cases = CaseStudy::when(! Access::manager($r->user()), fn ($q) => $q->where('author_id', $r->user()->id))->orderByDesc('updated_at')->get();
        if ($r->filled('status')) {
            $cases = $cases->where('status', $r->query('status'));
        }

return view('cases.index', compact('cases'));
    }

    public function form(Request $r, ?CaseStudy $case = null): View
    {
        abort_unless($this->allowed($r, $case), 403);

        return view('cases.form', ['case' => $case ?? new CaseStudy, 'config' => $this->config(), 'author' => ! $case || $case->author_id === $r->user()->id, 'reviewer' => $case && Access::manager($r->user()) && $case->author_id !== $r->user()->id]);
    }

    public function save(Request $r, ?CaseStudy $case = null): RedirectResponse
    {
        abort_unless($this->allowed($r, $case) && (! $case || $case->author_id === $r->user()->id), 403);
        $c = $this->config();
        $rules = ['version' => $case ? ['required', 'integer'] : ['prohibited'], 'privacy' => ['nullable', 'boolean']];
        foreach ($c['caseFields'] as $key) {
            $rules['fields.'.$key] = ['nullable', 'string', 'max:20000'];
        }$rules['fields.title'] = ['required', 'string', 'max:250'];
        $rules['fields.category'] = ['required', Rule::in($c['caseCategories'])];
        $data = $r->validate($rules);
        $saved = DB::transaction(function () use ($r, $case, $data) {
            $row = $case ? CaseStudy::whereKey($case->id)->lockForUpdate()->firstOrFail() : new CaseStudy(['author_id' => $r->user()->id]);
            if ($row->exists) {
                $this->version($r, $row);
                if ($row->status === 'For review') {
                    throw ValidationException::withMessages(['fields' => 'Withdraw the review request before editing.']);
                }
            }$history = $row->history ?? [];
            $history[] = ['event' => 'Draft saved', 'before' => $row->fields, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $row->fields = $data['fields'];
            $row->privacy = (bool) ($data['privacy'] ?? false);
            $row->status = 'Draft';
            $row->history = $history;
            if ($row->exists) {
                $row->version++;
            }$row->save();

            return $row;
        });

        return to_route('cases.show', $saved)->with('status', 'Anonymised case draft saved. It is not included in the inspection overview until separately approved.');
    }

    public function transition(Request $r, CaseStudy $case): RedirectResponse
    {
        abort_unless($this->allowed($r, $case), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'action' => ['required', Rule::in(['submit', 'withdraw', 'return', 'approve'])], 'note' => ['nullable', 'string', 'max:10000'], 'sources' => ['nullable', 'boolean'], 'privacy' => ['nullable', 'boolean'], 'balance' => ['nullable', 'boolean']]);
        DB::transaction(function () use ($r, $case, $data): void {
            $row = CaseStudy::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $row);
            $own = $row->author_id === $r->user()->id;
            $reviewer = Access::manager($r->user()) && ! $own;
            $action = $data['action'];
            if ($action === 'submit' && $own && in_array($row->status, ['Draft', 'Changes requested'], true)) {
                $this->complete($row);
                $status = 'For review';
            } elseif ($action === 'withdraw' && $own && $row->status === 'For review') {
                $status = 'Draft';
            } elseif ($action === 'return' && $reviewer && $row->status === 'For review') {
                $r->validate(['note' => ['required', 'string']]);
                $status = 'Changes requested';
            } elseif ($action === 'approve' && $reviewer && $row->status === 'For review') {
                $this->complete($row);
                $r->validate(['note' => ['required', 'string'], 'sources' => ['accepted'], 'privacy' => ['accepted'], 'balance' => ['accepted']]);
                $status = 'Approved';
            } else {
                throw ValidationException::withMessages(['action' => 'This action is not available for this account or case status.']);
            }
            $history = $row->history ?? [];
            $history[] = ['event' => $status, 'by' => $r->user()->id, 'at' => now()->toIso8601String(), 'note' => $data['note'] ?? null];
            $row->history = $history;
            $row->status = $status;
            $row->version++;
            if ($action === 'approve') {
                $copies = $row->approved_copies ?? [];
                $copies[] = ['version' => $row->version, 'fields' => $row->fields, 'reviewer_id' => $r->user()->id, 'at' => now()->toIso8601String(), 'note' => $data['note']];
                $row->approved_copies = $copies;
            }$row->save();
        });

        return back()->with('status', 'Case-study status saved. Internal approval does not independently verify evidence or authorise external sharing.');
    }

    private function complete(CaseStudy $row): void
    {
        $c = $this->config();
        $v = $row->fields;
        $missing = [];
        foreach (['area', 'scope', 'challenge', 'startingPoint', 'response', 'adaptation', 'measure', 'before', 'after', 'evidence', 'voice', 'followup', 'learning', 'limitations', 'typicality'] as $key) {
            if (! filled($v[$key] ?? null)) {
                $missing[] = $key;
            }
        }
        foreach (['area' => $c['caseAreas'], 'scope' => ['Individual', 'Group', 'Provider improvement'], 'outcome' => $c['caseOutcomes'], 'sustained' => $c['caseSustained'], 'evidenceBasis' => $c['caseEvidenceBases'], 'voiceBasis' => ['Learner account', 'Employer account', 'Both accounts', 'Not yet collected'], 'typicality' => ['Single example', 'Pattern to investigate', 'Comparable cases evidenced']] as $key => $list) {
            if (! in_array($v[$key] ?? null, $list, true)) {
                $missing[] = $key;
            }
        }
        foreach (['beforeDate', 'afterDate'] as $key) {
            $d = $v[$key] ?? '';
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || ! checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) || $d > today()->toDateString()) {
                $missing[] = $key;
            }
        }if (($v['beforeDate'] ?? '') > ($v['afterDate'] ?? '')) {
            $missing[] = 'date order';
        }if (! $row->privacy) {
            $missing[] = 'anonymisation confirmation';
        }if (($v['typicality'] ?? null) === 'Comparable cases evidenced' && ! filled($v['patternEvidence'] ?? null)) {
            $missing[] = 'comparable case references';
        }
        $follow = $v['followupDate'] ?? null;
        if (($v['sustained'] ?? null) === 'Not yet checked' || in_array($v['outcome'] ?? null, ['Outcome not yet known', 'No improvement yet', 'Mixed outcome'], true)) {
            if (! filled($follow)) {
                $missing[] = 'follow-up date';
            }
        }
        if ($follow && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $follow) || ! checkdate((int) substr($follow, 5, 2), (int) substr($follow, 8, 2), (int) substr($follow, 0, 4)))) {
            $missing[] = 'follow-up date';
        }
        if ($missing) {
            throw ValidationException::withMessages(['fields' => 'Complete/check before review: '.implode(', ', array_unique($missing))]);
        }
    }

    private function version(Request $r,CaseStudy $row): void
    {
        if ($row->version !== $r->integer('version')) {
            throw ValidationException::withMessages(['version' => 'This case changed. Reopen it before saving.']);
        }
    }
}
