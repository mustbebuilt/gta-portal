<?php

namespace Tests\Feature;

use App\Models\{Apprentice, AttendanceMark, LearningTask, ProgressReviewDraft, SmartAction, SupportCapability, SupportConcern, TrainingSession, TutorGroup, User};
use App\Support\{ReviewFacts83, SupportRegister71, Workday83};
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WorkflowSimplification83Test extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();$this->travelTo(Carbon::parse('2026-11-24 17:00'));
    }

    private function fixture(): array
    {
        $tutor=User::factory()->create(['role'=>'tutor','active'=>true]);
        $group=TutorGroup::factory()->create(['tutor_id'=>$tutor->id]);
        $a=Apprentice::factory()->create(['tutor_id'=>$tutor->id,'tutor_group_id'=>$group->id]);
        return [$tutor,$group,$a];
    }

    private function trainingSession(TutorGroup $group, Apprentice $a, string $date='2026-11-23'): TrainingSession
    {
        return TrainingSession::factory()->create(['tutor_group_id'=>$group->id,'members'=>[$a->id],'session_date'=>$date]);
    }

    public function test_home_combines_scoped_source_work_and_does_not_duplicate_linked_tasks_or_write_records(): void
    {
        [$u,$g,$a]=$this->fixture();$s=$this->trainingSession($g,$a);
        $task=LearningTask::factory()->create(['apprentice_id'=>$a->id,'due_date'=>today()->subDay()]);
        SmartAction::factory()->create(['apprentice_id'=>$a->id,'learning_task_id'=>$task->id]);
        [$other,$otherGroup,$otherA]=$this->fixture();$hidden=$this->trainingSession($otherGroup,$otherA);$hidden->name='Hidden register';$hidden->save();
        $before=$s->fresh()->getAttributes();
        $page=$this->actingAs($u)->get(route('dashboard.index'))->assertOk()->assertSee('Work today')->assertSee('Start next item')->assertDontSee('Hidden register');
        $rows=$page->viewData('priorityList')->getCollection();
        $this->assertSame(1,$rows->where('key','task-'.$task->id)->count());
        $this->assertSame(1,$rows->where('kind','review')->count());
        $this->assertSame(1,$rows->where('kind','register')->count());
        foreach($rows as $row)$this->get($row['url'])->assertOk();
        $this->assertSame($before,$s->fresh()->getAttributes());$this->assertDatabaseCount('attendance_marks',0);$this->assertDatabaseCount('progress_review_drafts',0);
        $this->get(route('attendance.show',$hidden))->assertForbidden();
    }

    public function test_profile_uses_recorded_percentages_and_missing_data_is_not_zero_achievement(): void
    {
        [$u,$g,$a]=$this->fixture();LearningTask::factory()->create(['apprentice_id'=>$a->id,'status'=>'Approved']);LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'b1-t4']);
        $page=$this->actingAs($u)->get(route('apprentices.show',$a))->assertOk()->assertSee('At a glance')->assertSee('50%')->assertSee('Not available')->assertSee('Apprentice details and staff contacts');
        $this->assertSame(2,$page->viewData('overview83')['task_total']);
        $this->assertNull($page->viewData('overview83')['attendance']['percent']);
        $this->assertSame(1,substr_count($page->getContent(),'href="'.route('reviews.create',$a).'"'));
    }

    public function test_register_navigation_is_read_only_scoped_and_save_next_keeps_the_group_filter(): void
    {
        [$u,$g,$a]=$this->fixture();$first=$this->trainingSession($g,$a,'2026-11-20');$second=$this->trainingSession($g,$a,'2026-11-23');
        $otherGroup=TutorGroup::factory()->create(['tutor_id'=>$u->id]);$otherA=Apprentice::factory()->create(['tutor_id'=>$u->id,'tutor_group_id'=>$otherGroup->id]);$outside=$this->trainingSession($otherGroup,$otherA,'2026-11-19');
        $this->actingAs($u)->get(route('attendance.index',['group'=>$g->id]))->assertOk()->assertSee('Open next register');
        $this->assertDatabaseCount('attendance_marks',0);
        $this->get(route('attendance.show',['session'=>$first,'group'=>$g->id]))->assertOk()->assertSee('Save and open next register');
        $scope=['group'=>$g->id];$this->assertSame($second->id,Workday83::nextRegister($u,$first->id,$scope)['session']->id);
        $this->post(route('attendance.save',$first),['version'=>$first->version,'marks'=>[$a->id=>['code'=>'A']],'after_save'=>'next','next_scope'=>$scope])->assertSessionHasNoErrors()->assertRedirect(route('attendance.show',['session'=>$second,...$scope]));
        $this->assertDatabaseCount('attendance_marks',1);$this->assertSame(0,$outside->marks()->count());
        $this->assertFalse($first->marks()->sole()->otj_confirmed);
    }

    public function test_bad_navigation_input_and_failed_register_validation_do_not_save_or_advance(): void
    {
        [$u,$g,$a]=$this->fixture();$s=$this->trainingSession($g,$a);$this->actingAs($u);
        $this->post(route('attendance.save',$s),['version'=>1,'marks'=>[$a->id=>['code'=>'bad']],'after_save'=>'next'])->assertSessionHasErrors();
        $this->assertDatabaseCount('attendance_marks',0);
        $this->post(route('attendance.save',$s),['version'=>1,'marks'=>[$a->id=>['code'=>'A']],'after_save'=>'next','next_scope'=>['group'=>['bad']]])->assertSessionHasErrors();
        $this->assertDatabaseCount('attendance_marks',0);
    }

    public function test_meeting_uses_original_marker_forms_and_navigation_never_records_a_review(): void
    {
        $admin=User::factory()->create(['role'=>'admin']);$people=collect();$schedules=[];
        foreach(['A','B','C'] as $name){$a=Apprentice::factory()->create(['first_name'=>$name,'last_name'=>'Same']);$people->push($a);$schedules[]=SupportRegister71::addMarker($admin,$a,['code'=>'IM34','interval_key'=>'monthly','owner_id'=>$admin->id]);}
        $this->actingAs($admin);
        foreach($people as $index=>$a){$page=$this->get(route('support.worklist',['view'=>'needs-review','meeting'=>$a->id]))->assertOk()->assertSee('Support and Retention meeting')->assertSee(route('support-register71.review',$schedules[$index]),false);$this->assertSame($people->get($index+1)?->id,$page->viewData('nextMeeting')['person']->id??null);}
        foreach($schedules as $schedule)$this->assertFalse($schedule->fresh()->first_review_completed);
        $url=route('support.worklist',['view'=>'needs-review','meeting'=>$people[0]->id]);
        $this->from($url)->post(route('support-register71.review',$schedules[0]),['version'=>$schedules[0]->version,'kind'=>'formal_review','review_date'=>today()->toDateString(),'interval_key'=>'monthly','note'=>'Actual private meeting outcome'])->assertSessionHasNoErrors()->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('no longer in the selected list')->assertDontSee('Actual private meeting outcome');
        $this->assertTrue($schedules[0]->fresh()->first_review_completed);$this->assertFalse($schedules[1]->fresh()->first_review_completed);
        $this->assertDatabaseCount('support_concerns',3);
    }

    public function test_meeting_does_not_broaden_inclusion_scope_or_leak_another_person(): void
    {
        $admin=User::factory()->create(['role'=>'admin']);$member=User::factory()->create(['role'=>'inclusion_member']);$own=Apprentice::factory()->create();$other=Apprentice::factory()->create(['first_name'=>'Hidden private person']);
        SupportCapability::create(['user_id'=>$member->id,'duty'=>'member','scope'=>'assigned','apprentice_ids'=>[$own->id],'group_ids'=>[]]);
        foreach([$own,$other] as $a)SupportRegister71::addMarker($admin,$a,['code'=>'IM34','interval_key'=>'monthly','owner_id'=>$admin->id]);
        $this->actingAs($member)->get(route('support.worklist',['meeting'=>$other->id]))->assertOk()->assertDontSee('Hidden private person')->assertSee('outside your permitted scope');
        $this->actingAs(User::factory()->create(['role'=>'apprentice']))->get(route('support.worklist',['meeting'=>$own->id]))->assertForbidden();
    }

    public function test_factual_review_fill_is_explicit_preserves_typed_text_and_does_not_agree_targets_or_reset_cycle(): void
    {
        [$u,$g,$a]=$this->fixture();LearningTask::factory()->create(['apprentice_id'=>$a->id,'status'=>'Approved']);
        $this->actingAs($u)->post(route('reviews.store',$a),['fields'=>['summary'=>'Actual discussion']])->assertSessionHasNoErrors();
        $review=ProgressReviewDraft::sole();$this->assertArrayNotHasKey('progressDiscussion',$review->fields);
        $this->post(route('reviews.update',[$a,$review]),['version'=>$review->version,'use_recorded_facts'=>1,'fields'=>['summary'=>'Actual discussion','learning'=>'My checked learning notes']])->assertSessionHasNoErrors();
        $fields=$review->fresh()->fields;$this->assertSame('My checked learning notes',$fields['learning']);$this->assertStringContainsString('1 of 1 loaded tasks approved',$fields['progressDiscussion']);$this->assertSame('Actual discussion',$fields['summary']);
        $this->assertArrayNotHasKey('judgement',$fields);$this->assertArrayNotHasKey('targetsAgreed',$fields);$this->assertSame('Draft',$review->fresh()->state);$this->assertDatabaseCount('smart_actions',0);
        $this->post(route('reviews.ready',[$a,$review]),['version'=>$review->fresh()->version,'confirmed'=>1])->assertSessionHasErrors();
    }

    public function test_recorded_facts_are_bounded_and_never_pull_sensitive_notes_or_invent_empty_learning(): void
    {
        $fields=ReviewFacts83::fill([],['assigned_tasks'=>0,'approved_tasks'=>0,'private_support'=>'SECRET']);$this->assertSame([],$fields);
        $fields=ReviewFacts83::fill(['learning'=>'Keep exactly'],['delivered'=>[['date'=>'2026-11-23','name'=>'Source session','subjects'=>'Recorded subject']]]);$this->assertSame('Keep exactly',$fields['learning']);
        $fields=ReviewFacts83::fill([],['delivered'=>array_fill(0,20,['date'=>'2026-11-23','name'=>'Source','subjects'=>str_repeat('a',10000)])]);$this->assertLessThanOrEqual(20000,mb_strlen($fields['learning']));
    }

    public function test_marking_starts_the_oldest_eligible_submission_in_the_current_filter_without_changing_it(): void
    {
        [$u,$g,$a]=$this->fixture();$t=LearningTask::factory()->create(['apprentice_id'=>$a->id,'status'=>'Awaiting marking','submitted_at'=>now()->subDays(8)]);
        $t->submissions=[['version'=>1,'submitted_at'=>$t->submitted_at->toIso8601String(),'decision'=>null,'template_hash'=>$t->template['sha256'],'answers'=>[]]];$t->save();
        $before=$t->fresh()->getAttributes();
        $page=$this->actingAs($u)->get(route('marking.index',['search'=>$a->reference,'view'=>'overdue']))->assertOk()->assertSee('Start next submission');
        $this->assertSame($t->id,$page->viewData('nextTask83'));$this->assertSame($before,$t->fresh()->getAttributes());
        $this->get(route('tasks.show',['task'=>$t,'from_marking'=>1,'search'=>$a->reference,'view'=>'overdue']))->assertOk()->assertSee('Save and open next');
    }

    public function test_meeting_saves_one_shared_target_and_advances_without_reopening_the_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $first = Apprentice::factory()->create(['first_name' => 'Alex', 'last_name' => 'A']);
        $next = Apprentice::factory()->create(['first_name' => 'Jordan', 'last_name' => 'Z']);
        $schedule = SupportRegister71::addMarker($admin, $first, ['code' => 'IM34', 'interval_key' => 'monthly', 'owner_id' => $admin->id]);
        SupportRegister71::addMarker($admin, $next, ['code' => 'IM34', 'interval_key' => 'monthly', 'owner_id' => $admin->id]);
        $url = route('support.worklist', ['view' => 'needs-review', 'meeting' => $first->id]);
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Save review and open next apprentice');
        $data = ['version' => $schedule->version, 'kind' => 'formal_review', 'review_date' => today()->toDateString(),
            'interval_key' => 'monthly', 'note' => 'Fictional meeting outcome', 'summary' => 'Bring inspection checklist',
            'owner_id' => $admin->id, 'due_date' => today()->addDays(7)->toDateString(), 'request_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'after_review' => 'next', 'next_meeting' => $next->id, 'meeting_filters' => ['view' => 'needs-review']];
        $this->from($url)->post(route('support-register71.review', $schedule), $data)->assertSessionHasErrors('measure');
        $this->assertFalse($schedule->fresh()->first_review_completed);
        $this->assertDatabaseCount('support_shared_actions71', 0);
        $this->post(route('support-register71.review', $schedule), $data + ['measure' => 'Tutor checks one completed checklist'])
            ->assertRedirect(route('support.worklist', ['meeting' => $next->id, 'view' => 'needs-review']))->assertSessionHasNoErrors();
        $this->assertTrue($schedule->fresh()->first_review_completed);
        $target = \App\Models\SupportSharedAction71::sole();
        $this->assertSame($schedule->support_concern_id, $target->support_concern_id);
        $this->get(route('support.index', $first))->assertOk()->assertSee('Bring inspection checklist');
        $this->get(route('support.worklist', ['view' => 'all', 'meeting' => $first->id]))->assertOk()->assertSee('Bring inspection checklist');
    }

    public function test_new_review_shows_recorded_progress_and_existing_targets_without_writing(): void
    {
        [$tutor, $group, $apprentice] = $this->fixture();
        LearningTask::factory()->create(['apprentice_id' => $apprentice->id, 'status' => 'Approved']);
        SmartAction::factory()->create(['apprentice_id' => $apprentice->id, 'title' => 'Fictional open workplace target']);
        $this->actingAs($tutor)->get(route('reviews.create', $apprentice))->assertOk()
            ->assertSee('Recorded facts for the draft')->assertSee('1 of 1 loaded tasks approved')
            ->assertSee('Fictional open workplace target')->assertSee('Actual meeting date');
        $this->assertDatabaseCount('progress_review_drafts', 0);
        $this->assertDatabaseCount('smart_actions', 1);
    }

    public function test_operational_home_uses_attention_and_reports_link_back(): void
    {
        foreach (['admin', 'ceo', 'tutor'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/')->assertRedirect(route('attention.index'));
        }
        $this->get(route('attention.index'))->assertOk()->assertSee('Who needs attention today?');
        $this->get(route('dashboard.report'))->assertOk()->assertSee(route('attention.index'));
    }
}
