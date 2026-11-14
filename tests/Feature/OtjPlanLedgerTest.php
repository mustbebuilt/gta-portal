<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\ExtraTraining;
use App\Models\ProgressReviewDraft;
use App\Models\TrainingSession;
use App\Support\OtjPosition;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class OtjPlanLedgerTest extends TestCase
{
    use LazilyRefreshDatabase,CreatesOperationalPlan;

    public function test_source_ledger_uses_confirmed_records_and_whole_ended_blocks_only(): void
    {
        [$a,$admin,$ceo,$tutor,$learner]=$this->approvedFixture();
        $base=['members'=>[$a->id],'session_date'=>today()->subDay(),'state'=>'Planned'];
        $s=TrainingSession::factory()->create($base);
        $m=AttendanceMark::create(['training_session_id'=>$s->id,'apprentice_id'=>$a->id,'code'=>'O','otj_confirmed'=>true,'otj_minutes'=>360,'recorded_by'=>$tutor->id]);
        $pending=TrainingSession::factory()->create($base);AttendanceMark::create(['training_session_id'=>$pending->id,'apprentice_id'=>$a->id,'code'=>'T','otj_confirmed'=>false,'otj_minutes'=>360,'recorded_by'=>$tutor->id]);
        $cancelled=TrainingSession::factory()->create([...$base,'state'=>'Cancelled']);AttendanceMark::create(['training_session_id'=>$cancelled->id,'apprentice_id'=>$a->id,'code'=>'O','otj_confirmed'=>true,'otj_minutes'=>360,'recorded_by'=>$tutor->id]);
        TrainingSession::factory()->create([...$base,'session_date'=>today()->addDay()]);
        ExtraTraining::factory()->create(['apprentice_id'=>$a->id,'approval'=>['by'=>$tutor->id]]);
        ExtraTraining::factory()->create(['apprentice_id'=>$a->id]);
        ExtraTraining::factory()->create(['apprentice_id'=>$a->id,'approval'=>['by'=>$tutor->id],'withdrawn_at'=>now()]);
        ExtraTraining::factory()->create(['apprentice_id'=>$a->id,'training_date'=>today()->addDay(),'approval'=>['by'=>$tutor->id]]);
        $v=OtjPosition::forApprentice($a);$q=$v['summary'];
        $this->assertSame(480,$q['recorded_minutes']);$this->assertSame(2,$q['pending_sources']);$this->assertEquals(100,$q['planned_due_hours']);$this->assertEquals(92,$q['loaded_record_gap_hours']);
        $this->assertEqualsWithDelta(8/110*100,$q['recorded_percent'],0.00001);$this->assertCount(6,$v['rows']);
        $this->assertSame(480,collect($v['rows'])->last()['cumulative_minutes']);
        $m->otj_minutes=300;$m->save();$this->assertSame(420,OtjPosition::forApprentice($a)['summary']['recorded_minutes']);
        $this->actingAs($learner)->get('/apprentices/'.$a->id.'/otj-ledger')->assertOk()->assertSee('Historical completeness is not verified')->assertSee('7.00');
        $pdf=$this->get('/apprentices/'.$a->id.'/otj-ledger/report.pdf')->assertOk()->assertDownload();
        if($dir=getenv('GTA_QA_EXPORT_DIR')){file_put_contents($dir.'/otj-ledger.pdf',$pdf->getContent());}
    }

    public function test_no_or_withdrawn_plan_leaves_target_unknown_and_report_is_scoped(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan]=$this->approvedFixture();
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/delivery-plan/withdraw',['version'=>3,'reason'=>'Fictional source withdrawal']);
        $this->assertNull(OtjPosition::forApprentice($a)['summary']['target_hours']);
        $this->actingAs($learner)->get('/apprentices/'.$a->id.'/otj-ledger')->assertOk()->assertSee('No currently applicable');
        foreach([$this->newActor('tutor'),$this->newActor('apprentice'),$adviser] as $u){$this->actingAs($u)->get('/apprentices/'.$a->id.'/otj-ledger')->assertForbidden();$this->get('/apprentices/'.$a->id.'/otj-ledger/report.pdf')->assertForbidden();}
    }

    public function test_review_captures_fixed_otj_summary_without_changing_source_hours(): void
    {
        [$a,$admin]=$this->approvedFixture();
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/reviews',['fields'=>['summary'=>'Fictional review']])->assertSessionHasNoErrors();
        $r=ProgressReviewDraft::firstOrFail();$q=$r->source_snapshot['otj_position'];$this->assertSame(0,$q['recorded_minutes']);$this->assertEquals(110,$q['target_hours']);
        ExtraTraining::factory()->create(['apprentice_id'=>$a->id,'approval'=>['by'=>$admin->id]]);
        $this->assertSame($q,$r->fresh()->source_snapshot['otj_position']);$this->assertSame(120,OtjPosition::forApprentice($a)['summary']['recorded_minutes']);
    }
}
