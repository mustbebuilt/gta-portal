<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\RegisterTrainingRecord;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Scoped Pack 72 adapter: structured session training delivery, personal bank & gap reports. */
final class TrainingJourney72
{
    public const CROSS_CUTTING = [
        'cc-safeguarding' => 'Safeguarding and Prevent',
        'cc-british-values' => 'British Values',
        'cc-health-safety' => 'Health, safety and workshop conduct',
        'cc-edi' => 'Equality, diversity and inclusion',
        'cc-wellbeing' => 'Wellbeing and mental health',
        'cc-careers' => 'Careers, progression and workplace behaviours',
    ];

    public const FUNCTIONAL_SKILLS = [
        'fs-maths-number' => 'Functional Skills Maths — Number and calculations',
        'fs-maths-measure' => 'Functional Skills Maths — Measure, shape and space',
        'fs-maths-data' => 'Functional Skills Maths — Handling data',
        'fs-english-reading' => 'Functional Skills English — Reading',
        'fs-english-writing' => 'Functional Skills English — Writing',
        'fs-english-speaking' => 'Functional Skills English — Speaking, listening and communicating',
    ];

    public static function catalogueForSession(TrainingSession $session): array
    {
        $programmeId = $session->group?->programme_id;
        $vocational = self::vocationalSubjects($programmeId);

        return [
            'vocational' => $vocational,
            'functional_skills' => self::FUNCTIONAL_SKILLS,
            'cross_cutting' => self::CROSS_CUTTING,
        ];
    }

    public static function vocationalSubjects(?int $programmeId): array
    {
        $key = $programmeId
            ? TaskCatalogue::keyFor(new Apprentice(['programme_id' => $programmeId]))
            : 'autocare';
        $key = $key ?? 'autocare';

        $items = [];
        for ($n = 1; $n <= 10; $n++) {
            $items["{$key}:block-{$n}"] = "Block {$n} vocational training ({$key})";
        }

        // Enrich with task themes if available
        $tasks = TaskCatalogue::tasks($key);
        $byBlock = [];
        foreach ($tasks as $task) {
            $block = (int) ($task['block'] ?? 0);
            if ($block >= 1 && $block <= 10 && ! isset($byBlock[$block]) && ! empty($task['title'])) {
                $byBlock[$block] = $task['title'];
            }
        }
        foreach ($byBlock as $n => $title) {
            $items["{$key}:block-{$n}"] = "Block {$n} — {$title}";
        }

        return $items;
    }

    public static function normaliseInput(TrainingSession $session, array $raw, array $rosterIds): ?array
    {
        $selected = $raw['training_subjects'] ?? [];
        $coverages = $raw['training_coverage'] ?? [];
        $custom = trim((string) ($raw['training_custom'] ?? ''));
        $customCoverage = ($raw['training_custom_coverage'] ?? 'delivered') === 'partial' ? 'partial' : 'delivered';
        $confirmed = (bool) ($raw['training_confirmed'] ?? false);
        $note = trim((string) ($raw['training_note'] ?? ''));
        $rawExceptions = $raw['training_exceptions'] ?? [];

        if (! $selected && $custom === '' && ! $confirmed && $note === '') {
            return null;
        }

        $catalogue = self::catalogueForSession($session);
        $allowed = [];
        foreach ($catalogue as $area => $map) {
            foreach ($map as $id => $label) {
                $allowed[$id] = ['area' => $area, 'label' => $label];
            }
        }

        $items = [];
        $selectedIds = [];
        foreach ((array) $selected as $subjectId) {
            $subjectId = (string) $subjectId;
            if (! isset($allowed[$subjectId])) {
                throw ValidationException::withMessages(['training_subjects' => 'Select subjects from the approved catalogue for this session only.']);
            }
            $cov = ($coverages[$subjectId] ?? 'delivered') === 'partial' ? 'partial' : 'delivered';
            $items[] = [
                'subjectId' => $subjectId,
                'area' => $allowed[$subjectId]['area'],
                'label' => $allowed[$subjectId]['label'],
                'coverage' => $cov,
            ];
            $selectedIds[] = $subjectId;
        }

        if ($custom !== '') {
            if (mb_strlen($custom) > 200) {
                throw ValidationException::withMessages(['training_custom' => 'Custom training entry must be 200 characters or fewer.']);
            }
            $customId = 'custom:'.substr(hash('sha256', mb_strtolower($custom)), 0, 12);
            $items[] = [
                'subjectId' => $customId,
                'area' => 'custom',
                'label' => $custom,
                'coverage' => $customCoverage,
            ];
            $selectedIds[] = $customId;
        }

        if (! $items) {
            throw ValidationException::withMessages(['training_subjects' => 'Select at least one subject or enter a custom training item before confirming structured delivery.']);
        }
        if (! $confirmed) {
            throw ValidationException::withMessages(['training_confirmed' => 'Confirm that the selected training subjects were actually delivered in this session.']);
        }

        $exceptions = [];
        foreach ((array) $rawExceptions as $apprenticeId => $ex) {
            $aid = (int) $apprenticeId;
            if (! in_array($aid, $rosterIds, true)) {
                throw ValidationException::withMessages(['training_exceptions' => 'Training exceptions must belong to the saved session roster.']);
            }
            $missed = array_values(array_intersect($selectedIds, array_map('strval', (array) ($ex['missed'] ?? []))));
            $partial = array_values(array_intersect($selectedIds, array_map('strval', (array) ($ex['partial'] ?? []))));
            if ($missed || $partial) {
                $exceptions[(string) $aid] = ['missed' => $missed, 'partial' => $partial];
            }
        }

        return [
            'items' => $items,
            'note' => $note !== '' ? mb_substr($note, 0, 2000) : null,
            'exceptions' => $exceptions,
            'confirmed' => true,
        ];
    }

    public static function saveRecord(TrainingSession $session, ?array $structured, int $actorId, int $finalRegisterVersion, ?string $correctionReason): void
    {
        if ($structured === null) {
            return;
        }

        $record = RegisterTrainingRecord::where('training_session_id', $session->id)->lockForUpdate()->first();
        $before = $record?->details;
        $payload = [
            ...$structured,
            'confirmed_by' => $actorId,
            'confirmed_at' => now()->toIso8601String(),
        ];

        if (! $record) {
            $record = new RegisterTrainingRecord;
            $record->training_session_id = $session->id;
            $record->version = 1;
            $record->history = [];
        } else {
            $record->version++;
        }

        $history = $record->history ?? [];
        $history[] = [
            'event' => $before ? 'training_updated' : 'training_recorded',
            'by' => $actorId,
            'at' => now()->toIso8601String(),
            'register_version' => $finalRegisterVersion,
            'reason' => $correctionReason,
            'before' => $before,
            'after' => $payload,
        ];

        $record->recorded_by = $actorId;
        $record->register_version = $finalRegisterVersion;
        $record->details = $payload;
        $record->history = $history;
        $record->save();
    }

    public static function requirementsFor(Apprentice $apprentice): array
    {
        $vocational = self::vocationalSubjects($apprentice->programme_id);
        $approved = DeliveryPlan::approved($apprentice);
        $dueByBlock = [];
        foreach ($approved['blocks'] ?? [] as $b) {
            if (isset($b['number'], $b['end'])) {
                $dueByBlock[(int) $b['number']] = $b['end'];
            }
        }

        $requirements = [];
        $i = 1;
        foreach ($vocational as $subjectId => $label) {
            $requirements[] = [
                'subjectId' => $subjectId,
                'area' => 'vocational',
                'label' => $label,
                'required' => true,
                'due' => $dueByBlock[$i] ?? null,
            ];
            $i++;
        }

        return $requirements;
    }

    public static function forApprentice(Apprentice $apprentice, ?string $today = null): array
    {
        $today ??= today()->toDateString();
        $marks = AttendanceMark::with(['session.group.tutor'])
            ->where('apprentice_id', $apprentice->id)
            ->get();

        $sessionIds = $marks->pluck('training_session_id')->unique()->all();
        $records = RegisterTrainingRecord::whereIn('training_session_id', $sessionIds)->get()->keyBy('training_session_id');

        $dtos = [];
        $legacySessions = [];
        $staleSessions = [];

        foreach ($marks as $mark) {
            $session = $mark->session;
            if (! $session || $session->state === 'Cancelled') {
                continue;
            }
            $rec = $records->get($session->id);
            $attended = in_array($mark->code, ['O', 'T'], true);

            if (! $rec) {
                if ($attended) {
                    $legacySessions[] = [
                        'id' => $session->id,
                        'name' => $session->name,
                        'date' => $session->session_date->toDateString(),
                        'group' => $session->group?->name ?? 'Unassigned group',
                        'delivered_learning' => $session->delivered_learning,
                    ];
                }

                continue;
            }

            if ($rec->register_version !== $session->version) {
                if ($attended) {
                    $staleSessions[] = [
                        'id' => $session->id,
                        'name' => $session->name,
                        'date' => $session->session_date->toDateString(),
                        'group' => $session->group?->name ?? 'Unassigned group',
                    ];
                }
            }

            $details = $rec->details ?? [];
            $ex = $details['exceptions'][(string) $apprentice->id] ?? [];
            $dtos[] = [
                'id' => $session->id,
                'date' => $session->session_date->toDateString(),
                'group' => $session->group?->name ?? 'Group',
                'tutor' => $session->group?->tutor?->name ?? 'Staff',
                'cancelled' => $session->state === 'Cancelled',
                'confirmed' => (bool) ($details['confirmed'] ?? false),
                'register_version' => $session->version,
                'delivery_register_version' => $rec->register_version,
                'items' => $details['items'] ?? [],
                'marks' => [[
                    'id' => (string) $apprentice->id,
                    'code' => $mark->code,
                    'missed' => $ex['missed'] ?? [],
                    'partial' => $ex['partial'] ?? [],
                ]],
            ];
        }

        // The personal bank and profile use the same current register source and approved requirements.
        $receipts = RegisterTraining::receipts($apprentice->id, $today);
        $journey = RegisterTraining::coverage($apprentice, $receipts, DeliveryPlan::approved($apprentice), $today);

        $counts = ['delivered' => 0, 'overdue' => 0, 'partial' => 0, 'due' => 0, 'planned' => 0, 'unscheduled' => 0];
        foreach ($journey as $row) {
            if (isset($counts[$row['state']])) {
                $counts[$row['state']]++;
            }
        }

        return [
            'today' => $today,
            'receipts' => $receipts,
            'journey' => $journey,
            'counts' => $counts,
            'legacy_sessions' => $legacySessions,
            'stale_sessions' => $staleSessions,
        ];
    }

    public static function gapsReport(User $actor, ?int $groupId = null, string $search = ''): array
    {
        $query = Apprentice::visibleTo($actor)->with(['groupRecord', 'programmeRecord']);
        if ($groupId) {
            $query->where('tutor_group_id', $groupId);
        }
        $search = trim($search);
        if ($search !== '') {
            $query->where(fn ($q) => $q
                ->where('reference', 'like', '%'.$search.'%')
                ->orWhere('first_name', 'like', '%'.$search.'%')
                ->orWhere('last_name', 'like', '%'.$search.'%'));
        }

        $apprentices = $query->orderBy('last_name')->orderBy('first_name')->get();
        $rows = [];
        $totals = ['apprentices' => $apprentices->count(), 'with_overdue' => 0, 'total_overdue' => 0, 'total_delivered' => 0, 'with_legacy' => 0];

        foreach ($apprentices as $a) {
            $data = self::forApprentice($a);
            if ($data['counts']['overdue'] > 0) {
                $totals['with_overdue']++;
                $totals['total_overdue'] += $data['counts']['overdue'];
            }
            $totals['total_delivered'] += $data['counts']['delivered'];
            if (count($data['legacy_sessions']) > 0) {
                $totals['with_legacy']++;
            }
            $rows[] = [
                'apprentice' => $a,
                'counts' => $data['counts'],
                'receipt_count' => count($data['receipts']),
                'legacy_count' => count($data['legacy_sessions']),
                'stale_count' => count($data['stale_sessions']),
            ];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }
}
