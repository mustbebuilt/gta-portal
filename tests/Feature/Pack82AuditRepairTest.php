<?php

namespace Tests\Feature;

use App\Models\{Apprentice, ApprenticeSetupDraft68 as Draft, Employer, IndividualDeliveryPlan, Programme, ProgrammeOutcome, ProgrammeSetupTemplate68 as Template, Qualification, TrainingTopic, User};
use App\Support\{FunctionalSkillsSetup82 as FS, GuidedSetup68 as Setup, Rpl68, RplPlanDates69 as Dates};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Pack82AuditRepairTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function cases(): array
    {
        $cases = [];
        foreach (GuidedSetup68Test::dateProgrammes() as $name => $programme) {
            foreach ([false,true] as $rpl) foreach (['pending','recorded'] as $state) $cases["$name/$rpl/$state"] = [...$programme,$rpl,$state];
        }
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cases')]
    public function test_four_programmes_save_assessments_and_confirm_generated_dates_without_timetable(string $code, string $version, int $hours, int $count, bool $rpl, string $state): void
    {
        $admin = User::factory()->create(['role'=>'admin','active'=>true]);
        $this->actingAs($admin);
        $source = GuidedSetup68Test::source();
        $source['standard_code']=$code; $source['standard_version']=$version; $source['full_hours']=$hours;
        $source['date_basis']='agreed_timetable'; $source['source_approval_ref']='FICTIONAL-TEST';
        foreach (range(0,9) as $i) $source['blocks'][$i]['hours']=$hours/10;
        $source['ksbs']=array_slice($source['ksbs'],0,$count);
        $programme = Programme::create(['standard_code'=>$code,'version'=>$version,'name'=>'Fictional programme','level'=>$code==='ST0499'?2:3,'active'=>true]);
        $template = Template::create(['programme_id'=>$programme->id,'template'=>$source,'source_hash'=>Rpl68::hash($source),'active'=>true]);
        config(['gta_rpl_dates'=>["$code:$version"=>['hours_per_training_week'=>6,'training_weeks_per_year'=>46.4,'epa_months'=>1]]]);
        $draft = Setup::start($admin,$admin->auth_version);
        $save = function ($step,$data) use ($admin,&$draft) { $draft=Setup::save($admin,$admin->auth_version,$draft,$draft->version,$step,$data); };
        $save(1,['reference'=>'QA82','first_name'=>'Fictional','last_name'=>'Setup']);
        $save(2,['state'=>'none']);
        $save(3,['template_id'=>$template->id,'start_date'=>'2027-01-11']);
        $save(4,$rpl?['mode'=>'applies','assessment_ref'=>'APPROVED-TEST','adjustments'=>['K1'=>10],'remaining_confirmed'=>true]:['mode'=>'none']);
        $employer=Employer::factory()->create(['active'=>true]); $save(5,['id'=>$employer->id]);
        if($rpl) $save(5,[...$draft->payload['employer'],'agreement_ref'=>'AGREEMENT-TEST','agreement_verified'=>true]);
        $fs=FS::defaults($admin);
        foreach($fs as &$entry){$entry['state']=$state;if($state==='recorded'){$entry['initial_assessment']='Actual test result ref';$entry['diagnostic']='Actual diagnostic ref';}}unset($entry);
        $fs['maths']['topics']=[0=>2.5]; $fs['english']['topics']=[7=>0];
        $this->get(route('setup68.show',[$draft,6]))->assertOk()->assertSee('Functional Skills')->assertSee('Pending');
        $this->post(route('setup68.save',[$draft,6]),['version'=>$draft->version,'data'=>$fs,'next'=>7])->assertRedirect(route('setup68.show',[$draft,7]))->assertSessionHasNoErrors();
        $draft->refresh();
        $this->get(route('setup68.show',[$draft,7]))->assertOk()->assertSee('Planned practical end date')->assertDontSee('12-line');
        $dates=Setup::review($draft)['dates69']['selected'];
        // Legacy date-save endpoint must also generate the block schedule without manual CSV.
        $draft=Setup::savePlanDates($admin,$admin->auth_version,$draft,$draft->version,[...$dates,'source'=>'Approved test delivery']);
        $this->post(route('setup68.confirm',$draft),['version'=>$draft->version,'confirmed'=>1,...$dates])->assertRedirect()->assertSessionHasNoErrors();
        $draft->refresh(); $this->assertSame('Complete',$draft->state);
        $this->get(route('setup68.index'))->assertOk()->assertSee(route('setup68.show',[$draft,7]),false);
        $a=Apprentice::findOrFail($draft->apprentice_id); $plan=IndividualDeliveryPlan::where('apprentice_id',$a->id)->firstOrFail();
        $this->assertSame($dates['practical_end'],$plan->approved['details']['practical_end_date']);
        $this->assertSame($dates['programme_end'],$a->planned_end_date->toDateString());
        $this->assertSame($dates['practical_end'],$plan->approved['blocks'][9]['end']);
        $this->assertCount(12,$plan->approved['blocks']);
        $this->assertSame($state,FS::current($a->id)['maths']['state']);
        $this->assertDatabaseHas('training_topics',['apprentice_id'=>$a->id,'subject'=>'maths','planned_hours'=>2.5,'status'=>'Not Started']);
        $this->assertDatabaseHas('training_topics',['apprentice_id'=>$a->id,'subject'=>'english','planned_hours'=>0,'status'=>'Secure']);
        $this->assertDatabaseCount('qualifications',0);
        $before=$plan->approved;
        $this->post(route('setup68.confirm',$draft),['version'=>1,'confirmed'=>1])->assertRedirect();
        $this->assertSame($before,$plan->fresh()->approved); $this->assertDatabaseCount('training_topics',2);
        $this->get(route('em.index',$a))->assertOk()->assertSee('Assessment information and follow-up');
        // Complete pending information directly in English/maths, without reopening setup.
        $entry=[...$fs['maths'],'state'=>'recorded','initial_assessment'=>'IA later','diagnostic'=>'Diagnostic later','topics'=>[]];
        $outcome=ProgrammeOutcome::where('apprentice_id',$a->id)->firstOrFail();
        $this->post(route('em.setup-assessments.save',$a),['subject'=>'maths','version'=>$outcome->version,'data'=>['maths'=>$entry]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('recorded',FS::current($a->id)['maths']['state']);
        $this->assertSame($state,FS::current($a->id)['english']['state']);
        $this->assertSame($before,$plan->fresh()->approved);
        $this->post(route('em.setup-assessments.save',$a),['subject'=>'maths','version'=>$outcome->version,'data'=>['maths'=>$entry]])->assertSessionHasErrors();
    }

    public function test_assessment_validation_does_not_invent_results_or_accept_wrong_subject_topics(): void
    {
        $u=User::factory()->create(['role'=>'admin','active'=>true]); $values=FS::defaults($u);
        $values['maths']['state']='recorded';
        try{FS::validate($values);$this->fail('Missing assessments accepted');}catch(\Illuminate\Validation\ValidationException $e){$this->assertArrayHasKey('maths.initial_assessment',$e->errors());}
        $values=FS::defaults($u);$values['maths']['topics']=[7=>3];
        $this->expectException(\Illuminate\Validation\ValidationException::class);FS::validate($values);
    }

    public function test_unrelated_user_cannot_read_or_change_assessments(): void
    {
        $a=Apprentice::factory()->create();$u=User::factory()->create(['role'=>'employer','active'=>true]);
        $this->actingAs($u)->post(route('em.setup-assessments.save',$a),['subject'=>'maths','version'=>0,'data'=>[]])->assertForbidden();
        $this->get(route('em.index',$a))->assertForbidden();
    }

    public function test_capacity_and_unresolved_allocations_are_rejected(): void
    {
        $source=GuidedSetup68Test::source();$source['date_basis']='agreed_timetable';
        config(['gta_rpl_dates'=>['ST0499:1.3'=>['hours_per_training_week'=>6,'training_weeks_per_year'=>46.4,'epa_months'=>1]]]);
        $programme=['template_id'=>1,'template_hash'=>Rpl68::hash($source),'start_date'=>'2027-01-11'];$rpl=['mode'=>'none'];$calc=Rpl68::calculate($source,$rpl);
        try{Dates::buildStep6Plan($source,$programme,$rpl,$calc,'2027-02-11','2027-03-11');$this->fail('Capacity accepted');}catch(\InvalidArgumentException $e){$this->assertStringContainsString('capacity',$e->getMessage());}
        $calc['unresolved']=[1=>['Reduction exceeds baseline']];$this->expectException(\InvalidArgumentException::class);
        Dates::buildStep6Plan($source,$programme,$rpl,$calc,'2030-01-11','2030-02-11');
    }

    public function test_subject_tutor_updates_only_their_subject_and_preserves_qualification_and_evidence(): void
    {
        $admin=User::factory()->create(['role'=>'admin','active'=>true]);
        $maths=User::factory()->create(['role'=>'functional_skills_tutor','subject_scope'=>'maths','active'=>true]);
        $a=Apprentice::factory()->create(['maths_tutor_id'=>$maths->id]);
        DB::transaction(fn()=>FS::persist($a,$admin,FS::defaults($admin),0));
        $qualification=Qualification::create(['apprentice_id'=>$a->id,'subject'=>'maths','type'=>'Functional Skills','title'=>'Existing result','level'=>'Level 2','status'=>'Already held','result'=>'Achieved','achievement_date'=>'2026-01-01']);
        $topic=TrainingTopic::create(['apprentice_id'=>$a->id,'subject'=>'maths','level'=>'Level 1','topic'=>'Addition and Subtraction','area'=>'Using Numbers','planned_hours'=>4]);
        $topic->files=[['id'=>'existing-private-file']];$topic->save();
        $values=FS::defaults($maths);$values['maths']['topics']=[0=>5];
        $outcome=ProgrammeOutcome::where('apprentice_id',$a->id)->firstOrFail();$before=$outcome->context;
        $this->actingAs($maths)->post(route('em.setup-assessments.save',$a),['subject'=>'maths','version'=>$outcome->version,'data'=>['maths'=>$values['maths']]])->assertSessionHasErrors();
        $this->assertSame($before,$outcome->fresh()->context);
        $values['maths']['topics']=[0=>4];
        $this->post(route('em.setup-assessments.save',$a),['subject'=>'maths','version'=>$outcome->version,'data'=>['maths'=>$values['maths']]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before['functional_skills_setup82']['english'],FS::current($a->id)['english']);
        $this->assertSame([['id'=>'existing-private-file']],$topic->fresh()->files);
        $this->assertSame('Achieved',$qualification->fresh()->result);$this->assertDatabaseCount('training_topics',1);
        $this->post(route('em.setup-assessments.save',$a),['subject'=>'english','version'=>$outcome->fresh()->version,'data'=>['english'=>$values['english']]])->assertForbidden();
    }

    public function test_configuration_diagnostic_is_read_only_and_identifies_missing_templates(): void
    {
        $before=DB::table('programme_setup_templates68')->count();
        $this->artisan('gta:pack82-check')->expectsOutputToContain('"configuration_ready": false')->assertExitCode(1);
        $this->assertSame($before,DB::table('programme_setup_templates68')->count());
        $this->assertDatabaseCount('apprentices',0);
    }
}
