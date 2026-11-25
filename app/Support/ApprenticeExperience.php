<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\ApprenticeExperienceEntry;
use App\Models\LearningTask;
use App\Models\SmartAction;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprenticeExperience
{
    public const KINDS = ['help_task' => 'Task help', 'help_employer' => 'Employer issue', 'help_private' => 'Private support', 'achievement' => 'Workplace achievement'];

    public static function profile(User $u, bool $lock = false): ?Apprentice
    {
        abort_unless($u->active && $u->role === 'apprentice', 403);
        $rows = Apprentice::where('user_id', $u->id)->when($lock, fn ($q) => $q->lockForUpdate())->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }

    public static function blocks(Apprentice $a, ?Collection $sourceTasks = null): array
    {
        $tasks = $sourceTasks ?? LearningTask::where('apprentice_id', $a->id)->orderBy('block')->orderBy('reference')->get();
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $block = $tasks->where('block', $i);
            $valid = $block->filter(fn ($t) => ($t->template['kind'] ?? 'source-pending') !== 'source-pending');
            $approved = $valid->where('status', 'Approved')->count();
            // This is only a count of allocated task decisions, never a Gateway/KSB judgement.
            $complete = $block->count() === 5 && $valid->count() === 5 && $block->pluck('reference')->unique()->count() === 5 && $approved === 5;
            if ($complete) {
                $key = TaskCatalogue::savedKey($block->first());
                $expected = array_column(array_filter(TaskCatalogue::tasks($key), fn ($t) => (int) $t['block'] === $i), 'id');
                $complete = count($expected) === 5 && ! array_diff($expected, $block->pluck('reference')->all()) && $block->every(fn ($t) => TaskCatalogue::savedKey($t) === $key);
            }
            $rows[] = ['block' => $i, 'label' => 'Training block '.$i, 'approved' => $approved, 'allocated' => $block->count(), 'complete' => $complete, 'status' => $complete ? 'Five allocated tasks approved' : ($block->isEmpty() ? 'Tasks not allocated' : $approved.' of '.$block->count().' allocated tasks approved')];
        }
        foreach ([11 => 'Gateway preparation', 12 => 'EPA'] as $i => $label) {
            $rows[] = ['block' => $i, 'label' => $label, 'approved' => null, 'allocated' => null, 'complete' => false, 'status' => 'Separate checks and sign-off required'];
        }

        return $rows;
    }

    public static function home(Apprentice $a): array
    {
        $tasks = LearningTask::where('apprentice_id', $a->id)->orderBy('block')->orderBy('reference')->get();
        $task = $tasks->filter(fn ($t) => $t->editable() && ! in_array($t->template['kind'] ?? 'source-pending', ['source-pending', 'knowledge'], true) && BlockRelease::state($t)['open'])->sortBy(fn ($t) => ($t->due_date?->toDateString() ?? '9999-12-31').'-'.str_pad((string) $t->block, 2, '0', STR_PAD_LEFT).'-'.$t->reference)->first();
        $actions = SmartAction::with('task')->where('apprentice_id', $a->id)->where('owner', 'Apprentice')->get()->filter(fn ($x) => $x->currentStatus() === 'To do')->sortBy(fn ($x) => $x->deadline()?->toDateString() ?? '9999-12-31')->values();
        $next = $task ? ['title' => $task->title, 'label' => $task->status === 'Referred' ? 'Make your task changes' : 'Continue your next task', 'url' => route('tasks.show', $task), 'due' => $task->due_date?->toDateString()] : null;
        $action = $actions->first(fn ($x) => ! $x->learning_task_id || ($x->task && $x->task->editable() && ! in_array($x->task->template['kind'] ?? 'source-pending', ['source-pending', 'knowledge'], true) && BlockRelease::state($x->task)['open']));
        if ($action && (! $next || ($action->overdue() && (! $task->due_date || ! $task->due_date->lt(today()))))) {
            $next = ['title' => $action->title, 'label' => 'Open your agreed action', 'url' => route('actions.index', $a).'#action-'.$action->id, 'due' => $action->deadline()?->toDateString()];
        }
        $session = TrainingSession::where('state', 'Planned')->where(fn ($q) => $q->whereJsonContains('members', $a->id)->orWhereJsonContains('members', (string) $a->id))->whereDate('session_date', '>=', today())->orderBy('session_date')->orderBy('start_time')->orderBy('id')->get(['id', 'name', 'session_date', 'start_time', 'end_time', 'members'])->first(function ($s) use ($a) {
            $roster = RegisterFollowup::roster($s);

            return $roster['shape'] && ! $roster['duplicates'] && in_array($a->id, $roster['ids'], true) && TimetableChanges::time($s->start_time) && TimetableChanges::time($s->end_time) && $s->end_time > $s->start_time && ! $s->ended();
        });

        return ['next' => $next, 'actions' => $actions, 'session' => $session, 'review' => SignedReviews::cycle($a), 'blocks' => self::blocks($a), 'awaiting' => $tasks->where('status', 'Awaiting marking')->count()];
    }

    public static function queueAllowed(User $u): bool
    {
        $s = SupportAccess::settings($u);

        return $u->active && (LearningAccess::manager($u) || $u->role === 'tutor' || StaffDuties::has($u, 'mentor') || in_array($s['duty'], ['member', 'lead'], true));
    }

    private static function mentor(Apprentice $a): bool
    {
        $u = $a->mentor_id ? User::find($a->mentor_id) : null;

        return $u && $u->active && StaffDuties::has($u, 'mentor');
    }

    public static function canHandle(User $u, ApprenticeExperienceEntry $e): bool
    {
        if (! $u->active) {
            return false;
        }$a = $e->apprentice;
        if ($e->kind === 'help_private') {
            return SupportAccess::writer($u, $a);
        }
        if (LearningAccess::manager($u)) {
            return true;
        }
        if ($e->kind === 'help_employer' && self::mentor($a)) {
            return StaffDuties::assigned($u, $a, 'mentor');
        }

        return LearningAccess::tutor($u, $a);
    }

    public static function queue(User $u): Builder
    {
        abort_unless(self::queueAllowed($u), 403);
        $main = LearningAccess::manager($u) ? Apprentice::pluck('id')->all() : ($u->role === 'tutor' ? Apprentice::where('tutor_id', $u->id)->pluck('id')->all() : []);
        $employer = $main;
        if (! LearningAccess::manager($u)) {
            $employer = Apprentice::where(fn ($q) => $q->where('mentor_id', $u->id)->orWhereIn('id', $main))->get(['id', 'tutor_id', 'mentor_id'])->filter(fn ($a) => self::mentor($a) ? StaffDuties::assigned($u, $a, 'mentor') : LearningAccess::tutor($u, $a))->pluck('id')->all();
        }
        $s = SupportAccess::settings($u);
        $private = in_array($s['duty'], ['member', 'lead'], true) ? SupportAccess::ids($u) : [];

        return ApprenticeExperienceEntry::where(function ($q) use ($main, $employer, $private): void {
            $q->where(fn ($x) => $x->whereIn('kind', ['help_task', 'achievement'])->whereIn('apprentice_id', $main))
                ->orWhere(fn ($x) => $x->where('kind', 'help_employer')->whereIn('apprentice_id', $employer))
                ->orWhere(fn ($x) => $x->where('kind', 'help_private')->whereIn('apprentice_id', $private));
        });
    }

    public static function create(User $actor, int $authVersion, array $data): ApprenticeExperienceEntry
    {
        return DB::transaction(function () use ($actor, $authVersion, $data) {
            $a = self::profile($actor, true);
            abort_unless($a, 409);
            $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(LearningAccess::learner($u, $a) && $u->auth_version === $authVersion, 403);
            $existing = ApprenticeExperienceEntry::where('request_token', $data['request_token'])->first();
            if ($existing) {
                abort_unless($existing->apprentice_id === $a->id && $existing->requester_id === $u->id, 409);

                return $existing;
            }
            if (ApprenticeExperienceEntry::where('requester_id', $u->id)->where('created_at', '>=', now()->subDay())->count() >= 20) {
                throw ValidationException::withMessages(['detail' => 'You have sent 20 entries in the last day. Please contact GTA if you need more help.']);
            }
            $entry = ApprenticeExperienceEntry::create(collect($data)->only('request_token', 'kind', 'title', 'detail', 'happened_on')->all() + ['apprentice_id' => $a->id, 'requester_id' => $u->id, 'state' => 'New', 'version' => 1, 'history' => [['event' => 'created', 'by' => $u->id, 'at' => now()->toIso8601String()]]]);
            self::audit($entry, $u, 'experience_entry_created');

            return $entry;
        });
    }

    public static function respond(User $actor, int $authVersion, ApprenticeExperienceEntry $entry, array $data): void
    {
        DB::transaction(function () use ($actor, $authVersion, $entry, $data): void {
            Apprentice::whereKey($entry->apprentice_id)->lockForUpdate()->firstOrFail();
            $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $e = ApprenticeExperienceEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            abort_unless($u->auth_version === $authVersion && self::canHandle($u, $e), 403);
            if ($e->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'This entry changed. Reload before responding.']);
            }
            $allowed = $e->kind === 'achievement' ? ['Approved', 'Referred'] : ['Received', 'Closed'];
            if (! in_array($data['state'], $allowed, true) || $e->state === $data['state'] || ($e->kind !== 'achievement' && $e->state === 'Closed')) {
                throw ValidationException::withMessages(['state' => 'Choose a current permitted change. Closed help requests stay closed; the apprentice can send a new request.']);
            }
            $before = $e->state;
            $e->state = $data['state'];
            $e->response = $data['response'];
            $e->reviewer_id = $u->id;
            $e->version++;
            $e->history = [...$e->history, ['event' => 'response', 'before' => $before, 'after' => $e->state, 'response' => $e->response, 'by' => $u->id, 'at' => now()->toIso8601String()]];
            $e->save();
            self::audit($e,$u,'experience_entry_responded');
        });
    }

    private static function audit(ApprenticeExperienceEntry $e,User $u,string $event): void
    {
        DB::table('audit_events')->insert(['user_id' => $u->id, 'apprentice_id' => $e->apprentice_id, 'event' => $event, 'created_at' => now()]);
    }
}
