<?php
namespace Tests\Feature;

use App\Models\{Apprentice,User,LearningTask,TaskEvidence,TrainingSession,AttendanceMark,SupportPlan,SupportConcern};
use App\Support\{TaskCatalogue,PrivateEvidence,SupportPlanReminders,SupportExposure};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Tests\TestCase;

class RoutineWorkflowSimplificationTest extends TestCase
{
    use LazilyRefreshDatabase;
    private function capture($response,string $name):void {
        if ($dir=getenv('GTA_WORKFLOW_CAPTURE')) {file_put_contents($dir.'/'.$name.'.html',$response->getContent());}
    }
    private function actor(string $role='admin'):User {$u=User::factory()->create();$u->role=$role;$u->save();return $u;}
    private function support(array $replace=[]):array {
        $u=$this->actor();$a=Apprentice::factory()->create();$this->actingAs($u);
        $data=['version'=>0,'profile_version'=>$a->version,'request_id'=>(string)Str::uuid(),'intent'=>'activate',
            'need'=>'Fictional help organising deadlines','action'=>'Weekly planning check','owner_id'=>$u->id,'review'=>today()->toDateString()];
        return [$u,$a,array_replace($data,$replace)];
    }
    private function task():array {
        $u=$this->actor('apprentice');$a=Apprentice::factory()->create(['user_id'=>$u->id]);
        $t=LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'b1-t4','template'=>TaskCatalogue::template('b1-t4')]);
        $f=collect($t->template['fields'])->first(fn($f)=>$f['role']==='apprentice'&&in_array($f['key'],['apprentice_name','sign_apprentice_name','apprentice_signoff'],true));
        $this->actingAs($u);Storage::fake(PrivateEvidence::diskName());
        return [$u,$a,$t,[$f['name']=>'Fictional Apprentice']];
    }
    private function upload(array $answers,int $version=1):array {
        return ['version'=>$version,'intent'=>'upload','answers'=>$answers,'kind'=>'supporting-evidence',
            'file'=>UploadedFile::fake()->createWithContent('evidence.pdf',"%PDF-1.4\nFictional evidence")];
    }
    public function test_answers_and_file_save_together_without_final_declaration():void {
        [,, $t,$answers]=$this->task();$this->post('/tasks/'.$t->id,$this->upload($answers))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($answers,$t->fresh()->answers);$this->assertSame(2,$t->fresh()->version);$this->assertSame('Draft',$t->fresh()->status);
        $this->assertDatabaseCount('task_evidence',1);$file=TaskEvidence::first();$this->assertSame(hash('sha256',"%PDF-1.4\nFictional evidence"),$file->sha256);
        $page=$this->get('/tasks/'.$t->id)->assertOk()->assertSee('Save answers and add evidence')->assertSee('Fictional Apprentice')->assertDontSee('Save answers before adding evidence');
        $this->capture($page,'task');
    }
    public function test_combined_upload_retry_does_not_duplicate_evidence_or_version():void {
        [,, $t,$answers]=$this->task();$this->post('/tasks/'.$t->id,$this->upload($answers))->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/tasks/'.$t->id,$this->upload($answers))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('task_evidence',1);$this->assertSame(2,$t->fresh()->version);
    }
    public function test_same_file_with_new_answers_saves_text_without_duplicate_file():void {
        [,, $t,$answers]=$this->task();$this->post('/tasks/'.$t->id,$this->upload($answers))->assertRedirect()->assertSessionHasNoErrors();
        $answers[array_key_first($answers)]='Corrected fictional name';
        $this->post('/tasks/'.$t->id,$this->upload($answers,2))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($answers,$t->fresh()->answers);$this->assertDatabaseCount('task_evidence',1);
    }
    public function test_bad_file_keeps_answers_in_validation_input_and_writes_nothing():void {
        [,, $t,$answers]=$this->task();$d=$this->upload($answers);$d['file']=UploadedFile::fake()->create('bad.exe',1);
        $this->post('/tasks/'.$t->id,$d)->assertSessionHasErrors('file')->assertSessionHasInput('answers',$answers);
        $this->assertDatabaseCount('task_evidence',0);$this->assertSame(1,$t->fresh()->version);
    }
    public function test_workplace_photo_uses_combined_save_and_still_requires_explanation():void {
        [,, $t,$answers]=$this->task();$d=$this->upload($answers);$d['kind']='workplace-photo';
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j3ioAAAAASUVORK5CYII=');
        $d['file']=UploadedFile::fake()->createWithContent('workplace.png',$png);
        $this->post('/tasks/'.$t->id,$d)->assertSessionHasErrors('description');$this->assertDatabaseCount('task_evidence',0);
        $d['description']='Fictional photo of the test setup';
        $this->post('/tasks/'.$t->id,$d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($answers,$t->fresh()->answers);$this->assertSame('workplace-photo',TaskEvidence::first()->kind);
        $this->assertSame($d['description'],TaskEvidence::first()->description);
    }
    public function test_combined_submit_uses_one_version_and_keeps_evidence_snapshot():void {
        [,, $t,$answers]=$this->task();$d=$this->upload($answers);$d['intent']='submit';$d['own_work']=1;
        $this->post('/tasks/'.$t->id,$d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Awaiting marking',$t->fresh()->status);$this->assertSame(2,$t->fresh()->version);
        $this->assertCount(1,$t->fresh()->submissions[0]['evidence']);
    }
    public function test_missing_submission_declaration_rolls_back_file_and_answers():void {
        [,, $t,$answers]=$this->task();$d=$this->upload($answers);$d['intent']='submit';
        $this->post('/tasks/'.$t->id,$d)->assertSessionHasErrors('own_work');
        $this->assertDatabaseCount('task_evidence',0);$this->assertSame(1,$t->fresh()->version);
        $this->assertCount(0,Storage::disk(PrivateEvidence::diskName())->allFiles());
    }
    public function test_stale_changed_answers_are_not_treated_as_a_successful_retry():void {
        [,, $t,$answers]=$this->task();$this->post('/tasks/'.$t->id,$this->upload($answers))->assertRedirect();
        $answers[array_key_first($answers)]='Different stale work';$this->post('/tasks/'.$t->id,$this->upload($answers))->assertSessionHasErrors('version');
        $this->assertDatabaseCount('task_evidence',1);
    }
    public function test_unrelated_apprentice_cannot_use_combined_upload():void {
        [,, $t,$answers]=$this->task();$this->actingAs($this->actor('apprentice'))->post('/tasks/'.$t->id,$this->upload($answers))->assertForbidden();
        $this->assertDatabaseCount('task_evidence',0);
    }
    public function test_task_audit_failure_rolls_back_both_answers_and_private_file():void {
        [,, $t,$answers]=$this->task();DB::statement('DROP TABLE learning_events');
        $this->post('/tasks/'.$t->id,$this->upload($answers))->assertStatus(500);
        $this->assertDatabaseCount('task_evidence',0);$this->assertSame(1,$t->fresh()->version);
        $this->assertCount(0,Storage::disk(PrivateEvidence::diskName())->allFiles());
    }
    public function test_routine_support_creates_active_plan_without_fabricating_a_marker():void {
        [$u,$a,$d]=$this->support();$this->post(route('routine-support.save',$a),$d)->assertRedirect()->assertSessionHasNoErrors();
        $p=SupportPlan::firstOrFail();$this->assertSame('Active',$p->status);$this->assertSame('not_funded',$p->active_plan['fields']['funding']);
        $this->assertSame([],$p->active_plan['codes']);$this->assertNull(SupportConcern::first()->code);$this->assertDatabaseCount('apprentice_markers',0);
        $this->assertSame($u->id,$p->review_owner_id);$this->assertSame(1,$p->review_owner_revision);
        $this->assertSame('due',SupportPlanReminders::position($p)['state']);
        $this->assertSame(1,SupportExposure::summary(collect([$p]))['days']);
        $this->get(route('routine-support.show',[$a,$p]))->assertOk()->assertSee('Save and activate support');
        $this->get(route('support.plan',[$a,$p]))->assertOk()->assertSee('Edit routine support')->assertDontSee('Approve exact saved draft');
        $this->get(route('support.plan.pdf',$p))->assertOk();
    }
    public function test_routine_activation_is_idempotent_and_conflicting_token_rejected():void {
        [,$a,$d]=$this->support();$this->post(route('routine-support.save',$a),$d)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('routine-support.save',$a),$d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('support_plans',1);$this->assertDatabaseCount('support_concerns',1);
        $d['need']='Conflicting retry';$this->post(route('routine-support.save',$a),$d)->assertSessionHasErrors('request_id');
    }
    public function test_scoped_member_can_save_own_routine_support_without_lead_handoff():void {
        [$u,$a,$d]=$this->support();$member=$this->actor('inclusion_member');$d['owner_id']=$member->id;
        $this->actingAs($member)->post(route('routine-support.save',$a),$d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($member->id,SupportPlan::first()->active_plan['approved_by']);
    }
    public function test_routine_support_rejects_funding_and_markers_in_the_quick_route():void {
        [,$a,$d]=$this->support();$d['funding']='als_funded';$d['code']='IM1';
        $this->post(route('routine-support.save',$a),$d)->assertSessionHasErrors(['funding','code']);$this->assertDatabaseCount('support_plans',0);
    }
    public function test_draft_does_not_activate_or_start_plan_reminders():void {
        [,$a,$d]=$this->support(['intent'=>'draft']);$this->post(route('routine-support.save',$a),$d)->assertRedirect()->assertSessionHasNoErrors();
        $p=SupportPlan::firstOrFail();$this->assertSame('Draft',$p->status);$this->assertNull($p->active_plan);
        $this->assertSame('inactive',SupportPlanReminders::position($p)['state']);
    }
    public function test_draft_amendment_keeps_active_copy_until_explicit_activation():void {
        [,$a,$d]=$this->support();$this->post(route('routine-support.save',$a),$d)->assertRedirect();$p=SupportPlan::firstOrFail();$active=$p->active_plan;
        $d['version']=$p->version;$d['request_id']=(string)Str::uuid();$d['intent']='draft';$d['action']='Changed draft support';
        $this->post(route('routine-support.save',[$a,$p]),$d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($active,$p->fresh()->active_plan);$this->assertSame('Changed draft support',$p->fresh()->draft['fields']['support']);
    }
    public function test_active_routine_change_requires_reason_and_preserves_history():void {
        [,$a,$d]=$this->support();$this->post(route('routine-support.save',$a),$d);$p=SupportPlan::first();$old=$p->active_plan;
        $d['version']=$p->version;$d['request_id']=(string)Str::uuid();$d['action']='New agreed support';
        $this->post(route('routine-support.save',[$a,$p]),$d)->assertSessionHasErrors('change_reason');
        $d['change_reason']='Agreed at check-in';$this->post(route('routine-support.save',[$a,$p]),$d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($old,$p->fresh()->history[1]['before_active']);
    }
    public function test_no_second_current_plan_and_no_legacy_overwrite_of_routine_basis():void {
        [,$a,$d]=$this->support();$this->post(route('routine-support.save',$a),$d);$p=SupportPlan::first();
        $d['request_id']=(string)Str::uuid();$this->post(route('routine-support.save',$a),$d)->assertSessionHasErrors('need');
        $this->post(route('support.plan.save',[$a,$p]),[])->assertSessionHasErrors('fields');$this->assertDatabaseCount('support_plans',1);
    }
    public function test_employer_apprentice_and_ordinary_tutor_cannot_open_private_quick_plan():void {
        [,$a,$d]=$this->support();foreach(['employer','apprentice','tutor']as$role){$this->actingAs($this->actor($role));
            $this->get(route('routine-support.show',$a))->assertForbidden();$this->post(route('routine-support.save',$a),$d)->assertForbidden();}
        $this->assertDatabaseCount('support_plans',0);
    }
    public function test_specialist_plan_cannot_be_converted_by_posting_to_quick_route():void {
        [,$a,$d]=$this->support();$p=SupportPlan::create(['apprentice_id'=>$a->id,'concern_ids'=>[],'draft'=>['fields'=>['funding'=>'als_funded'],'codes'=>[]]]);
        $d['version']=$p->version;$this->post(route('routine-support.save',[$a,$p]),$d)->assertNotFound();
        $this->assertSame('als_funded',$p->fresh()->draft['fields']['funding']);
    }
    public function test_support_audit_failure_leaves_no_plan_or_case():void {
        [,$a,$d]=$this->support();DB::statement('DROP TABLE learning_events');
        $this->post(route('routine-support.save',$a),$d)->assertStatus(500);
        $this->assertDatabaseCount('support_plans',0);$this->assertDatabaseCount('support_concerns',0);
    }
    public function test_new_routine_form_opens_without_any_classified_concern():void {
        [,$a]=$this->support();$page=$this->get(route('routine-support.show',$a))->assertOk()->assertSee('What help is needed?')->assertSee('Save and activate support');
        $this->capture($page,'routine-support');
        $this->assertDatabaseCount('support_plans',0);$this->assertDatabaseCount('support_concerns',0);
    }
    public function test_scoped_member_is_denied_an_unassigned_private_plan():void {
        [$admin,$a,$d]=$this->support();$member=$this->actor('tutor');
        $this->post('/staff-support-permissions/'.$member->id,['version'=>0,'duty'=>'member','scope'=>'assigned','reason'=>'Fictional limited support scope'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($member);$this->get(route('routine-support.show',$a))->assertForbidden();
        $this->post(route('routine-support.save',$a),$d)->assertForbidden();$this->assertDatabaseCount('support_plans',0);
    }
    public function test_profile_or_plan_changes_block_stale_routine_saves():void {
        [,$a,$d]=$this->support();$a->version++;$a->save();
        $this->post(route('routine-support.save',$a),$d)->assertSessionHasErrors('version');$this->assertDatabaseCount('support_plans',0);
        $d['profile_version']=$a->version;$this->post(route('routine-support.save',$a),$d)->assertRedirect()->assertSessionHasNoErrors();$p=SupportPlan::first();
        $d['request_id']=(string)Str::uuid();$d['version']=0;
        $this->post(route('routine-support.save',[$a,$p]),$d)->assertSessionHasErrors('version');
    }
    public function test_owner_change_is_versioned_and_due_reminder_uses_current_owner():void {
        [,$a,$d]=$this->support();$this->post(route('routine-support.save',$a),$d);$p=SupportPlan::first();$old=$p->active_plan;
        $new=$this->actor('inclusion_member');$d['version']=$p->version;$d['request_id']=(string)Str::uuid();$d['owner_id']=$new->id;$d['change_reason']='Agreed cover';
        $this->post(route('routine-support.save',[$a,$p]),$d)->assertRedirect()->assertSessionHasNoErrors();$p=$p->fresh();
        $this->assertSame(2,$p->review_owner_revision);$this->assertSame($new->id,SupportPlanReminders::position($p)['source']['recipient_id']);
        $this->assertNotSame($old['approved_at'],$p->active_plan['approved_at']);
        $this->assertSame($old,$p->history[1]['before_active']);
    }
    public function test_closed_routine_history_cannot_be_rewritten():void {
        [,$a,$d]=$this->support();$this->post(route('routine-support.save',$a),$d);$p=SupportPlan::first();
        $this->post(route('support.plan.close',$p),['version'=>$p->version,'outcome'=>'Fictional routine need resolved','confirmed'=>1])->assertRedirect()->assertSessionHasNoErrors();
        $p=$p->fresh();$before=$p->getAttributes();$d['version']=$p->version;$d['request_id']=(string)Str::uuid();
        $this->post(route('routine-support.save',[$a,$p]),$d)->assertSessionHasErrors('need');$this->assertSame($before,$p->fresh()->getAttributes());
    }
    private function register():array {
        $u=$this->actor();$a=Apprentice::factory()->create();$s=TrainingSession::factory()->create(['session_date'=>today()->subDay(),
            'start_time'=>'09:00','end_time'=>'16:00','members'=>[$a->id],'breaks'=>[['start'=>'12:00','end'=>'12:30']]]);
        $this->actingAs($u);return [$a,$s,['version'=>$s->version,'delivered_learning'=>'Fictional new learning',
            'marks'=>[$a->id=>['code'=>'O','arrival'=>'09:00','departure'=>'16:00','otj_confirmed'=>true]]]];
    }
    public function test_unchanged_register_save_needs_no_reason_and_creates_no_history_or_version():void {
        [$a,$s,$d]=$this->register();$this->post(route('attendance.save',$s),$d)->assertRedirect()->assertSessionHasNoErrors();
        $s=$s->fresh();$history=$s->history;$version=$s->version;$d['version']=$version;
        $this->post(route('attendance.save',$s),$d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($version,$s->fresh()->version);$this->assertSame($history,$s->fresh()->history);
        $this->assertSame(390,AttendanceMark::first()->otj_minutes);
    }
    public function test_actual_register_change_still_requires_reason_and_rolls_back_all_marks():void {
        [$a,$s,$d]=$this->register();$this->post(route('attendance.save',$s),$d);$s=$s->fresh();$d['version']=$s->version;
        $d['marks'][$a->id]['departure']='15:00';$this->post(route('attendance.save',$s),$d)->assertSessionHasErrors('correction_reason');
        $this->assertSame(390,AttendanceMark::first()->otj_minutes);
        $d['correction_reason']='Corrected actual departure';$this->post(route('attendance.save',$s),$d)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(330,AttendanceMark::first()->otj_minutes);
    }
    public function test_register_view_has_compact_details_and_explicit_full_session_control():void {
        [,$s]=$this->register();$page=$this->get(route('attendance.show',$s))->assertOk()->assertSee('Actual times, OTJ and other details')
            ->assertSee('Attended for the full session')->assertSee('routine-workflows.js');
        $this->capture($page,'register');
    }
    public function test_today_registers_are_prominent_without_hiding_past_missing_counts():void {
        $this->travelTo(now()->setTime(10,0));
        [$a,$old]=$this->register();$today=TrainingSession::factory()->create(['tutor_group_id'=>$old->tutor_group_id,'session_date'=>today(),'members'=>[$a->id],'name'=>'Fictional today session']);
        $r=$this->get(route('attendance.index'))->assertOk()->assertSee('Your next register')->assertSee($old->name)->assertSee('Other sessions, filters and follow-up');
        $this->assertCount(1,$r->viewData('todayRegisterRows'));$this->assertSame($today->id,$r->viewData('todayRegisterRows')->first()['session']->id);
        $this->assertSame(1,$r->viewData('registerCounts')['needs-register']);
    }
}
