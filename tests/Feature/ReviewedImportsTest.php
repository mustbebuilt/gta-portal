<?php
namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\DataImportBatch;
use App\Models\ExtraTraining;
use App\Models\HistoricalOtjRecord;
use App\Models\Qualification;
use App\Models\TrainingSession;
use App\Models\TrainingTopic;
use App\Support\OtjPosition;
use App\Support\ReviewedImports;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class ReviewedImportsTest extends TestCase
{
    use LazilyRefreshDatabase,CreatesOperationalPlan;

    private function csv(string $kind,array $rows): string
    {
        $f=fopen('php://temp','r+');fputcsv($f,ReviewedImports::HEADERS[$kind],',','"','');foreach($rows as $row){fputcsv($f,$row,',','"','');}rewind($f);return stream_get_contents($f);
    }
    private function prepare(string $kind,array $rows,$actor): DataImportBatch
    {
        $this->actingAs($actor)->post('/data-imports/'.$kind,['source_reference'=>'Fictional checked source statement','file'=>UploadedFile::fake()->createWithContent('source.csv',$this->csv($kind,$rows))])->assertSessionHasNoErrors();
        return DataImportBatch::orderByDesc('id')->firstOrFail();
    }
    private function payload(DataImportBatch $b): array {return ['version'=>$b->version,'preview_hash'=>$b->preview_hash,'source_checked'=>1,'results_checked'=>1,'occupational_checked'=>1,'duplicate_checked'=>1];}
    private function aim($a,array $values=[]): Qualification {return Qualification::factory()->create(['apprentice_id'=>$a->id,'subject'=>'maths','type'=>'Functional Skills','status'=>'Working towards','start_date'=>today()->subDays(100),'result'=>null,'achievement_date'=>null,...$values]);}
    private function resultRow($q): array {return [$q->id,$q->version,'Achieved','Achieved',today()->subDays(2)->toDateString(),'Fictional awarding-result reference'];}
    private function history($a,array $values=[]): array {return [$a->id,today()->subDays(40)->toDateString(),'17:00','19:00','30','Fictional historic diagnostic learning','Fictional attendance/evidence reference',...$values];}

    public function test_qualification_preview_is_read_only_and_independent_application_preserves_start_topics_and_history(): void
    {
        [$a,$admin,$ceo]=$this->approvedFixture();$q=$this->aim($a);$start=$q->start_date->toDateString();$target=$q->target()->toDateString();
        $topic=TrainingTopic::factory()->create(['apprentice_id'=>$a->id,'subject'=>'maths','status'=>'In Progress']);
        $this->actingAs($admin)->get('/data-imports/qualification-results/create?lookup='.$a->reference)->assertOk()->assertSee('Source ID')->assertSee($a->reference)->assertSee($q->level);
        $batch=$this->prepare('qualification-results',[$this->resultRow($q)],$admin);
        $this->assertSame('Working towards',$q->fresh()->status);$this->assertDatabaseCount('data_import_events',1);
        $this->get('/data-imports/batches/'.$batch->id)->assertOk()->assertSee('Fictional awarding-result reference');
        $this->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertSessionHasErrors('file');
        $this->actingAs($ceo)->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertSessionHasNoErrors();
        $q->refresh();$this->assertSame('Achieved',$q->status);$this->assertSame('Achieved',$q->result);$this->assertSame($start,$q->start_date->toDateString());$this->assertSame($target,$q->target()->toDateString());
        $this->assertSame('In Progress',$topic->fresh()->status);$this->assertSame($batch->id,$q->history[0]['import_batch_id']);$this->assertSame(2,$q->version);
        $this->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertSessionHasErrors('file');$this->assertSame(2,$q->fresh()->version);
    }

    #[TestWith(['wrong_grade'])] #[TestWith(['future'])] #[TestWith(['before_start'])] #[TestWith(['achieved_already'])] #[TestWith(['duplicate'])] #[TestWith(['awaiting_with_result'])] #[TestWith(['awaiting_zero'])]
    public function test_invalid_or_duplicate_qualification_rows_never_prepare_a_batch(string $case): void
    {
        [$a,$admin]=$this->approvedFixture();$q=$this->aim($a);$row=$this->resultRow($q);
        if($case==='wrong_grade'){$row[3]='Pass';}if($case==='future'){$row[4]=today()->addDay()->toDateString();}if($case==='before_start'){$row[4]=today()->subDays(101)->toDateString();}
        if($case==='achieved_already'){$q->status='Achieved';$q->save();}if($case==='awaiting_with_result'){$row[2]='Awaiting confirmation';}if($case==='awaiting_zero'){$row[2]='Awaiting confirmation';$row[3]='0';$row[4]='';}
        $rows=$case==='duplicate'?[$row,$row]:[$row];
        $this->actingAs($admin)->post('/data-imports/qualification-results',['source_reference'=>'Fictional result source','file'=>UploadedFile::fake()->createWithContent('source.csv',$this->csv('qualification-results',$rows))])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('data_import_batches',0);
    }

    public function test_stale_qualification_blocks_whole_batch_and_audit_failure_rolls_back_all_rows(): void
    {
        [$a,$admin,$ceo]=$this->approvedFixture();$q1=$this->aim($a);$q2=$this->aim($a);$batch=$this->prepare('qualification-results',[$this->resultRow($q1),$this->resultRow($q2)],$admin);
        $q2->version++;$q2->save();$this->actingAs($ceo)->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertSessionHasErrors('file');$this->assertSame('Working towards',$q1->fresh()->status);
        $batch=$this->prepare('qualification-results',[$this->resultRow($q1),$this->resultRow($q2)],$admin);
        \Tests\Support\AuditFault::install('reject_import_event','data_import_events','Fictional audit failure','applied');
        $this->actingAs($ceo)->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertStatus(500);
        $this->assertSame('Working towards',$q1->fresh()->status);$this->assertSame('Working towards',$q2->fresh()->status);$this->assertSame('Preview',$batch->fresh()->state);
    }

    public function test_historical_import_adds_only_reviewed_minutes_and_reasoned_withdrawal_retains_source(): void
    {
        [$a,$admin,$ceo,$tutor,$learner]=$this->approvedFixture();$batch=$this->prepare('historical-otj',[$this->history($a)],$admin);
        $this->assertDatabaseCount('historical_otj_records',0);$this->assertSame(0,OtjPosition::forApprentice($a)['summary']['recorded_minutes']);
        $this->actingAs($ceo)->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertSessionHasNoErrors();
        $record=HistoricalOtjRecord::firstOrFail();$this->assertSame(90,$record->minutes);$this->assertSame(90,OtjPosition::forApprentice($a)['summary']['historical_minutes']);$this->assertDatabaseCount('attendance_marks',0);
        $this->actingAs($learner)->get('/apprentices/'.$a->id.'/otj-ledger')->assertOk()->assertSee('1.50')->assertDontSee('Withdraw imported record');
        $this->actingAs($admin)->post('/historical-otj/'.$record->id.'/withdraw',['version'=>1,'reason'=>'Fictional duplicate discovered in paper source'])->assertSessionHasNoErrors();
        $this->assertSame(0,OtjPosition::forApprentice($a)['summary']['recorded_minutes']);$this->assertSame(90,$record->fresh()->minutes);$this->assertCount(2,$record->fresh()->history);
    }

    #[TestWith(['register'])] #[TestWith(['extra'])] #[TestWith(['batch'])] #[TestWith(['future'])] #[TestWith(['invalid_minutes'])] #[TestWith(['today_future'])]
    public function test_historical_duplicate_intervals_dates_and_minutes_are_rejected(string $case): void
    {
        [$a,$admin]=$this->approvedFixture();$row=$this->history($a);$rows=[$row];
        if($case==='register'){TrainingSession::factory()->create(['members'=>[$a->id],'session_date'=>$row[1],'start_time'=>'18:00','end_time'=>'20:00']);}
        if($case==='extra'){ExtraTraining::factory()->create(['apprentice_id'=>$a->id,'training_date'=>$row[1],'start_time'=>'18:00','end_time'=>'20:00']);}
        if($case==='batch'){$rows[]=$row;}
        if($case==='future'){$rows[0][1]=today()->addDay()->toDateString();}
        if($case==='today_future'){$this->travelTo(today()->setTime(12,0));$rows[0][1]=today()->toDateString();}
        if($case==='invalid_minutes'){$rows[0][4]='120';}
        $this->actingAs($admin)->post('/data-imports/historical-otj',['source_reference'=>'Fictional historic source','file'=>UploadedFile::fake()->createWithContent('source.csv',$this->csv('historical-otj',$rows))])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('data_import_batches',0);
    }

    public function test_intervening_overlap_stale_profile_expiry_and_missing_checks_block_application(): void
    {
        [$a,$admin,$ceo]=$this->approvedFixture();$row=$this->history($a);$batch=$this->prepare('historical-otj',[$row],$admin);$url='/data-imports/batches/'.$batch->id.'/apply';
        $this->actingAs($ceo)->post($url,['version'=>1,'preview_hash'=>$batch->preview_hash])->assertSessionHasErrors('source_checked');
        $a->version++;$a->save();$this->post($url,$this->payload($batch))->assertSessionHasErrors('file');
        $batch=$this->prepare('historical-otj',[$row],$admin);TrainingSession::factory()->create(['members'=>[$a->id],'session_date'=>$row[1],'start_time'=>'18:00','end_time'=>'20:00']);
        $this->actingAs($ceo)->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertSessionHasErrors('file');$this->assertDatabaseCount('historical_otj_records',0);
        $q=$this->aim($a);$batch=$this->prepare('qualification-results',[$this->resultRow($q)],$admin);$batch->created_at=now()->subHours(25);$batch->save();
        $this->actingAs($ceo)->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertSessionHasErrors('file');
    }

    public function test_later_source_overlap_is_a_reconciliation_check_not_double_counted_hours(): void
    {
        [$a,$admin,$ceo]=$this->approvedFixture();$row=$this->history($a);$batch=$this->prepare('historical-otj',[$row],$admin);
        $this->actingAs($ceo)->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertSessionHasNoErrors();
        TrainingSession::factory()->create(['members'=>[$a->id],'session_date'=>$row[1],'start_time'=>'18:00','end_time'=>'20:00']);
        $summary=OtjPosition::forApprentice($a)['summary'];$this->assertSame(0,$summary['historical_minutes']);$this->assertSame(1,$summary['invalid_sources']);
        $this->actingAs($admin)->get('/apprentices/'.$a->id.'/otj-ledger')->assertSee('Imported interval overlaps another source');
    }

    public function test_import_pages_uploads_templates_and_actions_are_manager_only(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser]=$this->approvedFixture();$q=$this->aim($a);$batch=$this->prepare('qualification-results',[$this->resultRow($q)],$admin);
        foreach([$tutor,$learner,$adviser] as $u){$this->actingAs($u)->get('/data-imports')->assertForbidden();$this->get('/data-imports/batches/'.$batch->id)->assertForbidden();$this->get('/data-imports/qualification-results/template.csv')->assertForbidden();$this->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertForbidden();}
        $this->actingAs($admin)->get('/data-imports/qualification-results/template.csv')->assertOk()->assertDownload();$this->get('/data-imports/unknown/create')->assertNotFound();
    }
    public function test_unsupported_headers_and_excess_rows_never_create_a_batch(): void
    {
        [$a,$admin]=$this->approvedFixture();$q=$this->aim($a);
        foreach(["wrong,headers\n1,2\n",$this->csv('qualification-results',Qualification::factory()->count(201)->create(['apprentice_id'=>$a->id,'status'=>'Working towards','start_date'=>today()->subDays(100),'result'=>null,'achievement_date'=>null])->map(fn($aim)=>$this->resultRow($aim))->all())] as $csv){
            $this->actingAs($admin)->post('/data-imports/qualification-results',['source_reference'=>'Fictional source','file'=>UploadedFile::fake()->createWithContent('source.csv',$csv)])->assertSessionHasErrors('file');
        }
        $this->assertDatabaseCount('data_import_batches',0);
    }

    public function test_json_object_order_does_not_invalidate_import_or_report_bindings(): void
    {
        $this->assertSame(ReviewedImports::hash(['x'=>['a'=>1,'b'=>2],'y'=>3]),ReviewedImports::hash(['y'=>3,'x'=>['b'=>2,'a'=>1]]));
        $this->assertNotSame(ReviewedImports::hash([1,2]),ReviewedImports::hash([2,1]));
        [$a,$admin,$ceo]=$this->approvedFixture();$q=$this->aim($a,['type'=>'Other']);$row=$this->resultRow($q);$row[3]='0';
        $batch=$this->prepare('qualification-results',[$row],$admin);$batch->rows=array_map(fn($r)=>array_reverse($r,true),$batch->rows);$batch->save();
        $this->actingAs($ceo)->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertSessionHasNoErrors();$this->assertSame('0',$q->fresh()->result);
    }

    public function test_historical_and_withdrawal_audit_failure_preserve_source_rows_atomically(): void
    {
        [$a,$admin,$ceo]=$this->approvedFixture();$batch=$this->prepare('historical-otj',[$this->history($a)],$admin);
        \Tests\Support\AuditFault::install('reject_history_audit','learning_events','Fictional audit failure');
        $this->actingAs($ceo)->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertStatus(500);$this->assertDatabaseCount('historical_otj_records',0);$this->assertSame('Preview',$batch->fresh()->state);
        DB::statement('DROP TRIGGER reject_history_audit');$this->post('/data-imports/batches/'.$batch->id.'/apply',$this->payload($batch))->assertSessionHasNoErrors();$record=HistoricalOtjRecord::firstOrFail();
        \Tests\Support\AuditFault::install('reject_history_audit','learning_events','Fictional audit failure');
        $this->post('/historical-otj/'.$record->id.'/withdraw',['version'=>1,'reason'=>'Fictional correction'])->assertStatus(500);$this->assertNull($record->fresh()->withdrawn_at);$this->assertSame(1,$record->fresh()->version);
    }

}
