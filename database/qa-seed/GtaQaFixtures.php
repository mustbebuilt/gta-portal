<?php

declare(strict_types=1);

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\Programme;
use App\Models\ProgrammeOutcome;
use App\Models\SupportCapability;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\AssignmentHistory;
use App\Support\EntryContext;
use App\Support\LearningAccess;
use App\Support\TaskCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class GtaQaFixtures
{
    private const SIZE = 250;
    private const PRACTICAL_WEEKS = [128, 148, 148, 148];
    private const TRAINING_HOURS = [600, 800, 760, 840];
    private const EMPLOYER_NAMES = [
        'Silver Quay Vehicle Works', 'Cinder Glen Fleet Workshop', 'Bracken Heath Fleet Care', 'Orbit Reed Panel Studio',
        'Sandvale Paint Works', 'Copper Birch Auto Care', 'Walnut Beck Garage', 'Windlasp Vehicle Centre',
        'Dapple Fen Commercials', 'Hollow Reed Motor Works', 'Flint Harbour Repairs', 'White Acorn Bodyshop',
        'Sable Elm Auto Services', 'Moor Vale Refinishing', 'Wrenbrook Mechanics', 'Slate Lantern Fleet Care',
        'Marsh Kestrel Workshop', 'Russet Gate Motors', 'Frostwater Coachworks', 'Amber Croft Automotive',
    ];
    private const FIRST = ['Mira','Ellis','Noor','Rowan','Anika','Jude','Lina','Idris','Sasha','Ren','Dara','Toby','Asha','Owen','Leah','Yara','Evan','Milo','Zara','Kai','Amara','Iona','Dev','Nico','Freya'];
    private const LAST = ['Venn','Croft','Alder','Pike','Reed','Marlow','Vale','Keel','Wren','Ashby','Fenwick','Lark','Hale','Brooke','Berry','Blythe','Saye','Shaw','Grove','Park'];
    private const STAFF = [
        'TUTOR-1' => ['Training Tutor One', 'tutor', null],
        'TUTOR-2' => ['Training Tutor Two', 'tutor', null],
        'TUTOR-3' => ['Training Tutor Three', 'tutor', null],
        'MATHS' => ['Maths Tutor', 'functional_skills_tutor', 'maths'],
        'ENGLISH' => ['English Tutor', 'functional_skills_tutor', 'english'],
        'SUPPORT' => ['Inclusion Team Member', 'inclusion_member', null],
        'MENTOR' => ['Progress Mentor', 'mentor', null],
    ];

    public static function run(
        string $batch, ?int $actorId, string $inbox, CarbonImmutable $week, bool $apply,
        bool $verified, ?Closure $writeCredentials = null
    ): array {
        if (!preg_match('/\AGTAQA-[A-Z0-9]{1,12}\z/', $batch)) {
            throw new InvalidArgumentException('Use a batch such as GTAQA-250.');
        }
        $today = CarbonImmutable::now('Europe/London');
        if (!$week->isMonday() || $week->gt($today->startOfWeek(CarbonImmutable::MONDAY))) {
            throw new InvalidArgumentException('Use a Monday on or before the current UK week.');
        }
        if (!filter_var($inbox, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Supply the test inbox with --inbox=ADDRESS.');
        }
        [$local, $domain] = explode('@', $inbox, 2);
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $local) || strlen($local.'+'.strtolower($batch).'-tutor-1') > 64) {
            throw new InvalidArgumentException('Use a base mailbox address that can receive plus-addresses.');
        }
        $alias = fn (string $key): string => strtolower($local.'+'.$batch.'-'.$key.'@'.$domain);

        $actor = $actorId ? User::find($actorId) : null;
        if (($actorId || $apply) && (!$actor || !LearningAccess::manager($actor))) {
            throw new InvalidArgumentException('For an import, specify an existing active admin or CEO with --actor=ID.');
        }
        if ($apply && $writeCredentials === null) {
            throw new InvalidArgumentException('For an import, supply a private new credentials file.');
        }
        $counts = ['apprentices' => self::SIZE, 'employers' => 20, 'tutor_groups' => 20,
            'staff_accounts' => count(self::STAFF), 'apprentice_accounts' => self::SIZE];
        $references = [
            'employers' => array_map(fn ($i) => self::ref($batch, 'E', $i), range(0, 19)),
            'tutor_groups' => array_map(fn ($i) => self::ref($batch, 'G', $i), range(0, 19)),
            'apprentices' => array_map(fn ($i) => self::ref($batch, 'A', $i), range(0, self::SIZE - 1)),
        ];
        $emails = array_map($alias, array_merge(array_keys(self::STAFF),
            array_map(fn ($i) => sprintf('A-%03d', $i + 1), range(0, self::SIZE - 1))));

        $requiredTables = ['employers', 'tutor_groups', 'apprentices', 'users', 'programmes', 'assignment_change_events',
            'apprentice_entry_contexts', 'entry_context_events', 'programme_outcomes', 'individual_delivery_plans',
            'learning_tasks', 'training_sessions', 'attendance_marks', 'support_concerns', 'apprentice_markers',
            'support_capabilities', 'training_topics', 'qualifications'];
        $missingTables = array_values(array_filter($requiredTables, fn ($table) => !Schema::hasTable($table)));
        $result = ['batch' => $batch, 'week_of' => $week->toDateString(), 'counts' => $counts];
        if ($missingTables !== []) {
            return $result + ['status' => 'schema_missing', 'missing_tables' => $missingTables, 'wrote' => false];
        }
        [$programmes, $missing, $templates] = self::programmes();
        $result += ['missing_active_programmes' => $missing, 'active_guided_template_rows' => $templates];

        return DB::transaction(function () use ($references, $result, $apply, $actor, $programmes, $batch, $week,
            $alias, $emails, $verified, $writeCredentials) {
            $existing = [];
            foreach ($references as $table => $ids) {
                $existing[$table] = DB::table($table)->whereIn('reference', $ids)->count();
            }
            $accountMatches = User::whereIn(DB::raw('LOWER(email)'), $emails)->count();
            if (array_sum($existing) > 0 || $accountMatches > 0) {
                $complete = $existing === array_map('count', $references) && $accountMatches === count($emails);
                return $result + ['status' => $complete ? 'references_already_present' : 'some_references_already_present',
                    'existing_reference_counts' => $existing, 'existing_aliases' => $accountMatches, 'wrote' => false];
            }
            if ($result['missing_active_programmes'] !== [] || !$apply) {
                return $result + ['status' => $result['missing_active_programmes'] ? 'missing_programmes' : 'ready_to_import',
                    'wrote' => false];
            }

            $credentials = [];
            $added = self::insert($batch, $week, $actor, $programmes, $alias, $verified, $credentials);
            $writeCredentials($credentials);
            return array_replace($result, ['status' => 'imported', 'wrote' => true, 'counts' => $result['counts'] + $added]);
        });
    }

    private static function programmes(): array
    {
        $found = [];
        $missing = [];
        $templates = [];
        $canCheck = Schema::hasTable('programme_setup_templates68');
        foreach (TaskCatalogue::PROGRAMMES as $key => $spec) {
            $code = $spec['code'];
            $version = $spec['version'];
            $programme = Programme::where('standard_code', $code)->where('version', $version)->where('active', true)->first();
            if (!$programme) {
                $missing[] = "$code v$version";
                continue;
            }
            $found[$key] = $programme;
            $templates["$code v$version"] = $canCheck && DB::table('programme_setup_templates68')
                ->where('programme_id', $programme->id)->where('active', true)->exists();
        }
        return [$found, $missing, $templates];
    }

    private static function ref(string $batch, string $type, int $index): string
    {
        return sprintf('%s-%s-%03d', $batch, $type, $index + 1);
    }

    private static function account(string $name, string $role, ?string $subject, string $email, bool $verified, array &$credentials): User
    {
        $password = 'GtaQa'.Str::password(22, true, true, true, false);
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => $role, 'subject_scope' => $subject,
            'email_verified_at' => $verified ? now() : null])->save();
        $credentials[] = [$role, $name, $email, $password];
        return $user;
    }

    private static function insert(
        string $batch, CarbonImmutable $week, User $actor, array $programmes, Closure $alias, bool $verified, array &$credentials
    ): array {
        $staff = [];
        foreach (self::STAFF as $key => [$name, $role, $subject]) {
            $staff[$key] = self::account("Fictional $batch $name", $role, $subject, $alias($key), $verified, $credentials);
        }

        $employers = [];
        foreach (self::EMPLOYER_NAMES as $i => $name) {
            $employers[$i] = Employer::create(['reference' => self::ref($batch, 'E', $i), 'name' => "Fictional · $name",
                'contact_email' => strtolower(self::ref($batch, 'E', $i)).'@example.invalid']);
        }
        $groups = [];
        $keys = array_keys($programmes);
        $staffTutors = [$staff['TUTOR-1'], $staff['TUTOR-2'], $staff['TUTOR-3']];
        for ($i = 0; $i < 20; $i++) {
            $pi = $i % 4;
            $key = $keys[$pi];
            $tutor = $staffTutors[$i % 3];
            $stage = intdiv($i, 4);
            $elapsed = [5, 24, 55, 90, self::PRACTICAL_WEEKS[$pi] + 8][$stage];
            $group = TutorGroup::create(['reference' => self::ref($batch, 'G', $i),
                'name' => 'Fictional '.$batch.' '.$programmes[$key]->standard_code.' '.($stage + 1),
                'programme_id' => $programmes[$key]->id, 'tutor_id' => $tutor->id]);
            $groups[$i] = compact('pi', 'key', 'tutor', 'stage', 'elapsed', 'group') + ['people' => []];
        }
        SupportCapability::create(['user_id' => $staff['SUPPORT']->id, 'duty' => 'member', 'scope' => 'assigned',
            'group_ids' => array_map(fn ($group) => $group['group']->id, $groups), 'apprentice_ids' => []]);

        $added = ['learning_tasks' => 0, 'delivery_plans' => 0, 'support_concerns' => 0, 'training_sessions' => 0, 'attendance_marks' => 0];
        for ($i = 0; $i < self::SIZE; $i++) {
            $groupIndex = $i % 20;
            ['pi' => $pi, 'key' => $key, 'tutor' => $tutor, 'stage' => $stage, 'elapsed' => $elapsed, 'group' => $group] = $groups[$groupIndex];
            $employerIndex = in_array($i, [0, 20], true) ? 0 : (intdiv($i, 20) + $groupIndex % 8) % 20;
            $person = self::person($batch, $i, $week, $actor, $staff, $tutor, $group, $programmes[$key],
                $employers[$employerIndex], $alias, $verified, $credentials, $stage, $elapsed, $pi, $key, $added);
            $groups[$groupIndex]['people'][$i] = $person->id;
        }

        foreach ($groups as $spec) {
            self::registers($batch, $week, $actor, $spec, $added);
        }
        return $added;
    }

    private static function person(
        string $batch, int $i, CarbonImmutable $week, User $actor, array $staff, User $tutor, TutorGroup $group,
        Programme $programme, Employer $employer, Closure $alias, bool $verified, array &$credentials,
        int $stage, int $elapsed, int $pi, string $key, array &$added
    ): Apprentice {
        $start = $week->subWeeks($elapsed);
        $through = $week->subDay();
        $reference = self::ref($batch, 'A', $i);
        $first = self::FIRST[$i % count(self::FIRST)];
        $last = self::LAST[intdiv($i, count(self::FIRST))].' (Fictional)';
        $login = self::account("$first $last · $reference", 'apprentice', null, $alias(sprintf('A-%03d', $i + 1)), $verified, $credentials);
        $person = new Apprentice([
            'reference' => $reference, 'first_name' => $first, 'last_name' => $last, 'email' => $login->email,
            'programme_id' => $programme->id, 'programme' => $programme->name, 'employer_id' => $employer->id,
            'employer' => $employer->name, 'tutor_group_id' => $group->id, 'group_name' => $group->name,
            'tutor_id' => $tutor->id, 'mentor_id' => $staff['MENTOR']->id, 'maths_tutor_id' => $staff['MATHS']->id,
            'english_tutor_id' => $staff['ENGLISH']->id, 'start_date' => $start->toDateString(),
            'planned_end_date' => GtaQaProgress::plannedEnd($start, self::PRACTICAL_WEEKS[$pi])->toDateString(),
        ]);
        $person->forceFill(['user_id' => $login->id])->save();
        AssignmentHistory::record($person, $actor->id, null);
        EntryContext::save($actor, $person, [
            'stage' => 'Enrolment', 'confirmed_on' => $start->toDateString(), 'entry_employer_id' => $employer->id,
            'employment_start_date' => $start->toDateString(), 'already_employed' => 'Yes',
            'recruitment_route' => ['School or careers adviser','Employer referral','GTA website or direct enquiry','Recruitment event or outreach'][$i % 4],
            'employment_route' => 'Already employed by the employer', 'family_owned' => 'No', 'family_owner_manager' => 'No',
            'family_supervisor' => 'No', 'relationship' => 'Not applicable',
            'work_experience' => ['Relevant paid work','Relevant unpaid work or placement','No relevant experience'][$i % 3],
            'prior_apprenticeship' => $i % 13 === 0 ? 'Started but not completed' : 'None', 'career_match' => $i % 5 ? 'Yes' : 'Partly',
        ], 0);
        $status = match (true) {
            $stage === 4 && $i % 3 === 0 => 'Completed',
            $stage >= 1 && $i % 19 === 17 => 'Withdrawn',
            $stage >= 1 && $i % 23 === 10 => 'Break in learning',
            default => 'Active',
        };
        $subjects = ['maths' => $i % 3 !== 0 ? 'Yes' : 'No', 'english' => $i % 4 !== 0 ? 'Yes' : 'No'];
        $context = ['status' => $status, 'sourceRef' => "Fictional test fixture $batch", 'age' => ['16–18','19–24','25+'][$i % 3]] + $subjects;
        if ($status === 'Completed' || $status === 'Withdrawn') {
            $context['endDate'] = $through->toDateString();
            if ($status === 'Completed') {
                $context += ['achievement' => 'Achieved', 'achievementDate' => $through->toDateString()];
            } else {
                $context['reason'] = 'Other';
            }
        }
        ProgrammeOutcome::create(['apprentice_id' => $person->id, 'context' => $context]);
        $progress = GtaQaProgress::seed($person, $actor, $staff['SUPPORT'], $tutor, $key, $i, $stage, self::PRACTICAL_WEEKS[$pi],
            self::TRAINING_HOURS[$pi], $start, $week, $through, $status, $subjects);
        foreach ($progress as $name => $total) {
            $added[$name] += $total;
        }
        return $person;
    }

    private static function registers(string $batch, CarbonImmutable $week, User $actor, array $spec, array &$added): void
    {
        ['pi' => $pi, 'elapsed' => $elapsed, 'group' => $group, 'people' => $people] = $spec;
        $start = $week->subWeeks($elapsed);
        $stamp = now()->toDateTimeString();
        for ($offset = 0; $offset < $elapsed; $offset++) {
            $date = $start->addWeeks($offset)->addDays(2);
            $session = TrainingSession::create([
                'tutor_group_id' => $group->id, 'created_by' => $actor->id,
                'name' => "Fictional $batch workshop register", 'session_date' => $date->toDateString(),
                'start_time' => '09:00', 'end_time' => '16:00', 'planned_learning' => 'Fictional workshop practice',
                'breaks' => [['start' => '12:00', 'end' => '13:00']], 'members' => array_values($people),
            ]);
            $added['training_sessions']++;
            $marks = [];
            foreach ($people as $i => $id) {
                $code = self::attendanceCode($i, $offset, $elapsed, self::PRACTICAL_WEEKS[$pi]);
                if ($code === null) {
                    continue;
                }
                $attended = in_array($code, ['O', 'T'], true);
                $minutes = $attended ? [360, 330, 270, 240, 300][$i % 5] : null;
                $marks[] = ['training_session_id' => $session->id, 'apprentice_id' => $id, 'recorded_by' => $actor->id,
                    'code' => $code, 'arrival' => $attended ? '09:00' : null, 'departure' => $attended ? '16:00' : null,
                    'parts' => $attended ? '[[540,720],[780,960]]' : '[]', 'otj_confirmed' => $attended,
                    'otj_minutes' => $minutes, 'created_at' => $stamp, 'updated_at' => $stamp];
            }
            if ($marks !== []) {
                DB::table('attendance_marks')->insert($marks);
            }
            $added['attendance_marks'] += count($marks);
        }
    }

    private static function attendanceCode(int $i, int $offset, int $elapsed, int $practical): ?string
    {
        if ($offset >= $practical) {
            return 'E';
        }
        if ($offset >= $elapsed - 2 && in_array($i, [0, 20], true)) {
            return 'W';
        }
        if ($offset === $elapsed - 1 && $i % 19 === 6) {
            return null;
        }
        return match (($i * 13 + $offset * 7) % 31) {
            0 => 'A', 1 => 'S', 2 => 'W', 3 => 'T', 4 => 'H', default => 'O',
        };
    }
}
