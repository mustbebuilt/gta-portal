<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\Employer;
use App\Models\LearningTask;
use App\Models\SmartAction;
use App\Models\StaffDuty;
use App\Models\SupportConcern;
use App\Models\TrainingSession;
use App\Models\TrainingTopic;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\CentreManagerAccess;
use App\Support\EmployerActions;
use App\Support\ProgressDashboard66 as D;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ProgressDashboard66Test extends TestCase
{
    use LazilyRefreshDatabase, \Tests\Support\CentreManagerFixture;

    private function u(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role]);
    }

    private function fixture(string $role = 'admin'): array
    {
        $u = $this->u($role);
        $a = Apprentice::factory()->create(['user_id' => $role === 'apprentice' ? $u->id : null, 'tutor_id' => $role === 'tutor' ? $u->id : null, 'first_name' => 'OWN66']);

        return [$u, $a];
    }

    private function report(User $u, string $role = 'admin', array $q = []): array
    {
        $r = Request::create('/progress-dashboard/demo/'.$role, 'GET', $q);
        $r->setUserResolver(fn () => $u);

        return D::demo($r, $role);
    }

    #[TestWith(['admin'])] #[TestWith(['manager'])] #[TestWith(['tutor'])] #[TestWith(['apprentice'])] #[TestWith(['employer'])] #[TestWith(['mentor'])]
    public function test_demo_each_role_renders_with_warning_and_accessible_tables(string $role): void
    {
        $u = $this->u();
        $this->actingAs($u)->get('/progress-dashboard/demo/'.$role)->assertOk()->assertSee('Demonstration — fictional data')->assertSee('Exact figures')->assertSee('Detailed records')->assertSee('How these figures are calculated')->assertHeader('Cache-Control', 'no-store, private');
    }

    #[TestWith(['admin'])] #[TestWith(['manager'])] #[TestWith(['tutor'])] #[TestWith(['apprentice'])] #[TestWith(['employer'])] #[TestWith(['mentor'])]
    public function test_demo_counts_reconcile_and_csv_json_share_the_same_scope(string $role): void
    {
        $u = $this->u();
        $r = $this->report($u, $role);
        $rows = $r['rows'];
        $sum = array_sum(array_map(fn ($x) => $x['task_status'] === null ? 0 : array_sum($x['task_status']), $rows));
        $this->assertSame($sum, $r['totals']['allocated']);
        $chart = collect($r['charts'])->firstWhere('key', 'task-status');
        $this->assertSame($sum, array_sum(array_column($chart['rows'], 'value')));
        $this->assertSame(array_sum(array_column(array_column($rows, 'attendance'), 'expected')), $r['totals']['attendance_expected']);
        $this->actingAs($u);
        $j = $this->get('/progress-dashboard/demo/'.$role.'/export/json')->assertOk()->json();
        $this->assertCount(count($r['details']), $j['rows']);
        $csv = $this->get('/progress-dashboard/demo/'.$role.'/export/csv')->assertOk()->getContent();
        $this->assertStringContainsString('Demonstration — fictional data', $csv);
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $csvRows = [];
        while (($line = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $csvRows[] = $line;
        }fclose($stream);
        $this->assertCount(count($r['details']) + 2, $csvRows);
        foreach ($r['details'] as $a) {
            $this->assertStringContainsString($a['reference'], $csv);
        }
    }

    public function test_demo_cohort_line_last_point_matches_current_task_counts_and_plan(): void
    {
        $r = $this->report($this->u());
        $c = collect($r['charts'])->firstWhere('key', 'task-history');
        $last = end($c['rows']);
        $this->assertSame($r['totals']['approved'], $last['value']);
        $this->assertSame($r['totals']['expected_tasks'], $last['planned']);
    }

    public function test_demo_filters_and_metric_drilldown_reconcile(): void
    {
        $u = $this->u();
        $r = $this->report($u, 'admin', ['metric' => 'awaiting']);
        $this->assertNotEmpty($r['details']);
        foreach ($r['details'] as $a) {
            $this->assertGreaterThan(0, $a['task_status']['Awaiting marking']);
        }$filtered = $this->report($u, 'admin', ['q' => 'Morgan']);
        $this->assertCount(1, $filtered['rows']);
        $this->assertNull($filtered['rows'][0]['progress']);
        $this->assertNull($filtered['rows'][0]['attendance']['percent']);
        $this->assertNull($filtered['rows'][0]['otj']['recorded_hours']);
    }

    public function test_demo_is_not_a_production_seed_and_never_writes_or_sends(): void
    {
        $u = $this->u();
        $before = [Apprentice::count(), LearningTask::count(), DB::table('portal_operations_events')->count()];
        $this->actingAs($u)->get('/progress-dashboard/demo/admin')->assertOk();
        $this->get('/progress-dashboard/demo/admin/export/json')->assertOk();
        $this->assertSame($before, [Apprentice::count(), LearningTask::count(), DB::table('portal_operations_events')->count()]);
        $this->assertDatabaseCount('apprentices', 0);
    }

    public function test_demo_role_and_identity_cannot_bypass_demo_scope(): void
    {
        $u = $this->u('employer');
        $this->actingAs($u)->get('/progress-dashboard/demo/admin')->assertForbidden();
        $this->get('/progress-dashboard/demo/employer?apprentice=DEMO-02')->assertNotFound();
        $this->get('/progress-dashboard/demo/employer/export/json?apprentice=DEMO-02')->assertNotFound();
    }

    #[TestWith(['admin'])] #[TestWith(['ceo'])] #[TestWith(['tutor'])] #[TestWith(['apprentice'])]
    public function test_live_full_roles_render_without_demo_or_invented_history(string $role): void
    {
        [$u,$a] = $this->fixture($role);
        LearningTask::factory()->create(['apprentice_id' => $a->id]);
        $this->actingAs($u)->get('/progress-dashboard')->assertOk()->assertSee('OWN66')->assertDontSee('Demonstration — fictional data')->assertSee('Historical task-progress curve')->assertSee('Not available');
    }

    #[TestWith(['tutor'])] #[TestWith(['apprentice'])]
    public function test_live_url_and_export_tampering_cannot_access_another_apprentice(string $role): void
    {
        [$u,$a] = $this->fixture($role);
        $other = Apprentice::factory()->create(['first_name' => 'SECRET66']);
        $this->actingAs($u)->get('/progress-dashboard')->assertOk()->assertDontSee('SECRET66');
        foreach (['/progress-dashboard', '/progress-dashboard/export/json', '/progress-dashboard/export/csv'] as $url) {
            $this->get($url.'?apprentice='.$other->id.'&role=admin')->assertNotFound();
        }$this->get('/progress-dashboard/export/json?role=admin')->assertOk()->assertDontSee('SECRET66');
    }

    public function test_unassigned_and_disabled_users_are_denied(): void
    {
        $u = $this->u('manager');
        $this->actingAs($u)->get('/progress-dashboard')->assertForbidden();
        $u->active = false;
        $u->save();
        $this->get('/progress-dashboard/export/json')->assertForbidden();
    }

    public function test_employer_named_grant_is_current_and_does_not_grant_full_progress(): void
    {
        $admin = $this->u();
        $employer = $this->u('employer');
        $e = Employer::factory()->create();
        $a = Apprentice::factory()->create(['employer_id' => $e->id, 'first_name' => 'SHARED66']);
        $secret = Apprentice::factory()->create(['first_name' => 'OTHER-EMPLOYER-SECRET66']);
        LearningTask::factory()->create(['apprentice_id' => $a->id, 'title' => 'PRIVATE-TASK66']);
        $g = EmployerActions::grant($admin, $admin->auth_version, $a, ['user_id' => $employer->id, 'version' => 0, 'identity_reference' => 'Fictional independently checked authority66']);
        $this->actingAs($employer)->get('/progress-dashboard')->assertOk()->assertSee('SHARED66')->assertDontSee('OTHER-EMPLOYER-SECRET66')->assertDontSee('PRIVATE-TASK66');
        $j = $this->get('/progress-dashboard/export/json')->assertOk()->json();
        $this->assertNull($j['rows'][0]['task_status']);
        $this->get('/progress-dashboard?apprentice='.$secret->id)->assertNotFound();
        EmployerActions::revoke($admin, $admin->auth_version, $g, $g->version, 'Fictional authority revoked66');
        $this->get('/progress-dashboard?apprentice='.$a->id)->assertNotFound();
    }

    public function test_mentor_only_sees_assigned_review_projection(): void
    {
        $u = $this->u('mentor');
        $a = Apprentice::factory()->create(['mentor_id' => $u->id, 'first_name' => 'MENTEE66']);
        $other = Apprentice::factory()->create(['first_name' => 'OTHER-MENTEE-SECRET66']);
        SmartAction::factory()->create(['apprentice_id' => $a->id, 'title' => 'PRIVATE-SUPPORT-TARGET66']);
        $this->actingAs($u)->get('/progress-dashboard')->assertOk()->assertSee('MENTEE66')->assertDontSee('OTHER-MENTEE-SECRET66')->assertDontSee('PRIVATE-SUPPORT-TARGET66');
        $this->get('/progress-dashboard/export/json?apprentice='.$other->id)->assertNotFound();
        $a->mentor_id = null;
        $a->save();
        $this->get('/progress-dashboard?apprentice='.$a->id)->assertNotFound();
    }

    public function test_empty_missing_and_invalid_period_states_are_explicit(): void
    {
        $u = $this->u();
        $this->actingAs($u)->get('/progress-dashboard')->assertOk()->assertSee('No matching records');
        $this->get('/progress-dashboard?from=2020-01-01&to=2026-11-17')->assertStatus(422)->assertSee('Report needs attention');
        $this->get('/progress-dashboard?sort=private_salary')->assertRedirect()->assertSessionHasErrors('sort');
    }

    public function test_apprentice_foreign_staff_actions_do_not_leak(): void
    {
        [$u,$a] = $this->fixture('apprentice');
        SmartAction::factory()->create(['apprentice_id' => $a->id, 'owner' => 'Tutor', 'title' => 'PRIVATE-STAFF-ACTION66']);
        $this->actingAs($u)->get('/progress-dashboard/export/json')->assertOk()->assertDontSee('PRIVATE-STAFF-ACTION66');
    }

    public function test_hundred_apprentice_twenty_staff_scope_and_export(): void
    {
        $admin = $this->u();
        $staff = User::factory()->count(20)->create(['role' => 'tutor']);
        for ($i = 0; $i < 100; $i++) {
            $person = Apprentice::factory()->create(['tutor_id' => $staff[$i % 20]->id, 'first_name' => 'Fictional cohort66']);
            for ($j = 0; $j < 5; $j++) {
                LearningTask::factory()->create(['apprentice_id' => $person->id, 'reference' => 'C66-'.$i.'-'.$j, 'status' => $j < 3 ? 'Approved' : ($j === 3 ? 'Awaiting marking' : 'Draft'), 'due_date' => today()->subDay()]);
            }
        }$this->actingAs($admin)->get('/progress-dashboard/export/json')->assertOk()->assertJsonPath('record_count', 100)->assertJsonPath('totals.allocated', 500)->assertJsonPath('totals.approved', 300);
        $this->actingAs($staff[0])->get('/progress-dashboard/export/json')->assertOk()->assertJsonPath('record_count', 5)->assertJsonPath('totals.allocated', 25)->assertJsonPath('totals.approved', 15);
    }

    public function test_export_preview_pages_when_explicitly_requested(): void
    {
        $dir = getenv('GTA_PREVIEW66_DIR');
        if (! $dir) {
            $this->markTestSkipped('Optional fictional preview export.');
        }if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }$u = $this->u();
        foreach (D::ROLES as $role) {
            $html = $this->actingAs($u)->get('/progress-dashboard/demo/'.$role)->assertOk()->getContent();
            file_put_contents($dir.'/'.$role.'.html', $html);
            foreach (['json', 'csv'] as $fmt) {
                file_put_contents($dir.'/'.$role.'.'.$fmt, $this->get('/progress-dashboard/demo/'.$role.'/export/'.$fmt)->assertOk()->getContent());
            }
        }for ($i = 1; $i <= 10; $i++) {
            $id = 'DEMO-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            file_put_contents($dir.'/apprentice-'.$id.'.html', $this->get('/progress-dashboard/demo/apprentice?apprentice='.$id)->assertOk()->getContent());
        }
    }

    public function test_manager_area_scope_and_revocation_apply_to_exports(): void
    {
        $f = $this->f();
        $g = $this->granted($f, ['training']);
        $this->actingAs($f['manager']);
        $j = $this->get('/progress-dashboard/export/json')->assertOk()->assertDontSee('PRIVATE-OUTSIDE49')->assertDontSee('PRIVATE-ANSWER49')->assertDontSee('PRIVATE-SUPPORT49')->json();
        $this->assertSame(1, $j['record_count']);
        $this->assertNull($j['rows'][0]['attendance']['percent']);
        $this->assertSame(1, $j['rows'][0]['tasks']);
        $this->get('/progress-dashboard/export/json?apprentice='.$f['outside']->id)->assertNotFound();
        CentreManagerAccess::revoke($f['admin'], 1, $f['manager'], $g->version, 'Fictional scope revoke66');
        $this->get('/progress-dashboard/export/json')->assertForbidden();
    }

    public function test_task_sources_and_due_dates_do_not_create_false_progress(): void
    {
        [$u,$a] = $this->fixture('apprentice');
        foreach ([['r1', 'Approved', 'knowledge'], ['r2', 'Draft', 'knowledge'], ['r3', 'Approved', 'source-pending']] as [$ref,$state,$kind]) {
            LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => $ref, 'status' => $state, 'due_date' => today()->subDay(), 'template' => ['kind' => $kind]]);
        }$j = $this->actingAs($u)->get('/progress-dashboard/export/json')->assertOk()->json();
        $this->assertSame(3, $j['rows'][0]['tasks']);
        $this->assertSame(1, $j['rows'][0]['approved']);
        $this->assertSame(50, $j['rows'][0]['progress']);
        $this->assertSame(100, $j['rows'][0]['expected_progress']);
        $this->assertSame('Approval below due-task reference', $j['rows'][0]['position']);
        LearningTask::where('reference', 'r2')->update(['due_date' => null]);
        $j = $this->get('/progress-dashboard/export/json')->assertOk()->json();
        $this->assertNull($j['rows'][0]['expected_progress']);
        $this->assertSame('Not available', $j['rows'][0]['position']);
    }

    public function test_incomplete_block_sources_are_not_promoted_to_completed_milestones(): void
    {
        [$u,$a] = $this->fixture('apprentice');
        for ($i = 1; $i <= 5; $i++) {
            LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => 'WRONG66-'.$i, 'status' => 'Approved']);
        }$j = $this->actingAs($u)->get('/progress-dashboard/export/json')->assertOk()->json();
        $this->assertFalse($j['rows'][0]['blocks'][0]['complete']);
        $this->get('/progress-dashboard')->assertOk()->assertDontSee('Block 1 task milestone');
    }

    public function test_attendance_and_otj_use_existing_codes_and_exclude_functional_skills_hours(): void
    {
        [$u,$a] = $this->fixture('apprentice');
        foreach (['O', 'T', 'A', 'H', 'S', 'W', 'C', 'B', 'E', null] as $i => $code) {
            $session = TrainingSession::factory()->create(['members' => [$a->id], 'session_date' => today()->subDays(12 - $i)]);
            if ($code !== null) {
                AttendanceMark::create(['training_session_id' => $session->id, 'apprentice_id' => $a->id, 'code' => $code, 'otj_confirmed' => in_array($code, ['O', 'T'], true), 'otj_minutes' => in_array($code, ['O', 'T'], true) ? 60 : 0, 'recorded_by' => $u->id]);
            }
        }TrainingTopic::factory()->create(['apprentice_id' => $a->id, 'subject' => 'maths', 'status' => 'Completed', 'planned_hours' => 6]);
        TrainingTopic::factory()->create(['apprentice_id' => $a->id, 'subject' => 'maths', 'topic' => 'Zero-hour topic66', 'planned_hours' => 0]);
        $j = $this->actingAs($u)->get('/progress-dashboard/export/json')->assertOk()->json();
        $r = $j['rows'][0];
        $this->assertSame(2, $r['attendance']['attended']);
        $this->assertSame(7, $r['attendance']['expected']);
        $this->assertSame(3, $r['attendance']['excluded']);
        $this->assertSame(1, $r['attendance']['unmarked']);
        $this->assertNull($r['attendance']['percent']);
        $this->assertSame(2, $r['otj']['recorded_hours']);
        $this->assertSame(1, $r['topics']['maths']['assigned']);
        $this->assertSame('Not recorded', $r['topics']['maths']['qualification']);
    }

    public function test_csv_formula_text_is_escaped_and_html_names_are_escaped(): void
    {
        [$u,$a] = $this->fixture();
        $a->first_name = '=FICTIONAL66';
        $a->last_name = '<script>bad66</script>';
        $a->save();
        $csv = $this->actingAs($u)->get('/progress-dashboard/export/csv')->assertOk()->getContent();
        $this->assertStringContainsString("'=FICTIONAL66", $csv);
        $this->get('/progress-dashboard')->assertOk()->assertDontSee('<script>bad66</script>', false)->assertSee('<script>bad66</script>');
    }

    public function test_support_metadata_respects_existing_team_or_raised_by_scope(): void
    {
        [$u,$a] = $this->fixture('tutor');
        $other = $this->u('tutor');
        SupportConcern::factory()->create(['apprentice_id' => $a->id, 'raised_by' => $other->id, 'initial_note' => 'PRIVATE-CONCERN-NOTE66']);
        $mine = SupportConcern::factory()->create(['apprentice_id' => $a->id, 'raised_by' => $u->id, 'initial_note' => 'OWN-SENSITIVE-NOTE66']);
        $this->actingAs($u)->get('/progress-dashboard/export/json')->assertOk()->assertDontSee('PRIVATE-CONCERN-NOTE66')->assertDontSee('OWN-SENSITIVE-NOTE66')->assertSee('Check an authorised support follow-up');
        $mine->raised_by = $other->id;
        $mine->save();
        $this->get('/progress-dashboard/export/json')->assertOk()->assertDontSee('Check an authorised support follow-up');
    }

    public function test_live_report_get_does_not_write_domain_records(): void
    {
        [$u,$a] = $this->fixture();
        $before = [DB::table('portal_operations_events')->count(), DB::table('audit_events')->count(), LearningTask::count()];
        $this->actingAs($u)->get('/progress-dashboard')->assertOk();
        $this->get('/progress-dashboard/export/json')->assertOk();
        $this->assertSame($before, [DB::table('portal_operations_events')->count(), DB::table('audit_events')->count(), LearningTask::count()]);
    }

    public function test_null_filters_reset_safely_and_unknown_metric_is_rejected(): void
    {
        $u = $this->u();
        $this->actingAs($u)->get('/progress-dashboard?from=&to=&q=')->assertOk();
        $this->get('/progress-dashboard/export/json?metric=private_support')->assertRedirect()->assertSessionHasErrors('metric');
    }

    public function test_missing_demo_source_has_an_explicit_unavailable_state(): void
    {
        $u = $this->u();
        $path = storage_path('framework/testing/progress66-empty-resources');
        if (! is_dir($path)) {
            mkdir($path, 0700, true);
        }$this->app->getNamespace();
        $this->app->make('view');
        $this->app->setBasePath($path);
        $this->actingAs($u)->get('/progress-dashboard/demo/admin')->assertStatus(503)->assertSee('Demonstration data is unavailable')->assertDontSee('Approved tasks: 0');
    }

    public function test_published_request_and_response_counts_remain_with_the_named_employer(): void
    {
        $admin = $this->u();
        $employer = $this->u('employer');
        $other = $this->u('employer');
        $e = Employer::factory()->create();
        $a = Apprentice::factory()->create(['employer_id' => $e->id]);
        $g = EmployerActions::grant($admin, $admin->auth_version, $a, ['user_id' => $employer->id, 'version' => 0, 'identity_reference' => 'Fictional independently checked employer66']);
        $target = SmartAction::factory()->create(['apprentice_id' => $a->id, 'owner' => 'Employer / workplace mentor']);
        $x = EmployerActions::publish($admin, $admin->auth_version, $a, ['request_token' => (string) Str::uuid(), 'grant_id' => $g->id, 'kind' => 'target', 'source_id' => $target->id, 'title' => 'SHARED-PUBLISHED-REQUEST66', 'detail' => 'Fictional public summary', 'due_date' => today()->subDay()->toDateString()]);
        $this->actingAs($employer)->get('/progress-dashboard')->assertOk()->assertSee('SHARED-PUBLISHED-REQUEST66');
        $this->actingAs($other)->get('/progress-dashboard/export/json')->assertOk()->assertDontSee('SHARED-PUBLISHED-REQUEST66');
        $this->get('/progress-dashboard/export/csv?apprentice='.$a->id)->assertNotFound();
    }

    public function test_additional_mentor_duty_is_scoped_and_its_removal_denies_exports(): void
    {
        $u = $this->u('staff');
        $d = StaffDuty::create(['user_id' => $u->id, 'mentor' => true, 'history' => []]);
        $a = Apprentice::factory()->create(['mentor_id' => $u->id, 'first_name' => 'DUTY-MENTEE66']);
        Apprentice::factory()->create(['first_name' => 'UNASSIGNED-SECRET66']);
        $this->actingAs($u)->get('/progress-dashboard')->assertOk()->assertSee('DUTY-MENTEE66')->assertDontSee('UNASSIGNED-SECRET66');
        $this->get('/progress-dashboard/export/json')->assertOk()->assertJsonPath('role', 'mentor');
        $d->mentor = false;
        $d->save();
        $this->get('/progress-dashboard/export/json')->assertForbidden();
    }

    public function test_work_with_tutor_is_not_listed_as_an_apprentice_overdue_action(): void
    {
        [$u,$a] = $this->fixture('apprentice');
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id, 'status' => 'Awaiting marking', 'due_date' => today()->subDays(5), 'submitted_at' => now()->subDay()]);
        SmartAction::factory()->create(['apprentice_id' => $a->id, 'learning_task_id' => $task->id, 'owner' => 'Apprentice', 'title' => 'ALREADY-SUBMITTED-TARGET66']);
        $j = $this->actingAs($u)->get('/progress-dashboard/export/json')->assertOk()->json();
        $this->assertSame(1, $j['rows'][0]['task_status']['Awaiting marking']);
        $this->assertSame([], $j['rows'][0]['actions']);
        $this->assertSame(1, collect($j['metrics'])->firstWhere('key', 'awaiting')['value']);
    }

    public function test_personal_summary_links_to_returned_work_before_reporting_detail():void
    {
     [$user,$apprentice]=$this->fixture('apprentice');
     $task=LearningTask::factory()->create([
      'apprentice_id'=>$apprentice->id,
      'title'=>'Update your inspection evidence',
      'status'=>'Referred',
      'due_date'=>today()->addDays(3),
      'template'=>['kind'=>'portfolio'],
     ]);

     $response=$this->actingAs($user)->get('/progress-dashboard')->assertOk()
      ->assertSeeInOrder(['My next step','Update your inspection evidence','Make your task changes','At a glance','Coming up','Progress detail and reports'])
      ->assertSee(route('tasks.show',$task))->assertSee('Target to date unavailable');
     $dom=new \DOMDocument();
     @$dom->loadHTML($response->getContent());
     $xpath=new \DOMXPath($dom);
     $this->assertSame(1,$xpath->query('//details[@id="p66-full-report" and not(@open)]')->length);
     $this->get(route('journey.show',$apprentice))->assertOk();

     $task->forceFill(['status'=>'Awaiting marking','submitted_at'=>now()])->save();
     $this->get('/progress-dashboard')->assertOk()->assertSee('With your tutor for marking')
      ->assertDontSee('Make your task changes')->assertDontSee('Open my next step');
     $filtered=$this->get('/progress-dashboard?metric=awaiting')->assertOk();
     @$dom->loadHTML($filtered->getContent());
     $this->assertSame(1,(new \DOMXPath($dom))->query('//details[@id="p66-full-report" and @open]')->length);
    }

    public function test_tutor_progress_shows_their_marking_link_ahead_of_charts():void
    {
     [$user,$apprentice]=$this->fixture('tutor');
     $task=LearningTask::factory()->create(['apprentice_id'=>$apprentice->id,'status'=>'Awaiting marking','submitted_at'=>now()]);
     $outside=Apprentice::factory()->create();
     $hidden=LearningTask::factory()->create(['apprentice_id'=>$outside->id,'status'=>'Awaiting marking','submitted_at'=>now()]);
     $this->actingAs($user)->get('/progress-dashboard')->assertOk()
      ->assertSeeInOrder(['Next to mark','Open submission','At a glance','What needs attention','Progress and trends'])
      ->assertSee(route('tasks.show',$task))->assertDontSee(route('tasks.show',$hidden));
    }
    public function test_guest_cannot_read_live_or_demo_exports(): void
    {
        foreach (['/progress-dashboard', '/progress-dashboard/export/json', '/progress-dashboard/demo/admin', '/progress-dashboard/demo/admin/export/csv'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_large_scope_has_a_group_recovery_filter_without_silent_truncation(): void
    {
        $u = $this->u();
        $g = TutorGroup::factory()->create(['name' => 'Fictional chosen group66']);
        Apprentice::factory()->create(['tutor_group_id' => $g->id]);
        Apprentice::factory()->count(200)->create();
        $this->actingAs($u)->get('/progress-dashboard/export/json')->assertOk()->assertJsonPath('record_count', 201);
        $this->get('/progress-dashboard/export/json?group='.$g->id)->assertOk()->assertJsonPath('record_count', 1);
        $this->get('/progress-dashboard/export/json?group=999999')->assertNotFound();
    }

    public function test_shared_action_headline_and_drilldown_include_review_references(): void
    {
        $u = $this->u();
        $base = $this->report($u, 'mentor');
        $person = $base['rows'][0];
        $person['actions'] = [['title' => 'Review reference only', 'owner' => 'Mentor', 'due' => '2026-11-24', 'state' => 'Review due reference', 'kind' => 'review']];
        $filters = $base['filters'];
        $filters['metric'] = 'pending';
        $r = D::assemble([$person], $filters, 'mentor', true, ['as_of' => '2026-11-17', 'notice' => 'Fictional regression', 'task_history' => false]);
        $this->assertSame(1, collect($r['metrics'])->firstWhere('key', 'pending')['value']);
        $this->assertSame([$person['id']], array_column($r['details'], 'id'));
    }

    public function test_unavailable_review_dates_do_not_produce_a_zero_overdue_claim(): void
    {
        $base = $this->report($this->u(), 'mentor');
        $person = $base['rows'][0];
        $person['review_due'] = null;
        $r = D::assemble([$person],$base['filters'],'mentor',true,['as_of' => '2026-11-17', 'notice' => 'Fictional regression', 'task_history' => false]);
        $this->assertNull(collect($r['metrics'])->firstWhere('key','reviews')['value']);
        $this->assertStringContainsString('0 of 1',collect($r['metrics'])->firstWhere('key','reviews')['note']);
    }
}
