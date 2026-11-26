<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\TrainingTopic;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class PlanCurriculum
{
    public const SUBJECTS = ['english'=>'English', 'maths'=>'Maths'];

    public static function tasks(?string $catalogue): array
    {
        if ($catalogue === null) {
            return [];
        }

        return collect(TaskCatalogue::tasks($catalogue))->keyBy('id')->all();
    }

    public static function topicKey(string $subject, string $level, string $title): string
    {
        return 'fs-'.hash('sha256', json_encode([$subject, $level, $title], JSON_THROW_ON_ERROR));
    }

    public static function topics(): array
    {
        $rows = TrainingPlanSource::load('topic-catalogue');
        $topics = [];
        foreach ($rows as $row) {
            [$name, $level] = explode(': ', $row['subject'], 2);
            $subject = strtolower($name);
            $id = self::topicKey($subject, $level, $row['topic']);
            $topics[$subject][$id] = ['id'=>$id, 'level'=>$level, 'title'=>$row['topic']];
        }

        return $topics;
    }

    public static function keyForTopic(TrainingTopic $topic): string
    {
        return self::topicKey($topic->subject, $topic->level, $topic->topic);
    }

    public static function completion(Collection $tasks, Collection $topics, ?array $plan): array
    {
        $scope = $plan['curriculum'] ?? [];
        $taskScope = isset($scope['tasks']) ? array_keys($scope['tasks']) : null;
        $assignedTasks = $tasks->filter(fn ($task) => ($task->template['kind'] ?? 'source-pending') !== 'source-pending');
        $completedTasks = $assignedTasks->where('status', 'Approved')->filter(fn ($task) =>
            $taskScope === null || TaskCatalogue::savedKey($task) === $plan['identity']['catalogue']);
        $progress = ['Apprenticeship tasks'=>self::progress(
            $completedTasks->pluck('reference')->all(), $assignedTasks->pluck('reference')->all(), $taskScope
        )];
        foreach (self::SUBJECTS as $key => $label) {
            $assigned = $topics->where('subject', $key)->filter(fn ($topic) => $topic->planned_hours > 0);
            $secureIds = $topics->where('subject', $key)->filter(fn ($topic) => (float) $topic->planned_hours === 0.0)
                ->map(fn ($topic) => self::keyForTopic($topic))->all();
            $required = $scope['subjects'][$key]['topics'] ?? null;
            $required = $required === null ? null : array_diff(array_keys($required), $secureIds);
            $topicId = fn ($topic) => self::keyForTopic($topic);
            $progress[$label] = self::progress($assigned->where('status', 'Completed')->map($topicId)->all(),
                $assigned->map($topicId)->all(), $required);
        }

        return $progress;
    }

    private static function progress(array $done, array $assigned, ?array $whole): array
    {
        $progress = ApprenticeReviewData::progress($done, $assigned, $whole);
        return ['done'=>$progress['assigned_done'], 'total'=>$progress['assigned_total'],
            'percent'=>$progress['assigned_percent'], 'whole_done'=>$progress['overall_done'],
            'whole_total'=>$progress['overall_total'], 'whole_percent'=>$progress['overall_percent']];
    }

    public static function prepare(Apprentice $apprentice, array $input): array
    {
        $catalogue = TaskCatalogue::keyFor($apprentice);
        $taskOptions = self::tasks($catalogue);
        $topics = self::topics();
        $allTasks = ($input['task_scope'] ?? '') === 'all';
        $tasks = $allTasks ? array_keys($taskOptions) : ($input['tasks'] ?? []);
        if (!$tasks || array_diff($tasks, array_keys($taskOptions))) {
            throw ValidationException::withMessages(['tasks'=>'Choose the full task catalogue or select the applicable tasks from this programme.']);
        }

        $secure = TrainingTopic::where('apprentice_id', $apprentice->id)->where('planned_hours', 0)->get()
            ->map(fn ($topic) => self::keyForTopic($topic))->all();
        $subjects = [];
        foreach (self::SUBJECTS as $subject => $label) {
            $section = $input['subjects'][$subject] ?? [];
            $choice = $section['scope'] ?? null;
            $ids = $section['topics'] ?? [];
            $available = array_diff_key($topics[$subject] ?? [], array_flip($secure));
            if ($choice === 'selected' && (!$ids || array_diff($ids, array_keys($available)))) {
                throw ValidationException::withMessages(['subjects.'.$subject.'.topics'=>'Choose the applicable '.$label.' topics. Zero-hour secure topics are excluded.']);
            }
            $subjects[$subject] = match ($choice) {
                'none' => ['topics'=>[], 'due'=>null],
                'selected' => ['topics'=>array_intersect_key($available, array_flip($ids)), 'due'=>$section['due'] ?? null],
                default => null,
            };
        }

        return ['mode'=>$allTasks ? 'all' : 'selected',
            'tasks'=>array_intersect_key($taskOptions, array_flip($tasks)), 'subjects'=>$subjects];
    }

    public static function requirements(array $plan): array
    {
        $scope = $plan['curriculum'] ?? [];
        $blocks = collect($plan['blocks'])->keyBy('number');
        $requirements = [];
        foreach ($scope['tasks'] ?? [] as $reference => $row) {
            $requirements[] = ['subjectId'=>'voc-'.$plan['identity']['catalogue'].'-'.$reference,
                'label'=>$plan['identity']['code'].' · Block '.$row['block'].' · '.$row['title'],
                'area'=>'vocational', 'required'=>true, 'due'=>$blocks->get($row['block'])['end'] ?? null];
        }
        foreach ($scope['subjects'] ?? [] as $subject => $section) {
            foreach ($section['topics'] ?? [] as $id => $topic) {
                $requirements[] = ['subjectId'=>$id, 'label'=>self::SUBJECTS[$subject].' '.$topic['level'].' · '.$topic['title'],
                    'area'=>'functional_skills', 'required'=>true, 'due'=>$section['due'] ?? null];
            }
        }

        return $requirements;
    }
}
