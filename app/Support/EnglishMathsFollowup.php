<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\Qualification;
use App\Models\TrainingTopic;
use App\Models\User;
use Illuminate\Support\Collection;

class EnglishMathsFollowup
{
    public const VIEWS = ['all', 'overdue', 'today', 'checks', 'awaiting', 'referred', 'unrecorded'];

    public static function subjects(User $user, Apprentice $apprentice): array
    {
        return array_values(array_filter(['maths', 'english'], fn ($s) => LearningAccess::training($user, $apprentice) || LearningAccess::subject($user, $apprentice, $s)));
    }

    public static function for(User $user, Collection $people, string $subject = 'all'): array
    {
        // Read metadata only; evidence, notes, feedback and submission bodies stay at source.
        $quals = Qualification::whereIn('apprentice_id', $people->pluck('id'))->get(['id', 'apprentice_id', 'subject', 'status', 'start_date'])->groupBy('apprentice_id');
        $topics = TrainingTopic::whereIn('apprentice_id', $people->pluck('id'))->get(['id', 'apprentice_id', 'subject', 'planned_hours', 'status', 'evidence_stage'])->groupBy('apprentice_id');
        $rows = collect();
        $staffSubjects = SubjectMarkingQueue::allowedSubjects($user);
        $manager = LearningAccess::manager($user);
        foreach ($people as $a) {
            $editable = $manager ? $staffSubjects : array_filter($staffSubjects, fn ($s) => $a->{$s.'_tutor_id'} === $user->id);
            $subjects = LearningAccess::training($user, $a) ? ['maths', 'english'] : $editable;
            if ($subject !== 'all') { $subjects = array_values(array_intersect($subjects, [$subject])); }
            if (! $subjects) { continue; }
            $summary = [];
            foreach ($subjects as $s) {
                $q = $quals->get($a->id, collect())->where('subject', $s);
                $t = $topics->get($a->id, collect())->where('subject', $s);
                $positions = $q->keyBy('id')->map(fn ($item) => $item->position());
                $canUpdate = in_array($s, $editable, true);
                $nextResult = $canUpdate ? $q->filter(fn ($record) => in_array($positions->get($record->id), ['Overdue', 'Due today'], true))
                    ->sortBy('start_date')->first() : null;
                $planned = $t->filter(fn ($item) => (float) $item->planned_hours > 0);
                $summary[$s] = [
                    'next_result' => $nextResult?->id,
                    'next_review' => $canUpdate ? $t->where('evidence_stage', 'Awaiting review')->sortBy('id')->first()?->id : null,
                    'qualifications' => $q->count(),
                    'overdue' => $positions->filter(fn ($p) => $p === 'Overdue')->count(),
                    'today' => $positions->filter(fn ($p) => $p === 'Due today')->count(),
                    'checks' => $positions->filter(fn ($p) => $p === 'Date check')->count(),
                    'awaiting' => $t->where('evidence_stage', 'Awaiting review')->count(),
                    'referred' => $t->where('evidence_stage', 'Referred')->count(),
                    'unrecorded' => $q->isEmpty() ? 1 : 0,
                    'planned_topics' => $planned->count(),
                    'completed_topics' => $planned->where('status', 'Completed')->count(),
                    'planned_hours' => $planned->sum('planned_hours'),
                ];
            }
            $flags = [];
            foreach (array_diff(self::VIEWS, ['all']) as $view) { $flags[$view] = collect($summary)->sum($view) > 0; }
            $rows->push(['apprentice' => $a, 'subjects' => $summary, 'flags' => $flags]);
        }
        $counts = ['all' => $rows->count()];
        foreach (array_diff(self::VIEWS, ['all']) as $view) { $counts[$view] = $rows->filter(fn ($row) => $row['flags'][$view])->count(); }
        return ['rows' => $rows, 'counts' => $counts];
    }
}
