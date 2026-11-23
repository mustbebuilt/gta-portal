<?php

declare(strict_types=1);

use App\Models\Apprentice;
use App\Models\IndividualDeliveryPlan;
use App\Models\Qualification;
use App\Models\SupportConcern;
use App\Models\TrainingTopic;
use App\Models\User;
use App\Support\DeliveryPlan;
use App\Support\PlanCurriculum;
use App\Support\TaskCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class GtaQaProgress
{
    public static function seed(
        Apprentice $person, User $actor, User $support, User $tutor, string $key, int $index, int $stage,
        int $practicalWeeks, int $hours, CarbonImmutable $start, CarbonImmutable $week, CarbonImmutable $through,
        string $outcome, array $subjects
    ): array {
        $source = 'Fictional testing only · '.$person->reference;
        $blocks = self::blocks($start, $practicalWeeks, $hours);
        $topicChoices = self::functionalSkills($person, $subjects, $stage, $index, $through, $source);
        $planAdded = $index % 31 !== 30;
        if ($planAdded) {
            $draft = [
                'identity' => DeliveryPlan::identity($person), 'catalogue_hash' => self::catalogueHash($key),
                'blocks' => $blocks, 'details' => [
                    'target_hours' => $hours, 'basis' => 'Full training retained', 'source_ref' => $source,
                    'ksb_ref' => $source, 'qualifications_ref' => $source, 'release_ref' => $source,
                    'practical_end_date' => $blocks[9]['end'], 'apprenticeship_end_date' => $blocks[11]['end'],
                ], 'curriculum' => PlanCurriculum::prepare($person, ['task_scope' => 'all', 'subjects' => $topicChoices]),
            ];
            $snapshot = $draft + ['approved_by' => $actor->id,
                'approved_at' => $start->toIso8601String(), 'approval_scope' => 'Fictional acceptance-testing fixture'];
            $snapshot['hash'] = hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
            $row = new IndividualDeliveryPlan(['apprentice_id' => $person->id, 'draft' => $draft, 'history' => []]);
            $row->forceFill(['active' => true, 'approved' => $snapshot])->save();
        }

        $tasks = self::tasks($person, $tutor, $start, $through, $stage, $index, $outcome, $key, $blocks);
        $concernAdded = $outcome === 'Active' && ($index % 7 === 0 || $index === 1);
        if ($concernAdded) {
            self::support($person, $actor, $support, $index, $week, $source);
        }
        return ['learning_tasks' => $tasks, 'delivery_plans' => (int) $planAdded, 'support_concerns' => (int) $concernAdded];
    }

    private static function catalogueHash(string $key): string
    {
        static $cache = [];
        return $cache[$key] ??= DeliveryPlan::catalogueHash($key);
    }

    private static function blocks(CarbonImmutable $start, int $practicalWeeks, int $hours): array
    {
        $blocks = [];
        for ($i = 0; $i < 10; $i++) {
            $blocks[] = ['number' => $i + 1, 'title' => 'Training block '.($i + 1),
                'start' => $start->addWeeks(intdiv($practicalWeeks * $i, 10))->toDateString(),
                'end' => $start->addWeeks(intdiv($practicalWeeks * ($i + 1), 10))->subDay()->toDateString(),
                'hours' => $hours / 10];
        }
        $gateway = $start->addWeeks($practicalWeeks);
        $blocks[] = ['number' => 11, 'title' => 'Gateway readiness', 'start' => $gateway->toDateString(),
            'end' => $gateway->addWeeks(3)->subDay()->toDateString(), 'hours' => 0];
        $blocks[] = ['number' => 12, 'title' => 'EPA window', 'start' => $gateway->addWeeks(3)->toDateString(),
            'end' => $gateway->addWeeks(8)->subDay()->toDateString(), 'hours' => 0];
        return $blocks;
    }

    public static function plannedEnd(CarbonImmutable $start, int $practicalWeeks): CarbonImmutable
    {
        return $start->addWeeks($practicalWeeks + 8)->subDay();
    }

    private static function functionalSkills(
        Apprentice $person, array $subjects, int $stage, int $index, CarbonImmutable $through, string $source
    ): array {
        static $options;
        $options ??= PlanCurriculum::topics();
        $choices = [];
        foreach ($subjects as $subject => $required) {
            $choices[$subject] = ['scope' => 'none'];
            if ($required !== 'Yes') {
                continue;
            }

            $requestedLevel = $person->programmeRecord->level === 2 ? 'Level 1' : 'Level 2';
            $catalogue = array_filter($options[$subject] ?? [], fn ($topic) => strcasecmp($topic['level'], $requestedLevel) === 0);
            $topics = array_slice($catalogue ?: $options[$subject], 0, 2, true);
            if ($topics === []) {
                continue;
            }
            $choices[$subject] = ['scope' => 'selected', 'topics' => array_keys($topics)];
            if ($stage === 0 && $index % 2 === 0) {
                continue;
            }

            $started = $through->subWeeks($stage === 0 ? 1 : 10)->toDateString();
            $achieved = $stage >= 3 && $index % 5 === 0;
            Qualification::create(['apprentice_id' => $person->id, 'subject' => $subject, 'type' => 'Functional Skills',
                'title' => 'Fictional Functional Skills '.ucfirst($subject), 'level' => reset($topics)['level'],
                'status' => $achieved ? 'Achieved' : 'Working towards', 'start_date' => $started,
                'achievement_date' => $achieved ? $through->toDateString() : null,
                'result' => $achieved ? 'Pass' : null, 'notes' => $source]);
            foreach (array_values($topics) as $j => $topic) {
                $completed = $achieved || ($stage > 1 && $j === 0);
                TrainingTopic::create(['apprentice_id' => $person->id, 'subject' => $subject, 'level' => $topic['level'],
                    'topic' => $topic['title'], 'area' => ucfirst($subject), 'planned_hours' => 3,
                    'status' => $completed ? 'Completed' : 'Not Started',
                    'completion_date' => $completed ? $through->toDateString() : null]);
            }
        }

        return $choices;
    }

    private static function tasks(
        Apprentice $person, User $tutor, CarbonImmutable $start, CarbonImmutable $through, int $stage,
        int $index, string $outcome, string $key, array $blocks
    ): int {
        static $catalogues = [];
        if (!isset($catalogues[$key])) {
            $catalogues[$key] = array_map(function ($task) use ($key) {
                return $task + ['source' => TaskCatalogue::template($task['id'], $key)];
            }, TaskCatalogue::tasks($key));
        }

        $allocated = array_slice($catalogues[$key], 0, [10, 20, 30, 40, 50][$stage]);
        $approvedCount = $outcome === 'Completed' ? count($allocated)
            : max(0, [1, 7, 18, 30, 42][$stage] - 2 * ($index % 4));
        $rows = [];
        $submittedOn = $through->subDay()->setTime(15, 0);
        $stamp = now()->toDateTimeString();
        // Spread saved drafts, referrals and marking queue entries without inventing attachments.
        foreach ($allocated as $i => $task) {
            $template = $task['source'];
            $kind = $template['kind'];
            $status = $i < $approvedCount ? 'Approved' : 'Draft';
            if ($status === 'Draft' && $outcome === 'Active' && $kind !== 'knowledge') {
                if ($i === $approvedCount + 1 && $index % 3 === 0) {
                    $status = 'Awaiting marking';
                } elseif ($i === $approvedCount + 2 && $index % 4 === 0) {
                    $status = 'Referred';
                }
            }

            $answers = [];
            foreach ($template['fields'] as $field) {
                if (in_array($field['key'] ?? '', ['apprentice_name','sign_apprentice_name','apprentice_signoff'], true)) {
                    $answers[$field['name']] = $person->first_name.' '.$person->last_name;
                }
            }
            $submission = null;
            if ($status !== 'Draft') {
                $evidence = $kind === 'knowledge'
                    ? ['test_title' => 'Fictional paper knowledge check', 'marks_available' => 20, 'marks_achieved' => 17]
                    : [];
                $decision = $status !== 'Awaiting marking' ? [
                    'status' => $status, 'tutor_id' => $tutor->id, 'tutor_name' => $tutor->name,
                    'date' => $through->toDateString(), 'feedback' => $status === 'Referred'
                        ? 'Fictional feedback: explain the final safety check.' : 'Fictional example of reviewed work.',
                    'recorded_at' => $through->setTime(16, 0)->toIso8601String(), 'source_fields' => $evidence,
                ] : null;
                $submission = [[
                    'version' => 1, 'submitted_at' => $kind === 'knowledge' ? null : $submittedOn->toIso8601String(),
                    'answers' => $answers, 'template_hash' => $template['sha256'], 'evidence' => [],
                    'own_work' => $kind !== 'knowledge', 'decision' => $decision,
                ]];
            }
            $rows[] = ['apprentice_id' => $person->id, 'reference' => $task['id'], 'block' => $task['block'],
                'title' => $task['title'], 'theme' => $task['theme'], 'template' => json_encode($template, JSON_THROW_ON_ERROR),
                'answers' => json_encode($answers, JSON_THROW_ON_ERROR),
                'submissions' => $submission ? json_encode($submission, JSON_THROW_ON_ERROR) : null,
                'status' => $status, 'due_date' => $blocks[$task['block'] - 1]['end'],
                'submitted_at' => $submission && $kind !== 'knowledge' ? $submittedOn->toDateTimeString() : null,
                'created_at' => $stamp, 'updated_at' => $stamp];
        }
        DB::table('learning_tasks')->insert($rows);
        return count($rows);
    }

    private static function support(
        Apprentice $person, User $actor, User $support, int $index, CarbonImmutable $week, string $source
    ): void {
        $code = $index % 2 ? 'IM36' : 'IM38';
        $date = $week->subWeek()->toDateString();
        DB::table('apprentice_markers')->insert(['apprentice_id' => $person->id, 'code' => $code,
            'recorded_by' => $actor->id, 'created_at' => $date, 'updated_at' => $date]);
        $case = new SupportConcern(['apprentice_id' => $person->id, 'raised_by' => $actor->id, 'initial_note' => $source]);
        $case->forceFill(['code' => $code, 'owner_id' => $support->id, 'status' => 'Monitoring',
            'acknowledged_at' => $date, 'acknowledged_by' => $actor->id,
            'next_action' => 'Fictional follow-up: review progress at next register.',
            'action_due' => $week->addDays(($index % 3) - 2)->toDateString(),
            'next_review' => $week->addDays(($index % 4) - 2)->toDateString()])->save();
    }
}
