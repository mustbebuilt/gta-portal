<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\SupportConcern;
use App\Models\SupportPlan;
use App\Models\TeachingInstruction;
use App\Models\User;
use App\Support\ExecutiveAccess;
use App\Support\LearningAccess as Access;
use App\Support\MarkerHistory;
use App\Support\PortalPdf;
use App\Support\PrivateEvidence;
use App\Support\SupportAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportController extends Controller
{
    private function team(Request $r, ?Apprentice $apprentice = null): bool
    {
        return SupportAccess::team($r->user(), $apprentice);
    }

    private function lead(Request $r, ?Apprentice $apprentice = null): bool
    {
        return SupportAccess::lead($r->user(), $apprentice);
    }

    private function raiseAllowed(Request $r, Apprentice $a): bool
    {
        return $this->team($r, $a) || Access::tutor($r->user(), $a) || Access::subject($r->user(), $a, 'maths') || Access::subject($r->user(), $a, 'english') || ($r->user()->role === 'mentor' && $a->mentor_id === $r->user()->id) || ($r->user()->role === 'careers_adviser' && $a->careers_adviser_id === $r->user()->id);
    }

    private function codes(): array
    {
        return json_decode(file_get_contents(resource_path('data/inclusion-markers.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function recipients(Apprentice $a): array
    {
        return User::whereIn('id', array_filter([$a->tutor_id, $a->maths_tutor_id, $a->english_tutor_id]))->where('active', true)->get()->filter(fn ($u) => Access::tutor($u, $a) || ($u->role === 'functional_skills_tutor' && (Access::subject($u, $a, 'maths') || Access::subject($u, $a, 'english'))))->pluck('id')->all();
    }

    public function worklist(Request $r): View
    {
        abort_unless($this->team($r), 403);
        $q = SupportConcern::with(['apprentice', 'owner'])->whereIn('apprentice_id', SupportAccess::ids($r->user()));
        if ($r->query('state') === 'new') {
            $q->whereNull('acknowledged_at');
        } elseif ($r->query('state') === 'overdue') {
            $q->where('status', '!=', 'Closed')->whereDate('action_due', '<', today());
        } elseif ($r->query('state') === 'weekly') {
            $q->where('status', '!=', 'Closed')->where(fn ($q) => $q->whereNull('next_review')->orWhereDate('next_review', '<=', today()));
        } else {
            $q->where('status', '!=', 'Closed');
        }

        return view('support.worklist', ['concerns' => $q->orderBy('created_at')->get(), 'codes' => $this->codes()]);
    }

    public function index(Request $r, Apprentice $apprentice): View
    {
        abort_unless($this->raiseAllowed($r, $apprentice), 403);
        $team = $this->team($r, $apprentice);
        $concerns = SupportConcern::with('owner')->where('apprentice_id', $apprentice->id)->when(! $team, fn ($q) => $q->where('raised_by', $r->user()->id))->orderByDesc('created_at')->get();

        return view('support.index', ['apprentice' => $apprentice, 'team' => $team, 'lead' => $this->lead($r, $apprentice), 'concerns' => $concerns, 'codes' => $this->codes(), 'markers' => $team ? DB::table('apprentice_markers')->where('apprentice_id', $apprentice->id)->pluck('code')->all() : [], 'markerHistory' => $team ? DB::table('marker_change_events')->where('apprentice_id', $apprentice->id)->orderByDesc('id')->get() : collect(), 'owners' => $team ? SupportAccess::owners($apprentice) : collect(), 'instructionWriter' => SupportAccess::writer($r->user(), $apprentice) || ExecutiveAccess::enabled($r), 'instructionApprover' => SupportAccess::approver($r->user(), $apprentice) || ExecutiveAccess::enabled($r), 'instructions' => $team ? TeachingInstruction::where('apprentice_id', $apprentice->id)->get() : collect(), 'plans' => $team ? SupportPlan::where('apprentice_id', $apprentice->id)->get() : collect(), 'recipients' => $team ? User::whereIn('id', $this->recipients($apprentice))->where('active', true)->get() : collect()]);
    }

    public function raise(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless($this->raiseAllowed($r, $apprentice), 403);
        $data = $r->validate(['initial_note' => ['required', 'string', 'max:10000'], 'code' => ['prohibited'], 'owner_id' => ['prohibited']]);
        DB::transaction(function () use ($r, $apprentice, $data): void {
            $fresh = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->raiseAllowed($r, $fresh), 403);
            $c = SupportConcern::create([...$data, 'apprentice_id' => $apprentice->id, 'raised_by' => $r->user()->id]);
            $this->audit($r, $apprentice->id, $c->id, 'concern_raised');
        });

        return back()->with('status', 'Concern raised for acknowledgement. No marker was allocated automatically.');
    }

    public function classify(Request $r, SupportConcern $concern): RedirectResponse
    {
        abort_unless($this->lead($r, $concern->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'code' => ['required', Rule::in(array_keys($this->codes()))], 'other_code' => ['nullable', 'required_if:code,IM40', 'string', 'max:250'], 'owner_id' => ['required', 'integer', Rule::in(SupportAccess::owners($concern->apprentice)->pluck('id')->all())], 'next_action' => ['required', 'string', 'max:5000'], 'action_due' => ['required', 'date_format:Y-m-d'], 'next_review' => ['required', 'date_format:Y-m-d']]);
        DB::transaction(function () use ($r, $concern, $data): void {
            $profile = Apprentice::whereKey($concern->apprentice_id)->lockForUpdate()->firstOrFail();
            $c = SupportConcern::whereKey($concern->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $c);
            if ($c->status === 'Closed') {
                throw ValidationException::withMessages(['code' => 'Closed concerns retain their history. Raise a new concern for a new episode.']);
            }$h = $c->history ?? [];
            $h[] = ['event' => 'classified', 'before' => $c->only('code', 'owner_id', 'next_action', 'action_due', 'next_review'), 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $c->history = $h;
            $c->code = $data['code'];
            $c->other_code = $data['code'] === 'IM40' ? $data['other_code'] : null;
            abort_unless(SupportAccess::team(User::findOrFail($data['owner_id']), $profile), 403);
            $c->owner_id = $data['owner_id'];
            $c->next_action = $data['next_action'];
            $c->action_due = $data['action_due'];
            $c->next_review = $data['next_review'];
            $c->acknowledged_at ??= now();
            $c->acknowledged_by ??= $r->user()->id;
            $c->status = 'Monitoring';
            $c->version++;
            $c->save();
            $beforeMarkers = MarkerHistory::codes($profile->id);
            DB::table('apprentice_markers')->insertOrIgnore(['apprentice_id' => $c->apprentice_id, 'code' => $c->code, 'recorded_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            MarkerHistory::record($profile->id, $r->user()->id, 'concern_classification', $beforeMarkers, 'Concern '.$c->id);
            $profile->version++;
            $profile->save();
            $this->audit($r, $c->apprentice_id, $c->id, 'acknowledged_classified');
        });

        return back()->with('status', 'Concern acknowledged, coded and assigned.');
    }

    public function followup(Request $r, SupportConcern $concern): RedirectResponse
    {
        abort_unless($this->team($r, $concern->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['required', 'string', 'max:10000'], 'target' => ['required', 'string', 'max:5000'], 'measure' => ['required', 'string', 'max:5000'], 'owner' => ['required', 'string', 'max:150'], 'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:date'], 'next_review' => ['required', 'date_format:Y-m-d', 'after_or_equal:date'], 'outcome' => ['required', Rule::in(['Progress made', 'More support needed', 'No progress yet', 'Target completed'])], 'agreed' => ['accepted']]);
        DB::transaction(function () use ($r, $concern, $data): void {
            $c = SupportConcern::whereKey($concern->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $c);
            if (! $c->acknowledged_at || $c->status === 'Closed') {
                throw ValidationException::withMessages(['note' => 'Acknowledge the concern before recording weekly follow-up.']);
            }$h = $c->followups ?? [];
            if ($data['date'] < $c->created_at->toDateString() || ($h && $data['date'] < end($h)['date'])) {
                throw ValidationException::withMessages(['date' => 'Use a follow-up date within this concern, not before its previous follow-up.']);
            }$h[] = [...collect($data)->except('version')->all(), 'by' => $r->user()->id, 'recorded_at' => now()->toIso8601String()];
            $c->followups = $h;
            $c->next_action = $data['target'];
            $c->action_due = $data['due_date'];
            $c->next_review = $data['next_review'];
            $c->version++;
            $c->save();
            $this->audit($r, $c->apprentice_id, $c->id, 'weekly_followup');
        });

        return back()->with('status', 'Weekly follow-up saved chronologically.');
    }

    public function close(Request $r, SupportConcern $concern): RedirectResponse
    {
        abort_unless($this->lead($r, $concern->apprentice), 403);
        $r->validate(['version' => ['required', 'integer'], 'outcome' => ['required', 'string', 'max:10000']]);
        DB::transaction(function () use ($r, $concern): void {
            $c = SupportConcern::whereKey($concern->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $c);
            if ($c->status === 'Closed') {
                throw ValidationException::withMessages(['outcome' => 'This concern is already closed.']);
            }$c->status = 'Closed';
            $c->outcome = $r->input('outcome');
            $c->closed_at = now();
            $c->version++;
            $c->save();
            $this->audit($r, $c->apprentice_id, $c->id, 'closed');
        });

        return back()->with('status', 'Concern closed; history and markers retained.');
    }

    public function markers(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless($this->lead($r, $apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'marker_reason' => ['nullable', 'string', 'max:2000'], 'codes' => ['nullable', 'array', 'max:40'], 'codes.*' => ['required', Rule::in(array_keys($this->codes()))], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $apprentice, $data): void {
            $locked = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $locked);
            $locked->version++;
            $locked->save();
            $before = MarkerHistory::codes($apprentice->id);
            $selected = array_values(array_unique($data['codes'] ?? []));
            DB::table('apprentice_markers')->where('apprentice_id', $apprentice->id)->whereNotIn('code', $selected)->delete();
            foreach (array_diff($selected, $before) as $code) {
                DB::table('apprentice_markers')->insert(['apprentice_id' => $apprentice->id, 'code' => $code, 'recorded_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            MarkerHistory::record($apprentice->id, $r->user()->id, 'manual_selection', $before, $data['marker_reason'] ?? null);
            $this->audit($r, $apprentice->id, 0, 'markers_updated');
        });

        return back()->with('status', 'Inclusion markers saved. A support-plan requirement is separate from an approved active plan.');
    }

    public function instruction(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(SupportAccess::writer($r->user(), $apprentice) || ExecutiveAccess::enabled($r), 403);
        $data = $r->validate(['draft_text' => ['required', 'string', 'max:10000'], 'private_note' => ['nullable', 'string', 'max:10000'], 'recipient_ids' => ['required', 'array', 'min:1'], 'recipient_ids.*' => ['required', 'integer', Rule::in($this->recipients($apprentice))]]);
        DB::transaction(function () use ($r, $apprentice, $data): void {
            $fresh = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless(SupportAccess::writer($r->user(), $fresh) || ExecutiveAccess::enabled($r), 403);
            $i = TeachingInstruction::create([...$data, 'recipient_ids' => array_map('intval', array_unique($data['recipient_ids'])), 'apprentice_id' => $apprentice->id]);
            $this->audit($r, $apprentice->id, $i->id, 'instruction_drafted');
        });

        return back()->with('status', 'Instruction draft saved. Tutors cannot see it until approved.');
    }

    public function amendInstruction(Request $r, TeachingInstruction $instruction): RedirectResponse
    {
        abort_unless(SupportAccess::writer($r->user(), $instruction->apprentice) || ExecutiveAccess::enabled($r), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'draft_text' => ['required', 'string', 'max:10000'], 'private_note' => ['nullable', 'string', 'max:10000'], 'recipient_ids' => ['required', 'array', 'min:1'], 'recipient_ids.*' => ['integer', Rule::in($this->recipients($instruction->apprentice))]]);
        DB::transaction(function () use ($r, $instruction, $data): void {
            $i = TeachingInstruction::whereKey($instruction->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $i);
            $h = $i->history ?? [];
            $h[] = ['event' => 'Draft amended', 'draft_revision' => $i->draft_revision, 'before' => $i->only('draft_text', 'private_note', 'recipient_ids'), 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $i->fill(collect($data)->except('version')->all());
            $i->recipient_ids = array_map('intval', array_unique($data['recipient_ids']));
            $i->draft_revision++;
            $i->version++;
            $i->history = $h;
            $i->save();
            $this->audit($r, $i->apprentice_id, $i->id, 'instruction_draft_amended');
        });

        return back()->with('status', 'Instruction draft amended. Any currently approved copy remains unchanged until a new version is approved.');
    }

    public function withdrawInstruction(Request $r, TeachingInstruction $instruction): RedirectResponse
    {
        abort_unless(SupportAccess::approver($r->user(), $instruction->apprentice) || ExecutiveAccess::enabled($r), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'release_version' => ['required', 'integer'], 'reason' => ['required', 'string', 'max:5000']]);
        DB::transaction(function () use ($r, $instruction, $data): void {
            $i = TeachingInstruction::whereKey($instruction->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $i);
            $release = $i->currentRelease();
            if (! $release || $release['version'] !== $r->integer('release_version')) {
                throw ValidationException::withMessages(['release_version' => 'The approved instruction changed or was already withdrawn. Reopen it.']);
            }
            $release['withdrawn_at'] = now()->toIso8601String();
            $release['withdrawn_by'] = $r->user()->id;
            $release['withdrawal_reason'] = $data['reason'];
            $i->release = $release;
            $h = $i->history ?? [];
            $h[] = ['event' => 'Release withdrawn', 'release' => $release, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $i->history = $h;
            $i->version++;
            $i->save();
            $this->audit($r, $i->apprentice_id, $i->id, 'instruction_withdrawn');
        });

        return back()->with('status', 'Approved instruction withdrawn. It is no longer shown to tutors; its history remains restricted.');
    }

    public function release(Request $r, TeachingInstruction $instruction): RedirectResponse
    {
        abort_unless(SupportAccess::approver($r->user(), $instruction->apprentice) || ExecutiveAccess::enabled($r), 403);
        $r->validate(['version' => ['required', 'integer'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $instruction): void {
            $i = TeachingInstruction::whereKey($instruction->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $i);
            if (! $i->hasUnreleasedDraft()) {
                throw ValidationException::withMessages(['confirmed' => 'This instruction is already approved.']);
            }$allowed = $this->recipients($i->apprentice);
            if (array_diff($i->recipient_ids, $allowed)) {
                throw ValidationException::withMessages(['confirmed' => 'A recipient assignment changed. Draft a new instruction for the current recipients.']);
            }if ($i->release) {
                $history = $i->history ?? [];
                $history[] = ['event' => 'Release replaced', 'release' => $i->release, 'acknowledgements' => $i->acknowledgements, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
                $i->history = $history;
            }
            $i->acknowledgements = [];
            $i->release = ['draft_revision' => $i->draft_revision, 'version' => $i->version, 'text' => $i->draft_text, 'recipient_ids' => $i->recipient_ids, 'approved_by' => $r->user()->id, 'approved_at' => now()->toIso8601String()];
            $i->version++;
            $i->save();
            $this->audit($r, $i->apprentice_id, $i->id, 'instruction_approved');
        });

        return back()->with('status', 'Teaching instruction approved for its named linked tutors. Private notes remain restricted.');
    }

    public function instructions(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::tutor($r->user(), $apprentice) || Access::subject($r->user(), $apprentice, 'maths') || Access::subject($r->user(), $apprentice, 'english'), 403);
        $instructions = TeachingInstruction::where('apprentice_id', $apprentice->id)->get()->filter(fn ($i) => $i->currentRelease() && in_array($r->user()->id, $i->release['recipient_ids'], true));

        return view('support.instructions', ['apprentice' => $apprentice, 'instructions' => $instructions]);
    }

    public function acknowledgeInstruction(Request $r, TeachingInstruction $instruction): RedirectResponse
    {
        $a = $instruction->apprentice;
        abort_unless((Access::tutor($r->user(), $a) || Access::subject($r->user(), $a, 'maths') || Access::subject($r->user(), $a, 'english')) && $instruction->currentRelease() && in_array($r->user()->id, $instruction->release['recipient_ids'], true), 403);
        $r->validate(['version' => ['required', 'integer'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $instruction): void {
            $i = TeachingInstruction::whereKey($instruction->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $i);
            abort_unless($i->currentRelease() && in_array($r->user()->id, $i->release['recipient_ids'], true), 403);
            $acks = $i->acknowledgements ?? [];
            $acks[(string) $r->user()->id] = now()->toIso8601String();
            $i->acknowledgements = $acks;
            $i->version++;
            $i->save();
            $this->audit($r, $i->apprentice_id, $i->id, 'instruction_read');
        });

        return back()->with('status', 'Instruction acknowledged.');
    }

    public function planPdf(Request $r, SupportPlan $plan): Response
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        abort_unless($plan->active_plan, 409, 'No approved support-plan snapshot exists.');

        return PortalPdf::response('pdf.support', ['plan' => $plan, 'codes' => $this->codes()], 'GTA-Private-ASP-'.$plan->apprentice->reference.'-'.$plan->id);
    }

    public function plan(Request $r, Apprentice $apprentice, ?SupportPlan $plan = null): View
    {
        abort_unless($this->team($r, $apprentice), 403);
        if ($plan) {
            abort_unless($plan->apprentice_id === $apprentice->id, 404);
        }

        return view('support.plan', ['apprentice' => $apprentice, 'plan' => $plan ?? new SupportPlan, 'concerns' => SupportConcern::where('apprentice_id', $apprentice->id)->whereNotNull('code')->whereNotIn('code', ['IM18', 'IM19', 'IM27', 'IM28', 'IM32', 'IM33'])->get(), 'lead' => $this->lead($r, $apprentice), 'activityStaff' => User::where('active', true)->whereNotIn('role', ['apprentice', 'employer'])->get(['id', 'name']), 'codes' => $this->codes()]);
    }

    public function savePlan(Request $r, Apprentice $apprentice, ?SupportPlan $plan = null): RedirectResponse
    {
        abort_unless($this->team($r, $apprentice), 403);
        if ($plan) {
            abort_unless($plan->apprentice_id === $apprentice->id, 404);
        }
        $data = $r->validate(['version' => $plan ? ['required', 'integer'] : ['prohibited'], 'concern_ids' => ['required', 'array', 'min:1'], 'concern_ids.*' => ['required', 'integer', Rule::exists('support_concerns', 'id')->where('apprentice_id', $apprentice->id)->whereNotNull('code')->whereNotIn('code', ['IM18', 'IM19', 'IM27', 'IM28', 'IM32', 'IM33'])], 'fields' => ['required', 'array'], 'fields.type' => ['nullable', Rule::in(['full', 'light_touch'])], 'fields.frequency' => ['nullable', Rule::in(['Every 4 weeks', 'Every 6 weeks', 'Every 8 weeks', 'Quarterly'])], 'fields.funding' => ['nullable', Rule::in(['not_funded', 'awaiting_assessment', 'als_funded'])], 'fields.referral' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'fields.review' => ['nullable', 'date_format:Y-m-d'], 'fields.referred' => ['nullable', 'string', 'max:500'], 'fields.source' => ['nullable', 'string', 'max:1000'], 'fields.barriers' => ['nullable', 'string', 'max:10000'], 'fields.strengths' => ['nullable', 'string', 'max:10000'], 'fields.view' => ['nullable', 'string', 'max:10000'], 'fields.support' => ['nullable', 'string', 'max:10000'], 'fields.rationale' => ['nullable', 'string', 'max:10000']]);
        $saved = DB::transaction(function () use ($r, $apprentice, $plan, $data) {
            $fresh = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->team($r, $fresh), 403);
            if (! $plan && SupportPlan::where('apprentice_id', $apprentice->id)->where('status', '!=', 'Closed')->exists()) {
                throw ValidationException::withMessages(['fields' => 'A current plan already exists. Open that plan to update it.']);
            }
            $p = $plan ? SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail() : new SupportPlan(['apprentice_id' => $apprentice->id]);
            if ($p->exists) {
                $this->version($r, $p);
                if ($p->status === 'Closed') {
                    throw ValidationException::withMessages(['fields' => 'Closed support history cannot be edited. Start a new plan.']);
                }
            }$ids = array_map('intval', array_unique($data['concern_ids']));
            $codes = SupportConcern::whereIn('id', $ids)->pluck('code', 'id')->all();
            $history = $p->history ?? [];
            $history[] = ['event' => 'draft_saved', 'fields' => $data['fields'], 'codes' => $codes, 'concern_ids' => $ids, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $allowedFields = ['type', 'frequency', 'funding', 'referral', 'review', 'referred', 'source', 'barriers', 'strengths', 'view', 'support', 'rationale'];
            $p->draft = ['fields' => collect($data['fields'])->only($allowedFields)->all(), 'codes' => $codes];
            $p->concern_ids = $ids;
            $p->history = $history;
            if ($p->exists) {
                $p->version++;
            }$p->save();
            $this->audit($r, $apprentice->id, $p->id, 'plan_draft_saved');

            return $p;
        });

        return to_route('support.plan', [$apprentice, $saved])->with('status', 'Support-plan draft saved privately. An existing active version remains unchanged until approval.');
    }

    public function approvePlan(Request $r, SupportPlan $plan): RedirectResponse
    {
        abort_unless($this->lead($r, $plan->apprentice), 403);
        $r->validate(['version' => ['required', 'integer'], 'confirmed' => ['accepted'], 'reason' => ['required', 'string', 'max:2000']]);
        DB::transaction(function () use ($r, $plan): void {
            $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $p);
            if ($p->status === 'Closed' || ($p->active_plan['draft_hash'] ?? null) === hash('sha256', json_encode($p->draft))) {
                throw ValidationException::withMessages(['confirmed' => 'This draft is closed or already active.']);
            }$f = $p->draft['fields'];
            if (($f['funding'] ?? null) === 'als_funded' && ! filled($f['rationale'] ?? null)) {
                throw ValidationException::withMessages(['fields' => 'Record the ALS claim rationale before approval.']);
            }
            foreach (['type', 'referral', 'barriers', 'strengths', 'view', 'support', 'review'] as $key) {
                if (! filled($f[$key] ?? null)) {
                    throw ValidationException::withMessages(['fields' => 'Complete the draft before approval: '.$key]);
                }
            }if ($f['review'] < today()->toDateString() || $f['referral'] > today()->toDateString()) {
                throw ValidationException::withMessages(['fields' => 'Check the referral date and set the next review to today or later.']);
            }$latest = SupportConcern::where('apprentice_id', $p->apprentice_id)->whereIn('id', $p->concern_ids)->lockForUpdate()->pluck('code', 'id')->all();
            if (count($latest) !== count($p->concern_ids) || array_diff(array_values($latest), array_keys($this->codes())) || array_intersect(array_values($latest), ['IM18', 'IM19', 'IM27', 'IM28', 'IM32', 'IM33']) || $latest !== $p->draft['codes']) {
                throw ValidationException::withMessages(['fields' => 'A linked concern code changed. Save a checked draft before approval.']);
            }$active = ['fields' => $f, 'concern_ids' => $p->concern_ids, 'codes' => $latest, 'draft_hash' => hash('sha256', json_encode($p->draft)), 'approved_at' => now()->toIso8601String(), 'approved_by' => $r->user()->id, 'reason' => $r->input('reason')];
            $h = $p->history ?? [];
            $h[] = ['event' => 'approved', 'release' => $active, 'at' => now()->toIso8601String()];
            $p->history = $h;
            $p->active_plan = $active;
            $p->status = 'Active';
            $p->version++;
            $p->save();
            $this->audit($r, $p->apprentice_id, $p->id, 'plan_approved');
        });

        return back()->with('status', 'Exact support-plan version approved. No employer sharing, funding claim or OTJ hours were generated.');
    }

    public const ACTIVITY_TYPES = ['Specialist 1:1 Coaching', 'In-Class Teaching Adjustment', 'Assistive Tech Setup & Support', 'Pastoral & Wellbeing Check-in', 'Workplace Mentor Consultation', 'Assessment & Exam Preparation'];

    public function supportActivity(Request $r, SupportPlan $plan): RedirectResponse
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'approved_at' => ['required', 'string'], 'request_id' => ['required', 'uuid'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'type' => ['required', Rule::in(self::ACTIVITY_TYPES)], 'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'who' => ['required', 'string'], 'other_name' => ['nullable', 'required_if:who,other', 'string', 'max:250'], 'delivered' => ['required', 'string', 'max:10000'], 'response' => ['nullable', 'string', 'max:10000'], 'impact' => ['nullable', 'string', 'max:10000']]);
        DB::transaction(function () use ($r, $plan, $data): void {
            $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $p);
            if ($p->status !== 'Active' || ! $p->active_plan || $p->active_plan['approved_at'] !== $data['approved_at']) {
                throw ValidationException::withMessages(['approved_at' => 'Open the current active support plan before recording delivered support.']);
            }
            if ($data['date'] < substr($p->active_plan['approved_at'], 0, 10)) {
                throw ValidationException::withMessages(['date' => 'Use an actual support date within the current active plan.']);
            }
            if (collect($p->activities ?? [])->contains('request_id', $data['request_id'])) {
                throw ValidationException::withMessages(['request_id' => 'This activity form was already saved. Open a new form for another activity.']);
            }
            $person = $data['who'] === 'other' ? null : User::whereKey($data['who'])->where('active', true)->whereNotIn('role', ['apprentice', 'employer'])->first();
            if (! $person && $data['who'] !== 'other') {
                throw ValidationException::withMessages(['who' => 'Choose an active named staff member or Other named person.']);
            }
            $activity = [...collect($data)->except('version', 'who', 'other_name')->all(), 'id' => (string) Str::uuid(), 'delivered_by' => ['id' => $person?->id, 'name' => $person?->name ?? $data['other_name'], 'reported' => $person === null], 'recorded_at' => now()->toIso8601String(), 'recorded_by' => $r->user()->id];
            $items = $p->activities ?? [];
            $items[] = $activity;
            $p->activities = $items;
            $p->version++;
            $p->save();
            $this->audit($r, $p->apprentice_id, $p->id, 'delivered_support_recorded');
        });

        return back()->with('status', 'Actual support activity recorded. Minutes are not automatically OTJ hours or an ALS funding claim; tasks and formal reviews remain separate.');
    }

    public function uploadPlanEvidence(Request $r, SupportPlan $plan): RedirectResponse
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'file' => PrivateEvidence::rules(), 'purpose' => ['required', 'string', 'max:2000'], 'activity_id' => ['nullable', 'uuid']]);
        $entry = null;
        try {
            DB::transaction(function () use ($r, $plan, $data, &$entry): void {
                $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                $this->version($r, $p);
                if (! in_array($p->status, ['Draft', 'Active'], true)) {
                    throw ValidationException::withMessages(['file' => 'Closed support records cannot receive new evidence.']);
                }
                if (filled($data['activity_id'] ?? null) && ! collect($p->activities ?? [])->contains('id', $data['activity_id'])) {
                    throw ValidationException::withMessages(['activity_id' => 'Choose an existing activity from this support plan.']);
                }
                $entry = PrivateEvidence::store($r->file('file'), 'support-plan-evidence', $r->user()->id);
                $files = $p->files ?? [];
                $files[] = [...$entry, 'purpose' => $data['purpose'], 'activity_id' => $data['activity_id'] ?? null];
                $p->files = $files;
                $p->version++;
                $p->save();
                $this->audit($r, $p->apprentice_id, $p->id, 'support_evidence_uploaded');
            });
        } catch (\Throwable $error) {
            if ($entry) {
                PrivateEvidence::delete($entry);
            } throw $error;
        }

        return back()->with('status', 'Evidence saved in the restricted support record. No tutor or employer disclosure was made.');
    }

    public function downloadPlanEvidence(Request $r, SupportPlan $plan, string $file): StreamedResponse
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        $entry = collect($plan->files ?? [])->firstWhere('id', $file);
        abort_unless($entry && PrivateEvidence::exists($entry), 404);

        return PrivateEvidence::download($entry);
    }

    public function reviewPlan(Request $r, SupportPlan $plan): RedirectResponse
    {
        abort_unless($this->team($r, $plan->apprentice), 403);
        $data = $r->validate(['version' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'baseline' => ['required', 'string', 'max:10000'], 'delivered' => ['required', 'string', 'max:10000'], 'impact' => ['required', 'string', 'max:10000'], 'evidence' => ['required', 'string', 'max:10000'], 'direction' => ['required', Rule::in(['continue', 'adjust_support', 'escalate', 'step_down_close'])], 'next' => ['required', 'date_format:Y-m-d', 'after_or_equal:date'], 'notes' => ['nullable', 'string', 'max:10000']]);
        DB::transaction(function () use ($r, $plan, $data): void {
            $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $p);
            if ($p->status !== 'Active' || ! $p->active_plan) {
                throw ValidationException::withMessages(['date' => 'Formal support reviews require an active plan.']);
            }$h = $p->reviews ?? [];
            $last = collect($h)->last();
            if ($data['date'] < substr($p->active_plan['approved_at'], 0, 10) || ($last && $data['date'] < $last['date'])) {
                throw ValidationException::withMessages(['date' => 'A review cannot precede the active plan or previous review.']);
            }$h[] = [...collect($data)->except('version')->all(), 'active_snapshot' => $p->active_plan, 'previous_due' => $p->nextReview(), 'by' => $r->user()->id, 'recorded_at' => now()->toIso8601String()];
            $p->reviews = $h;
            $p->version++;
            $p->save();
            $this->audit($r, $p->apprentice_id, $p->id, 'support_impact_review');
        });

        return back()->with('status', 'Support-impact review saved. Draft changes and funding decisions remain separate.');
    }

    public function closePlan(Request $r, SupportPlan $plan): RedirectResponse
    {
        abort_unless($this->lead($r, $plan->apprentice), 403);
        $r->validate(['version' => ['required', 'integer'], 'outcome' => ['required', 'string', 'max:10000'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($r, $plan): void {
            $p = SupportPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $this->version($r, $p);
            if ($p->status !== 'Active') {
                throw ValidationException::withMessages(['outcome' => 'Only an active plan can be closed.']);
            }$p->status = 'Closed';
            $p->closure_outcome = $r->input('outcome');
            $p->version++;
            $p->save();
            $this->audit($r, $p->apprentice_id, $p->id, 'plan_closed');
        });

        return back()->with('status', 'Support plan closed. History is retained; future support uses a new plan.');
    }

    private function version(Request $r, Model $row): void
    {
        $a = $row instanceof Apprentice ? $row : $row->apprentice;
        $route = $r->route()->getName();
        if ($row instanceof SupportConcern) {
            abort_unless(in_array($route, ['support.classify', 'support.close'], true) ? $this->lead($r, $a) : $this->team($r, $a), 403);
        } elseif ($row instanceof SupportPlan) {
            abort_unless(in_array($route, ['support.plan.approve', 'support.plan.close'], true) ? $this->lead($r, $a) : $this->team($r, $a), 403);
        } elseif ($row instanceof Apprentice) {
            abort_unless($this->lead($r, $a), 403);
        } elseif ($row instanceof TeachingInstruction && $route !== 'support.instruction.ack') {
            abort_unless((in_array($route, ['support.release', 'support.instruction.withdraw'], true) ? SupportAccess::approver($r->user(), $a) : SupportAccess::writer($r->user(), $a)) || ExecutiveAccess::enabled($r), 403);
        }

        if ($row->version !== $r->integer('version')) {
            throw ValidationException::withMessages(['version' => 'This record changed. Reopen before saving.']);
        }
    }

    private function audit(Request $r, int $a, int $id, string $event): void
    {
        if (ExecutiveAccess::enabled($r)) {
            ExecutiveAccess::record($r, $event, 'apprentice:'.$a.'/support:'.$id);
        }
        DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $a, 'kind' => 'support', 'record_id' => $id, 'event' => $event, 'created_at' => now()]);
    }
}
