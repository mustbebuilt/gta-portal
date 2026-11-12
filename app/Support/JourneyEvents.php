<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\Qualification;
use App\Models\SmartAction;
use App\Models\TrainingTopic;

class JourneyEvents
{
    public static function for(Apprentice $a): array
    {
        $events = [];
        if ($a->start_date) {
            $events[] = ['date' => $a->start_date->toDateString(), 'type' => 'Programme start', 'title' => 'First day in learning', 'detail' => 'Recorded start date', 'url' => route('apprentices.show', $a)];
        }
        foreach (LearningTask::where('apprentice_id', $a->id)->where('status', 'Approved')->get() as $t) {
            $decision = collect($t->submissions ?? [])->last()['decision'] ?? null;
            if ($decision && filled($decision['date'] ?? null)) {
                $events[] = ['date' => $decision['date'], 'type' => 'Task approved', 'title' => $t->title, 'detail' => $decision['feedback'] ?? 'Tutor approval recorded', 'url' => route('tasks.show', $t)];
            }
        }
        foreach (TrainingTopic::where('apprentice_id', $a->id)->where('status', 'Completed')->whereNotNull('completion_date')->get() as $t) {
            $events[] = ['date' => $t->completion_date->toDateString(), 'type' => ucfirst($t->subject).' training', 'title' => $t->topic, 'detail' => 'Assigned training topic completed; qualification results remain separate', 'url' => route('topics.show', $t)];
        }
        foreach (Qualification::where('apprentice_id', $a->id)->whereIn('status', ['Achieved', 'Already held'])->whereNotNull('achievement_date')->get() as $q) {
            $events[] = ['date' => $q->achievement_date->toDateString(), 'type' => 'Qualification result', 'title' => $q->title, 'detail' => $q->status.' · '.$q->result, 'url' => route('em.index', $a)];
        }
        foreach (SmartAction::where('apprentice_id', $a->id)->whereNull('learning_task_id')->where('status', 'Completed')->whereNotNull('completion_date')->get() as $action) {
            $events[] = ['date' => $action->completion_date->toDateString(), 'type' => 'Agreed target completed', 'title' => $action->title, 'detail' => $action->measure, 'url' => route('actions.index', $a).'#action-'.$action->id];
        }
        usort($events, fn ($x, $y) => strcmp($x['date'], $y['date']) ?: strcmp($x['title'], $y['title']));

        return array_values(array_filter($events, fn ($e) => $e['date'] <= today()->toDateString()));
    }
}
