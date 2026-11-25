<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\ProgrammeOutcome;
use App\Models\SignedReviewRecord;
use App\Models\SmartAction;
use App\Models\TrainingSession;
use App\Models\User;

class ApprenticeReminderSources
{
    public static function build(Apprentice $a, bool $lock = false): array
    {
        $outcome = ProgrammeOutcome::where('apprentice_id', $a->id)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $context = $outcome?->context ?? [];
        if (($context['status'] ?? null) !== 'Active' || ! filled($context['sourceRef'] ?? null)) {
            return ['source' => null, 'check' => 'programme_not_sourced_active'];
        }
        $u = $a->user_id ? User::whereKey($a->user_id)->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
        if (! $u?->active || $u->role !== 'apprentice' || ! $u->email_verified_at || Apprentice::where('user_id', $u->id)->count() !== 1) {
            return ['source' => null, 'check' => 'account_link_or_mailbox_check'];
        }
        $programme = $a->programmeRecord()->when($lock, fn ($q) => $q->lockForUpdate())->first();
        $catalogue = $a->programme_id ? TaskCatalogue::allocationKey($a) : null;
        $items = [];
        $checks = 0;
        $end = today()->addDays(7)->toDateString();
        $tasks = LearningTask::where('apprentice_id', $a->id)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        foreach ($tasks as $t) {
            if ($a->programme_id && ($catalogue === null || TaskCatalogue::savedKey($t) !== $catalogue)) {
                $checks++;

                continue;
            }
            if (! $t->editable() || ($t->template['kind'] ?? 'source-pending') === 'source-pending' || ! BlockRelease::state($t)['open']) {
                continue;
            }
            $due = $t->due_date?->toDateString();
            $kind = $t->status === 'Referred' ? 'task_referred' : (! $due ? null : ($due < today()->toDateString() ? 'task_overdue' : ($due <= $end ? 'task_due' : null)));
            if (! $kind) {
                if (! $due) {
                    $checks++;
                }

                continue;
            }
            $items[] = ['kind' => $kind, 'id' => $t->id, 'version' => $t->version, 'date' => $due, 'reference' => $t->reference, 'status' => $t->status];
        }
        foreach (SmartAction::where('apprentice_id', $a->id)->whereNull('learning_task_id')->where('owner', 'Apprentice')->where('status', 'To do')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get() as $x) {
            $due = $x->due_date?->toDateString();
            if (! $due) {
                $checks++;

                continue;
            }if ($due <= $end) {
                $items[] = ['kind' => $due < today()->toDateString() ? 'action_overdue' : 'action_due', 'id' => $x->id, 'version' => $x->version, 'date' => $due];
            }
        }
        if ($lock) {
            SignedReviewRecord::where('apprentice_id', $a->id)->orderBy('id')->lockForUpdate()->get();
        }
        $cycle = SignedReviews::cycle($a);
        $due = $cycle['next_due'];
        if ($due && $due <= $end) {
            $items[] = ['kind' => 'review_due', 'id' => $cycle['latest_record_id'], 'date' => $due, 'meeting_date' => $cycle['last_meeting']];
        } elseif (! $due) {
            $checks++;
        }
        $tomorrow = today()->addDay()->toDateString();
        foreach (TrainingSession::where(fn ($q) => $q->whereJsonContains('members', $a->id)->orWhereJsonContains('members', (string) $a->id))->whereDate('session_date', $tomorrow)->where('state', 'Planned')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get() as $s) {
            $roster = RegisterFollowup::roster($s);
            if (! $roster['shape'] || $roster['duplicates']) {
                $checks++;

                continue;
            }$items[] = ['kind' => 'training_tomorrow', 'id' => $s->id, 'version' => $s->version, 'date' => $tomorrow, 'start' => $s->start_time, 'end' => $s->end_time];
        }

        // Only dated, outstanding non-voluntary assignments; no form title, answers or check notes.
        foreach (\App\Models\PortalQuestionnaireAssignment::where('apprentice_id',$a->id)->where('recipient_id',$u->id)
            ->whereIn('state',['Assigned','In progress'])->whereDate('due_date','<=',$end)->orderBy('id')
            ->when($lock,fn($q)=>$q->lockForUpdate())->get() as $x) {
            $f=$x->form;
            if ($f->kind==='ongoing' || !in_array($f->state,['Published','Retired'],true) || !PortalQuestionnaires::intact($f)) {continue;}
            $items[]=['kind'=>'questionnaire_due','id'=>$x->id,'version'=>$x->version,'date'=>$x->due_date->toDateString(),'form_hash'=>$f->content_hash];
        }

        foreach (\App\Models\ElectronicReviewPacket::where('apprentice_id',$a->id)->where('state','Released')->orderBy('id')->when($lock,fn($q)=>$q->lockForUpdate())->get() as $packet) {
            $slot=\App\Models\ElectronicReviewSigner::where('packet_id',$packet->id)->where('user_id',$u->id)->where('role','apprentice')->where('state','Pending')->when($lock,fn($q)=>$q->lockForUpdate())->first();
            if ($slot && ElectronicReviews::current($packet) && ElectronicReviews::signerIntact($slot,$packet)) {
                $items[]=['kind'=>'review_signing','id'=>$packet->id,'version'=>$packet->version,'date'=>$packet->expires_on->toDateString(),'document_hash'=>$packet->snapshot_hash];
            }
        }
        if (count($items) > 100) {
            return ['source' => null, 'check' => 'over_100_items_reconcile_first'];
        }
        $source = $items ? ['template_version' => ApprenticeAlertTransport::TEMPLATE_VERSION, 'cycle_date' => today()->toDateString(), 'apprentice_id' => $a->id, 'recipient_id' => $u->id, 'programme' => ['record' => $programme?->only('id', 'record_version', 'standard_code', 'version', 'active'), 'id' => $a->programme_id, 'status_hash' => ReviewedImports::hash(array_intersect_key($context, array_flip(['status', 'sourceRef', 'endDate'])))], 'items' => $items] : null;

        return ['source' => $source, 'check' => $source ? null : 'no_due_items', 'checks' => $checks];
    }

    public static function link(Apprentice $a, array $item): string
    {
        return match ($item['kind']) {
            'review_due' => route('review-appointments.index'), 'training_tomorrow' => route('calendar.index'), 'review_signing' => route('electronic-reviews.show',$item['id']),'questionnaire_due' => route('portal-questionnaires.show',$item['id']),'task_referred','task_overdue','task_due' => route('tasks.show', $item['id']),'action_overdue','action_due' => route('actions.index', $a).'#action-'.$item['id'],default => route('experience.home')
        };
    }

    public const LABELS = ['review_signing' => 'Review record waiting for your sign-off', 'questionnaire_due' => 'Portal questionnaire due — sign in to check', 'task_referred' => 'Task returned for changes', 'task_overdue' => 'Task past its recorded deadline', 'task_due' => 'Task due within seven days', 'action_overdue' => 'Agreed action past its date', 'action_due' => 'Agreed action due within seven days', 'review_due' => 'Review due reference — confirm appointment with tutor', 'training_tomorrow' => 'Saved training session tomorrow — check timetable with GTA'];
}
