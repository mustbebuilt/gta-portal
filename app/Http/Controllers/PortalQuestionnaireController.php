<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\PortalQuestionnaireAssignment as Assignment;
use App\Models\PortalQuestionnaireForm as Form;
use App\Models\SupportConcern;
use App\Support\LearningAccess;
use App\Support\PortalQuestionnaires as Q;
use App\Support\SupportAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PortalQuestionnaireController extends Controller
{
    private function authVersion(Request $r): int
    {
        return (int) $r->session()->get('auth_version', 0);
    }

    private function page(string $view, array $data = [])
    {
        return response()->view('portal-questionnaires.'.$view, $data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    private function filter(Request $r)
    {
        $filter = $r->validate(['filter' => ['nullable', Rule::in(['all', 'open', 'overdue', 'followup', ...Q::STATES])]])['filter'] ?? 'open';
        $q = Q::queue($r->user());
        abort_if($filter === 'followup' && $r->user()->role === 'apprentice', 403);
        if ($filter === 'open') {
            $q->whereIn('state', ['Assigned', 'In progress', 'Submitted']);
        } elseif ($filter === 'overdue') {
            $q->whereIn('state', ['Assigned', 'In progress'])->whereDate('due_date', '<', today())->whereHas('form', fn ($f) => $f->where('kind', '!=', 'ongoing'));
        } elseif ($filter === 'followup') {
            $q->where('state', 'Checked')->whereHas('submissions', fn ($s) => $s->where('state', 'Checked')->where('followup', 'Required')->whereNull('followup_resolved_at'));
        } elseif ($filter !== 'all') {
            $q->where('state', $filter);
        }

        return [$q, $filter];
    }

    public function index(Request $r)
    {
        [$q,$filter] = $this->filter($r);

        return $this->page('index', ['rows' => $q->with('form', 'apprentice:id,reference,first_name,last_name')->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString(), 'counts' => Q::counts($r->user()), 'filter' => $filter, 'staff' => $r->user()->role !== 'apprentice', 'author' => Q::author($r->user())]);
    }

    public function forms(Request $r, ?Form $form = null)
    {
        abort_unless(Q::author($r->user()), 403);

        return $this->page('forms', ['form' => $form ?? new Form, 'forms' => Form::orderByDesc('created_at')->orderByDesc('id')->paginate(20), 'manager' => LearningAccess::manager($r->user())]);
    }

    public function saveForm(Request $r, ?Form $form = null)
    {
        abort_unless(Q::author($r->user()), 403);
        $data = $r->validate(['version' => $form ? ['required', 'integer', 'min:1'] : ['prohibited'], 'kind' => ['required', Rule::in(array_keys(SurveyReceiptController::TYPES))], 'title' => ['required', 'string', 'max:180'], 'introduction' => ['required', 'string', 'max:4000'], 'privacy_notice' => ['required', 'string', 'max:6000'], 'questions' => ['required', 'array', 'min:1', 'max:30'], 'questions.*.key' => ['required', 'string', 'max:40'], 'questions.*.label' => ['nullable', 'string', 'max:500'], 'questions.*.type' => ['required', Rule::in(['text', 'choice'])], 'questions.*.required' => ['nullable', 'boolean'], 'questions.*.options_text' => ['nullable', 'string', 'max:2200']]);
        $qs = [];
        foreach ($data['questions'] as $q) {
            if (! filled($q['label'] ?? null)) {
                continue;
            }$qs[] = ['key' => $q['key'], 'label' => $q['label'], 'type' => $q['type'], 'required' => (bool) ($q['required'] ?? false), 'options' => filled($q['options_text'] ?? null) ? array_map('trim', preg_split('/\r?\n/', $q['options_text'])) : []];
        }$data['questions'] = $qs;
        $saved = Q::saveForm($r->user(), $this->authVersion($r), $form, $data);

        return to_route('portal-questionnaires.form', $saved)->with('status', 'Draft saved. A different administrator or CEO must check it before publication.');
    }

    public function formAction(Request $r, Form $form)
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['publish', 'retire', 'revise'])]]);
        $f = Q::formAction($r->user(), $this->authVersion($r), $form, $d['version'], $d['action']);

        return to_route('portal-questionnaires.form', $f)->with('status', 'Form revision updated. Existing assignments keep their original form.');
    }

    public function assignmentForm(Request $r)
    {
        abort_unless(SupportAccess::team($r->user()), 403);

        return $this->page('assign', ['forms' => Form::where('state', 'Published')->orderBy('title')->get()->filter(fn ($f) => Q::intact($f)), 'apprentices' => Apprentice::whereIn('id', SupportAccess::ids($r->user()))->orderBy('last_name')->orderBy('id')->get()]);
    }

    public function assign(Request $r)
    {
        abort_unless(SupportAccess::team($r->user()), 403);
        $d = $r->validate(['form_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'], 'cycle_code' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D'], 'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'], 'apprentice_ids' => ['required', 'array', 'min:1', 'max:100'], 'apprentice_ids.*' => ['required', 'integer', 'distinct']]);
        $result = Q::assign($r->user(), $this->authVersion($r), Form::findOrFail($d['form_id']), $d);

        return to_route('portal-questionnaires.index')->with('status', count($result['created']).' assignment(s) created; '.count($result['existing_apprentices']).' already recorded for this form series/cycle. No messages were sent.');
    }

    public function show(Request $r, Assignment $assignment)
    {
        abort_unless(Q::visible($r->user(), $assignment), 403);
        $f = $assignment->form;
        abort_unless(Q::intact($f), 409, 'This form needs checking by GTA.');
        $staff = $r->user()->role !== 'apprentice';
        $subs = $assignment->submissions()->orderByDesc('revision')->get();
        foreach ($subs as $s) {
            abort_unless(Q::submissionIntact($s, $assignment, $f), 409, 'This response record needs checking by GTA.');
        }
        try {
            $answers = $staff ? [] : (Q::draftAnswers($assignment, $f) ?? ($subs->first()?->answer_payload['answers'] ?? []));
        } catch (\Throwable) {
            abort(409, 'Your saved draft needs checking by GTA.');
        }

        $events = collect();
        if ($staff) {
            $events = DB::table('portal_questionnaire_events as e')->join('users as u', 'u.id', '=', 'e.actor_id')
                ->where('e.assignment_id', $assignment->id)->orderBy('e.id')->select('e.event', 'e.record_version', 'e.created_at', 'e.private_reason', 'u.name as actor_name')->get();
            foreach ($events as $event) {
                try {
                    $event->private_reason = $event->private_reason === null ? null : decrypt($event->private_reason);
                } catch (\Throwable) {
                    abort(409, 'This private audit record needs checking by GTA.');
                }
            }
        }

        return $this->page('show', ['events' => $events, 'assignment' => $assignment, 'form' => $f, 'staff' => $staff, 'submissions' => $subs, 'answers' => $answers, 'concerns' => $staff ? SupportConcern::where('apprentice_id', $assignment->apprentice_id)->orderBy('id')->get() : collect()]);
    }

    public function respond(Request $r, Assignment $assignment)
    {
        abort_unless($r->user()->role === 'apprentice' && Q::visible($r->user(), $assignment), 403);
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['save', 'submit'])], 'answers' => ['nullable', 'array', 'max:30'], 'answers.*' => ['nullable', 'string', 'max:5000'], 'confirm' => ['nullable', 'boolean']]);
        // Empty HTML text fields become null through middleware; canonicalise them to blank answers.
        $d['answers'] = array_map(fn ($v) => $v ?? '', $d['answers'] ?? []);
        Q::respond($r->user(), $this->authVersion($r), $assignment, $d, $d['action'] === 'submit');

        return to_route('portal-questionnaires.show', $assignment)->with('status', $d['action'] === 'submit' ? 'Submitted. Your answers are waiting for an Inclusion Team check.' : 'Draft saved. The Inclusion Team can see its status, but cannot read your unfinished answers.');
    }

    public function review(Request $r, Assignment $assignment)
    {
        abort_unless(SupportAccess::team($r->user(), Apprentice::findOrFail($assignment->apprentice_id)), 403);
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['check', 'return'])], 'followup' => ['nullable', Rule::in(['Required', 'Not required'])], 'concern_id' => ['nullable', 'integer'], 'private_note' => ['nullable', 'string', 'max:4000'], 'shared_note' => ['nullable', 'string', 'max:2000']]);
        Q::review($r->user(), $this->authVersion($r), $assignment, $d);

        return to_route('portal-questionnaires.show', $assignment)->with('status', 'Inclusion response recorded. Follow-up actions remain in the support record.');
    }

    public function cancel(Request $r, Assignment $assignment)
    {
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:2000']]);
        Q::cancel($r->user(), $this->authVersion($r), $assignment, $d['version'], $d['reason']);

        return to_route('portal-questionnaires.show', $assignment)->with('status', 'Assignment cancelled. Its history is retained.');
    }

    public function followup(Request $r, Assignment $assignment)
    {
        abort_unless(SupportAccess::team($r->user(), Apprentice::findOrFail($assignment->apprentice_id)), 403);
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'action' => ['required', Rule::in(['resolve', 'reopen'])], 'note' => ['required', 'string', 'max:4000']]);
        Q::followup($r->user(), $this->authVersion($r), $assignment, $d);

        return to_route('portal-questionnaires.show', $assignment)->with('status', 'Follow-up outcome recorded privately. Submitted answers and the original check remain unchanged.');
    }

    public function export(Request $r)
    {
        abort_unless(SupportAccess::team($r->user()), 403);
        [$q,$filter] = $this->filter($r);
        if ((clone $q)->count() > 5000) {
            throw ValidationException::withMessages(['filter' => 'More than 5,000 assignments. Narrow the filter before downloading.']);
        }
        $rows = $q->with('form:id,title,kind,revision', 'apprentice:id,reference')->orderBy('id')->get();
        // No answers, names, email/mobile, private/shared comments, concern IDs or form questions exported.
        $clean = fn ($v) => preg_match('/^[\s]*[=+@\-]/u', (string) $v) ? "'".(string) $v : (string) $v;

        return response()->streamDownload(function () use ($rows, $clean): void {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['Assignment reference', 'Apprentice reference', 'Form title', 'Form revision', 'Cycle', 'State', 'Due', 'Submitted'], ',', '"', '');
            foreach ($rows as $x) {
                fputcsv($h, array_map($clean, [$x->id, $x->apprentice->reference, $x->form->title, $x->form->revision, $x->cycle_code, $x->state, $x->due_date?->toDateString(), $x->submitted_at?->toIso8601String()]), ',', '"', '');
            }fclose($h);
        }, 'GTA_questionnaire_status.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
