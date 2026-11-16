<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\LearningTask;
use App\Models\Programme;
use App\Models\ProgrammeOutcome;
use App\Models\ProgressReviewDraft;
use App\Models\Qualification;
use App\Models\SmartAction;
use App\Models\SupportConcern;
use App\Models\SupportPlan;
use App\Models\TimetablePlanEntry;
use App\Models\TimetableRoom;
use App\Models\TrainingSession;
use App\Models\TrainingTopic;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\CentreManagerAccess;
use App\Support\CentrePlanning;
use App\Support\EmployerActions;
use App\Support\GatewayReadiness;
use App\Support\GatewaySummary;
use App\Support\JourneyEvents;
use App\Support\LearningAccess;
use App\Support\OtjPosition;
use App\Support\ReviewedImports;
use App\Support\SignedReviews;
use App\Support\StaffDuties;
use App\Support\SupportAccess;
use App\Support\TaskCatalogue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** One isolated shared cohort:100 learner accounts plus20 staff/employer accounts, no external delivery. */
class PortalCohort100Test extends TestCase
{
    use LazilyRefreshDatabase;

    private array $report = ['cohort' => 100, 'non_apprentice_users' => 20, 'total_accounts' => 120, 'permission_matrix' => [], 'http_checks' => [], 'interaction_checks' => [], 'login_checks' => [], 'real_data_used' => false, 'actual_cloud' => false, 'actual_native_database' => false, 'browser_or_phone_tested' => false, 'concurrent_load_test' => false, 'external_delivery_enabled' => false];

    private string $password = 'Fictional-Isolated-Cohort60-Only!';

    private function act(User $u): static
    {
        return $this->actingAs($u->fresh())->withSession(['auth_version' => $u->fresh()->auth_version]);
    }

    private function loginAccount(User $u): void
    {
        $this->post('/logout');
        $this->flushSession();
        Auth::forgetGuards();
        $r = $this->post('/login', ['email' => $u->email, 'password' => $this->password]);
        $r->assertSessionHasNoErrors()->assertRedirect();
        $this->assertAuthenticatedAs($u);
        $this->report['login_checks'][] = ['account' => $u->name, 'role' => $u->role, 'password_login_passed' => true];
    }

    private function page(User $u, Apprentice $a, string $module, bool $allow): void
    {
        $routes = ['tasks' => 'learning.index', 'journey' => 'journey.show', 'otj' => 'otj.show', 'review' => 'reviews.create', 'gateway' => 'gateway.show', 'maths_english' => 'em.index', 'careers' => 'careers.index', 'private_support' => 'support.plan.new'];
        $start = microtime(true);
        $response = $this->act($u)->get(route($routes[$module], $a));
        $expected = $allow ? 200 : 403;
        $response->assertStatus($expected);
        $this->report['http_checks'][] = ['user' => $u->name, 'role' => $u->role, 'apprentice' => $a->reference, 'module' => $module, 'expected' => $expected, 'actual' => $response->status(), 'milliseconds' => round((microtime(true) - $start) * 1000, 2)];
    }

    private function step(string $name, array $evidence = []): void
    {
        $this->report['interaction_checks'][] = ['name' => $name, 'passed' => true, 'evidence' => $evidence];
        $this->saveReport(false);
    }

    private function saveReport(bool $finished): void
    {
        if ($p = getenv('GTA_SCALE_REPORT')) {
            $this->report['completed'] = $finished;
            $this->report['peak_php_memory_bytes'] = memory_get_peak_usage(true);
            file_put_contents($p, json_encode($this->report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    public function test_hundred_apprentices_twenty_staff_users_and_cross_area_workflows(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(12, 0));
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();
        $started = microtime(true);
        try {
            $hash = Hash::make($this->password);
            $staff = [];
            $definitions = ['admin-a' => 'admin', 'admin-b' => 'admin', 'ceo' => 'ceo', 'tutor-1' => 'tutor', 'tutor-2' => 'tutor', 'tutor-3' => 'tutor', 'tutor-4' => 'tutor', 'tutor-5' => 'tutor', 'mentor-1' => 'mentor', 'mentor-2' => 'mentor', 'inclusion-lead' => 'inclusion_lead', 'inclusion-member' => 'inclusion_member', 'careers-1' => 'careers_adviser', 'careers-2' => 'careers_adviser', 'maths' => 'functional_skills_tutor', 'english' => 'functional_skills_tutor', 'employer-1' => 'employer', 'employer-2' => 'employer', 'centre-manager-1' => 'manager', 'centre-manager-2' => 'manager'];
            foreach ($definitions as $key => $role) {
                $staff[$key] = User::factory()->create(['name' => 'Fictional60 '.$key, 'email' => $key.'@cohort60.example.test', 'role' => $role, 'password' => $hash, 'subject_scope' => $key === 'maths' ? 'maths' : ($key === 'english' ? 'english' : null)]);
            }
            $programmes = [];
            foreach (TaskCatalogue::PROGRAMMES as $key => $p) {
                $programmes[$key] = Programme::factory()->create(['name' => 'Fictional60 '.$p['name'], 'standard_code' => $p['code'], 'version' => $p['version'], 'active' => true]);
            }
            $employers = [Employer::factory()->create(['name' => 'Fictional60 employer one']), Employer::factory()->create(['name' => 'Fictional60 employer two'])];
            $groups = [];
            foreach ($programmes as $key => $p) {
                foreach (range(1, 5) as $n) {
                    $groups[$key][$n] = TutorGroup::factory()->create(['name' => 'Fictional60 '.$key.' '.$n, 'programme_id' => $p->id, 'tutor_id' => $staff['tutor-'.$n]->id, 'active' => true]);
                }
            }
            $people = [];
            $learners = [];
            $keys = array_keys($programmes);
            $grants = [];
            $cases = [];
            foreach (range(0, 99) as $i) {
                $key = $keys[$i % 4];
                $n = $i % 5 + 1;
                $side = $i < 50 ? 0 : 1;
                $learner = User::factory()->create(['role' => 'apprentice', 'name' => 'Fictional learner60 '.($i + 1), 'email' => 'learner'.($i + 1).'@cohort60.example.test', 'password' => $hash]);
                $a = Apprentice::factory()->create(['reference' => 'SCALE60-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'first_name' => 'Fictional', 'last_name' => 'Cohort60 '.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'user_id' => $learner->id, 'email' => $learner->email, 'programme_id' => $programmes[$key]->id, 'tutor_group_id' => $groups[$key][$n]->id, 'tutor_id' => $staff['tutor-'.$n]->id, 'mentor_id' => $staff['mentor-'.($side + 1)]->id, 'careers_adviser_id' => $staff['careers-'.($side + 1)]->id, 'maths_tutor_id' => $staff['maths']->id, 'english_tutor_id' => $staff['english']->id, 'employer_id' => $employers[$side]->id, 'start_date' => '2026-09-01', 'planned_end_date' => '2029-09-01']);
                $people[] = $a;
                $learners[] = $learner;
                ProgrammeOutcome::factory()->create(['apprentice_id' => $a->id, 'context' => ['status' => 'Active', 'sourceRef' => 'Fictional checked programme60']]);
                $cases[$a->id] = SupportConcern::factory()->create(['apprentice_id' => $a->id, 'raised_by' => $staff['inclusion-lead']->id, 'initial_note' => 'PRIVATE_SUPPORT60_'.$a->reference]);
                $cases[$a->id]->forceFill(['code' => 'IM36', 'status' => 'Monitoring', 'owner_id' => $staff['inclusion-lead']->id, 'acknowledged_at' => now(), 'next_action' => 'Fictional private support action', 'action_due' => today()->addDays(4), 'next_review' => today()->addDays(7)])->save();
                $grants[$a->id] = EmployerActions::grant($staff['admin-a'], 1, $a, ['user_id' => $staff['employer-'.($side + 1)]->id, 'version' => 0, 'identity_reference' => 'Fictional individual workplace authority60']);
            }
            $this->assertDatabaseCount('apprentices', 100);
            $this->assertDatabaseCount('users', 120);
            $this->assertCount(20, $staff);
            $this->act($staff['admin-a'])->post('/staff-support-permissions/'.$staff['inclusion-member']->id, ['version' => 0, 'duty' => 'member', 'scope' => 'assigned', 'apprentice_ids' => array_map(fn ($a) => $a->id, array_slice($people, 0, 50)), 'group_ids' => [], 'reason' => 'Fictional scoped inclusion60'])->assertSessionHasNoErrors();
            $manager = $staff['centre-manager-1'];
            $scope = [$people[0]->tutor_group_id, $people[1]->tutor_group_id];
            $areas = ['training', 'attendance', 'reviews', 'functional_skills'];
            $expires = today()->addDays(30)->toDateString();
            $preview = CentreManagerAccess::preview($staff['admin-a'], $manager, $scope, $areas, $expires, false);
            $request = CentreManagerAccess::prepare($staff['admin-a'], 1, $manager, $scope, $areas, $expires, false, $preview['source_hash'], (string) Str::uuid(), 'Fictional centre remit60');
            CentreManagerAccess::review($staff['admin-b'], 1, $request, 1, true, 'Fictional independent remit check60');
            CentreManagerAccess::apply($staff['admin-a'], 1, $request->fresh(), 2);
            $this->loginAccount($staff['admin-a']);
            foreach ($people as $a) {
                $this->post(route('learning.allocate', $a), ['confirmed' => 1])->assertSessionHasNoErrors()->assertRedirect();
                $this->assertSame(50, LearningTask::where('apprentice_id', $a->id)->count());
            }$this->assertDatabaseCount('learning_tasks', 5000);
            $this->post(route('learning.allocate', $people[0]), ['confirmed' => 1])->assertSessionHasErrors('confirmed');
            $this->assertDatabaseCount('learning_tasks', 5000);
            $this->step('100 explicit supported programme allocations create exactly5000 source tasks; duplicate allocation refused', ['programmes' => 4, 'groups' => 20, 'tasks' => 5000]);
            foreach ($staff as $u) {
                $this->loginAccount($u);
                $eligible = [];
                foreach ($people as $i => $a) {
                    $admin = in_array($u->role, ['admin', 'ceo'], true);
                    $training = $admin || ($u->role === 'tutor' && $a->tutor_id === $u->id);
                    $mentor = $u->role === 'mentor' && $a->mentor_id === $u->id;
                    $careers = $u->role === 'careers_adviser' && $a->careers_adviser_id === $u->id;
                    $private = $admin || $u->role === 'inclusion_lead' || ($u->role === 'inclusion_member' && $i < 50);
                    $fs = $u->role === 'functional_skills_tutor' && (($u->subject_scope === 'maths' && $a->maths_tutor_id === $u->id) || ($u->subject_scope === 'english' && $a->english_tutor_id === $u->id));
                    $expected = ['tasks' => $training, 'journey' => $training, 'otj' => $training, 'review' => $training || $mentor, 'gateway' => $training, 'maths_english' => $training || $fs, 'careers' => $admin || $careers, 'private_support' => $private];
                    $actual = ['tasks' => LearningAccess::training($u, $a), 'journey' => LearningAccess::training($u, $a), 'otj' => LearningAccess::training($u, $a), 'review' => SignedReviews::allowed($u, $a), 'gateway' => GatewayReadiness::allowed($u, $a), 'maths_english' => LearningAccess::training($u, $a) || LearningAccess::subject($u, $a, 'maths') || LearningAccess::subject($u, $a, 'english'), 'careers' => LearningAccess::manager($u) || StaffDuties::assigned($u, $a, 'careers_adviser'), 'private_support' => SupportAccess::team($u, $a)];
                    foreach ($expected as $module => $allow) {
                        $this->assertSame($allow, $actual[$module], $u->name.' '.$a->reference.' '.$module);
                        $eligible[$module][$allow ? 'allow' : 'deny'][] = $a;
                    }$this->report['permission_matrix'][] = ['user' => $u->name, 'role' => $u->role, 'apprentice' => $a->reference, 'expected' => $expected, 'matched' => true];
                }
                foreach ($eligible as $module => $sets) {
                    foreach ($sets as $decision => $rows) {
                        $this->page($u, $rows[0], $module, $decision === 'allow');
                    }
                }
            }
            $this->step('20 staff/employer accounts have real password logins and16000 independent role/apprentice/module predicate assertions', ['staff_users' => 20, 'apprentices' => 100, 'modules' => 8, 'predicate_assertions' => 16000]);
            foreach ($people as $i => $a) {
                $this->loginAccount($learners[$i]);
                $this->get(route('learning.index', $a))->assertOk()->assertSee($a->first_name);
                $this->get(route('experience.home'))->assertOk()->assertSee('What is left before Gateway?')->assertDontSee('PRIVATE_SUPPORT60_');
                $this->get(route('journey.show', $a))->assertOk()->assertDontSee('PRIVATE_SUPPORT60_');
                $foreign = $people[($i + 1) % 100];
                $this->get(route('learning.index', $foreign))->assertForbidden();
                $this->get(route('support.plan.new', $a))->assertForbidden();
                $this->report['http_checks'][] = ['user' => $learners[$i]->name, 'role' => 'apprentice', 'apprentice' => $a->reference, 'module' => 'own_tasks_home_journey_foreign_denial_private_support_denial', 'requests' => 5, 'passed' => true];
            }
            $this->step('All100 learners authenticate and access own tasks/home/journey; foreign work and private support forms denied', ['learner_password_logins' => 100, 'http_requests' => 500]);
            $a = $people[0];
            $tutor = $staff['tutor-1'];
            $learner = $learners[0];
            $task = LearningTask::where('apprentice_id', $a->id)->where('reference', 'b1-t4')->firstOrFail();
            $field = collect($task->template['fields'])->first(fn ($f) => $f['role'] === 'apprentice' && in_array($f['key'], ['apprentice_name', 'sign_apprentice_name', 'apprentice_signoff']));
            $answers = [$field['name'] => $learner->name];
            $this->act($learner)->post(route('tasks.save', $task), ['version' => $task->version, 'intent' => 'draft', 'answers' => $answers])->assertSessionHasNoErrors();
            $photo = UploadedFile::fake()->createWithContent('fictional-photo60.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
            $this->post(route('tasks.upload', $task), ['version' => $task->fresh()->version, 'file' => $photo, 'kind' => 'supporting-evidence'])->assertSessionHasNoErrors();
            $this->post(route('tasks.save', $task), ['version' => $task->fresh()->version, 'intent' => 'submit', 'own_work' => 1, 'answers' => $answers])->assertSessionHasNoErrors();
            $this->assertSame('Awaiting marking', $task->fresh()->status);
            $this->act($tutor)->post('/tasks/'.$task->id.'/decision', ['version' => $task->fresh()->version, 'decision' => 'Referred', 'decision_date' => today()->toDateString(), 'feedback' => 'Fictional correction: explain the measurement.', 'confirmed' => 1])->assertSessionHasNoErrors();
            $this->act($learner)->post(route('tasks.save', $task), ['version' => $task->fresh()->version, 'intent' => 'submit', 'own_work' => 1, 'answers' => $answers])->assertSessionHasNoErrors();
            $this->act($tutor)->post('/tasks/'.$task->id.'/decision', ['version' => $task->fresh()->version, 'decision' => 'Approved', 'decision_date' => today()->toDateString(), 'confirmed' => 1])->assertSessionHasNoErrors();
            $this->assertSame('Approved', $task->fresh()->status);
            $this->assertCount(2, $task->fresh()->submissions);
            $this->assertDatabaseCount('task_evidence', 1);
            $this->step('Photo evidence, draft, submission, referral, resubmission and whole-task approval retain source history');
            $date = today()->addDay()->toDateString();
            $room = TimetableRoom::where('site', 'Doncaster')->firstOrFail();
            CentrePlanning::room($staff['admin-a'], 1, $room, 1, ['capacity' => 8, 'uses' => ['Teaching', 'Admin', 'Review'], 'active' => true]);
            CentrePlanning::windows($staff['admin-a'], 1, $tutor, [$date], ReviewedImports::hash(CentrePlanning::windowSource($tutor, [$date])), true, '08:00', '18:00');
            $plan = CentrePlanning::save($staff['admin-a'], 1, null, 0, (string) Str::uuid(), ['staff_id' => $tutor->id, 'date' => $date, 'weeks' => 1, 'start' => '09:00', 'end' => '16:00', 'kind' => 'Teaching', 'site' => 'Doncaster', 'room_id' => $room->id, 'group_id' => $a->tutor_group_id, 'headcount' => 5, 'title' => 'Fictional cohort60 teaching']);
            CentrePlanning::check($staff['admin-a'], 1, $plan, 1, true);
            CentrePlanning::publish($staff['admin-a'], 1, $plan->fresh(), 2);
            $entry = TimetablePlanEntry::where('plan_id', $plan->id)->firstOrFail();
            $this->act($staff['admin-a'])->post(route('occurrence-register.create', $entry), ['version' => $plan->fresh()->version, 'planned_learning' => 'Fictional actual practical learning60', 'breaks_checked' => 1, 'breaks' => [['start' => '12:00', 'end' => '13:00']], 'confirmed' => 1])->assertSessionHasNoErrors();
            $session = TrainingSession::where('tutor_group_id', $a->tutor_group_id)->firstOrFail();
            $this->assertCount(5, $session->members);
            $this->travelTo(today()->addDays(2)->setTime(17, 0));
            $marks = [];
            foreach ($session->members as $id) {
                $marks[$id] = ['code' => 'O', 'arrival' => '09:00', 'departure' => '16:00', 'otj_confirmed' => 1];
            }$this->act($tutor)->post(route('attendance.save', $session), ['version' => $session->version, 'marks' => $marks, 'delivered_learning' => 'Fictional actual practical learning60'])->assertSessionHasNoErrors();
            $this->assertSame(360, OtjPosition::forApprentice($a)['summary']['recorded_minutes']);
            $this->step('Published timetable occurrence creates one five-person register; actual attendance feeds360 eligible OTJ minutes per attendee', ['attendees' => 5, 'minutes_per_apprentice' => 360]);
            $topic = TrainingTopic::factory()->create(['apprentice_id' => $a->id, 'subject' => 'maths', 'planned_hours' => 2, 'status' => 'Not Started']);
            $qual = Qualification::factory()->create(['apprentice_id' => $a->id, 'subject' => 'maths', 'type' => 'Functional Skills', 'title' => 'Fictional Maths Level2', 'level' => 'Level 2', 'status' => 'Working towards', 'start_date' => '2026-09-01']);
            $this->act($staff['maths'])->post(route('topics.update', $topic), ['version' => $topic->version, 'status' => 'Completed', 'completion_date' => today()->toDateString(), 'feedback' => 'Fictional recorded topic completion'])->assertSessionHasNoErrors();
            $this->assertSame('Working towards', $qual->fresh()->status);
            $minutes = OtjPosition::forApprentice($a)['summary']['recorded_minutes'];
            $this->assertSame(360, $minutes);
            $this->put(route('qualifications.update', [$a, $qual]), ['version' => $qual->version, 'subject' => 'maths', 'type' => 'Functional Skills', 'title' => $qual->title, 'level' => 'Level 2', 'status' => 'Achieved', 'start_date' => '2026-09-01', 'achievement_date' => today()->toDateString(), 'result' => 'Achieved', 'change_reason' => 'Fictional checked result source60'])->assertSessionHasNoErrors();
            $this->assertSame('Achieved', $qual->fresh()->status);
            $this->step('Maths topic completion does not award qualification or add main-aim OTJ; separate manual result remains independent');
            $fields = ['type' => 'light_touch', 'frequency' => 'Every 4 weeks', 'funding' => 'not_funded', 'referral' => today()->toDateString(), 'review' => today()->addDays(28)->toDateString(), 'barriers' => 'PRIVATE_SUPPORT_BARRIER60', 'strengths' => 'Practical engagement', 'view' => 'I would like clear steps.', 'support' => 'PRIVATE_SUPPORT_PLAN60'];
            $this->act($staff['inclusion-lead'])->post(route('support.plan.create', $a), ['concern_ids' => [$cases[$a->id]->id], 'fields' => $fields])->assertSessionHasNoErrors();
            $support = SupportPlan::where('apprentice_id', $a->id)->firstOrFail();
            $this->act($staff['admin-a'])->post(route('support.plan.approve', $support), ['version' => 1, 'confirmed' => 1, 'reason' => 'Fictional checked support60'])->assertSessionHasNoErrors();
            $this->act($staff['inclusion-lead'])->post(route('support.plan.review', $support), ['version' => 2, 'date' => today()->toDateString(), 'baseline' => 'Fictional starting barrier', 'delivered' => 'Fictional agreed support', 'impact' => 'Uses fewer prompts', 'evidence' => 'Fictional learner feedback and observed work', 'notes' => 'PRIVATE_NEXT_ACTION60: Inclusion lead to check progress next week', 'direction' => 'continue', 'next' => today()->addDays(7)->toDateString()])->assertSessionHasNoErrors();
            $this->get(route('support.plan', [$a, $support]))->assertOk()->assertSee('PRIVATE_NEXT_ACTION60');
            $this->act($tutor)->get(route('support.plan', [$a, $support]))->assertForbidden();
            $this->post('/apprentices/'.$a->id.'/reviews', ['fields' => ['reviewDate' => today()->toDateString(), 'trainingFrom' => '2026-09-01', 'summary' => 'Fictional integrated progress discussion60']])->assertSessionHasNoErrors();
            $review = ProgressReviewDraft::where('apprentice_id', $a->id)->latest('id')->firstOrFail();
            $snapshot = $review->source_snapshot;
            $this->assertSame(50, $snapshot['assigned_tasks']);
            $this->assertSame(1, $snapshot['approved_tasks']);
            $this->assertSame(360, $snapshot['otj_position']['recorded_minutes']);
            $this->assertNotEmpty($snapshot['delivered']);
            $this->assertStringNotContainsString('PRIVATE_SUPPORT', json_encode($snapshot));
            $this->assertStringNotContainsString('PRIVATE_NEXT_ACTION60', json_encode($snapshot));
            $this->step('Review source snapshot combines tasks, delivered training, OTJ, maths and qualification sources without private support details');
            $target = SmartAction::factory()->create(['apprentice_id' => $a->id, 'created_by' => $tutor->id, 'owner' => 'Employer / workplace mentor', 'title' => 'Fictional employer target60']);
            $action = EmployerActions::publish($tutor, 1, $a, ['request_token' => (string) Str::uuid(), 'grant_id' => $grants[$a->id]->id, 'kind' => 'target', 'source_id' => $target->id, 'title' => 'Fictional shared workplace action60', 'detail' => 'Explicit shareable summary only60', 'due_date' => null]);
            $this->act($staff['employer-1'])->get(route('employer-actions.show', $action))->assertOk()->assertSee('Explicit shareable summary only60')->assertDontSee('PRIVATE_SUPPORT');
            $this->act($staff['employer-2'])->get(route('employer-actions.show', $action))->assertForbidden();
            $this->step('Named employer receives only the explicit shared action; other employer and private support access remain denied');
            $criteria = [];
            foreach (array_keys(GatewayReadiness::CATEGORIES) as $category) {
                $criteria[] = ['category' => $category, 'kind' => $category === 'tasks' ? 'taskset' : ($category === 'qualifications' ? 'qualification' : 'manual'), 'qualification_id' => $category === 'qualifications' ? $qual->id : null, 'label' => 'Fictional source requirement60 '.$category];
            }$gateway = GatewayReadiness::prepare($tutor, 1, $a, ['cohort' => 'Fictional100 cohort60', 'source_reference' => 'Fictional source applicability only; not real Gateway approval', 'criteria' => $criteria]);
            GatewayReadiness::change($staff['admin-b'], 1, $gateway, ['version' => 1, 'state' => 'Approved', 'note' => 'Fictional independent criteria check60']);
            $g = GatewaySummary::for($learner, $a);
            $this->assertSame(['Met' => 1, 'Missing' => 1, 'Unknown' => 4], $g['counts']);
            $this->assertFalse($g['all_met']);
            $this->act($learner)->get(route('experience.home'))->assertOk()->assertSee('1 of 6 configured checks met')->assertDontSee('PRIVATE_SUPPORT');
            $events = JourneyEvents::for($a);
            $this->assertTrue(collect($events)->contains('type', 'Task approved'));
            $this->assertTrue(collect($events)->contains('type', 'Qualification result'));
            $this->assertStringNotContainsString('PRIVATE_SUPPORT', json_encode($events));
            $this->assertDatabaseCount('signed_review_records', 0);
            $this->step('Gateway correctly remains incomplete despite approved task/qualification; own home/journey reflect learning without private support or invented signed review');
            $this->act($manager)->get(route('centre-oversight.show', [$a, 'training']))->assertOk()->assertDontSee('PRIVATE_SUPPORT');
            $outside = collect($people)->first(fn ($p) => ! in_array($p->tutor_group_id, $scope, true));
            $this->get(route('centre-oversight.show', [$outside, 'training']))->assertForbidden();
            $this->get(route('support.plan', [$a, $support]))->assertForbidden();
            $this->step('Independently granted Centre Manager remit exposes operational projection only for named groups, not private support');
            $this->act($staff['admin-a'])->get(route('dashboard.index'))->assertOk()->assertViewHas('metrics', fn ($m) => $m['apprentices'] === 100 && $m['tasks'] === 5000);
            $this->act($tutor)->get(route('dashboard.index'))->assertOk()->assertViewHas('metrics', fn ($m) => $m['apprentices'] === 20 && $m['tasks'] === 1000);
            $this->step('Admin and Tutor dashboard totals agree with shared100-apprentice,5000-task source and20-person assigned caseload');
            $a->update(['tutor_id' => $staff['tutor-2']->id]);
            $this->act($tutor)->get(route('learning.index', $a))->assertForbidden();
            $this->act($staff['tutor-2'])->get(route('learning.index', $a))->assertOk();
            $this->act($staff['employer-1'])->get(route('employer-actions.show', $action))->assertOk()->assertViewHas('current', true);
            $target->update(['title' => 'Fictional changed source60', 'version' => $target->version + 1]);
            $this->get(route('employer-actions.show', $action))->assertOk()->assertViewHas('current', false);
            $this->post(route('employer-actions.respond', $action), ['version' => $action->version, 'response' => 'Confirmed', 'note' => 'Fictional stale source attempt60', 'confirmed' => 1])->assertSessionHasErrors('actions');
            $this->assertSame('Pending', $action->fresh()->state);
            $this->step('Current-tutor change revokes old training access; changed target source marks employer request stale and refuses response without destroying the shared history');
            $this->act($staff['admin-a'])->post('/staff-support-permissions/'.$staff['inclusion-member']->id, ['version' => 1, 'duty' => 'staff', 'scope' => 'assigned', 'reason' => 'Fictional revocation60'])->assertSessionHasNoErrors();
            $this->act($staff['inclusion-member'])->get(route('support.plan.new', $a))->assertForbidden();
            $this->step('Explicit Inclusion duty revocation removes private support access without deleting the records');
            $this->act($staff['inclusion-lead'])->post(route('support.plan.close', $support), ['version' => $support->fresh()->version, 'outcome' => 'Goals reviewed; normal Tutor support continues. Next check agreed.', 'confirmed' => 1])->assertSessionHasNoErrors();
            $this->assertSame('Closed', $support->fresh()->status);
            $this->assertSame('Monitoring', $cases[$a->id]->fresh()->status);
            $this->assertSame('Approved', $task->fresh()->status);
            $this->step('Support closure retains its history and does not silently close linked concerns or alter learning decisions');
            $disabled = $staff['centre-manager-2'];
            $disabled->forceFill(['active' => false])->save();
            $this->assertFalse($disabled->fresh()->active);
            $this->post('/logout');
            $this->flushSession();
            Auth::forgetGuards();
            $this->post('/login',['email' => $disabled->email, 'password' => $this->password])->assertSessionHasErrors();
            $this->assertGuest();
            $this->step('Inactive account cannot authenticate');
            $this->assertDatabaseCount('users',120);
            $this->assertDatabaseCount('apprentices',100);
            $this->assertDatabaseCount('learning_tasks',5000);
            Mail::assertNothingSent();
            Notification::assertNothingSent();
            Http::assertNothingSent();
            $this->report['elapsed_seconds'] = round(microtime(true) - $started,2);
            $this->saveReport(true);
        } catch (\Throwable $e) {
            $this->report['failure_class'] = get_class($e);
            $this->report['failure_message'] = $e->getMessage();
            $this->saveReport(false);
            throw $e;
        }
    }
}
