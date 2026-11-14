<?php
namespace Tests\Feature;

use App\Models\{Apprentice,ExtraTraining,User};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ExtraTrainingFollowupTest extends TestCase
{
    use LazilyRefreshDatabase;
    protected function setUp(): void {parent::setUp();$this->travelTo(\Carbon\Carbon::parse('2026-11-13 12:00:00'));}
    private function actor(string $role='admin'): User {return User::factory()->create(['role'=>$role]);}
    private function record(Apprentice $a,string $state='agreement',array $fields=[]): ExtraTraining
    {
        $e=ExtraTraining::factory()->create(['apprentice_id'=>$a->id,...$fields]);
        if(in_array($state,['compensation','ready','approved'],true)){$e->agreement=['by'=>1,'at'=>now()->toIso8601String(),'reported'=>false,'source'=>'Apprentice portal confirmation','reference'=>null];}
        if(in_array($state,['ready','approved'],true)){$e->compensation=['method'=>'paid','date'=>today()->subDay()->toDateString(),'covered'=>120,'employer_name'=>'Fictional employer','source'=>'Employer email','reference'=>'PRIVATE CONFIRMATION'];}
        if($state==='approved'){$e->approval=['by'=>1,'at'=>now()->toIso8601String(),'eligible'=>true];}
        if($state==='withdrawn'){$e->withdrawn_at=now();$e->withdrawal_reason='Fictional correction';}
        $e->save();return $e;
    }
    public function test_counts_use_existing_positions_and_pending_is_an_overlapping_total(): void
    {
        $a=Apprentice::factory()->create();foreach(['agreement','compensation','ready','approved','withdrawn'] as $state){$this->record($a,$state);}$r=$this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/extra-training')->assertOk();$this->assertSame(['all'=>5,'pending'=>3,'agreement'=>1,'compensation'=>1,'ready'=>1,'approved'=>1,'withdrawn'=>1],$r->viewData('counts'));$this->assertSame(120,$r->viewData('approvedMinutes'));
    }
    public function test_search_and_status_never_shrink_the_unfiltered_approved_subtotal(): void
    {
        $a=Apprentice::factory()->create();$this->record($a,'approved',['topic'=>'Approved training']);$this->record($a,'agreement',['topic'=>'Pending battery work']);$r=$this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/extra-training?search=battery&show=pending')->assertOk()->assertSee('2.00 hrs')->assertSee('Pending battery work')->assertDontSee('Approved training');$this->assertSame(1,$r->viewData('counts')['all']);$this->assertSame(120,$r->viewData('approvedMinutes'));
    }
    public function test_pagination_is_stable_clamps_and_keeps_filters(): void
    {
        $a=Apprentice::factory()->create();ExtraTraining::factory()->count(22)->create(['apprentice_id'=>$a->id]);$r=$this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/extra-training?show=pending&search=Fictional&page=99')->assertOk();$this->assertSame(2,$r->viewData('records')->currentPage());$this->assertCount(2,$r->viewData('records')->items());$this->assertStringContainsString('show=pending',$r->viewData('records')->previousPageUrl());$ids=$r->viewData('records')->pluck('id')->all();$this->assertSame([2,1],$ids);
    }
    public function test_exact_source_link_opens_older_record_and_cannot_cross_apprentices(): void
    {
        $a=Apprentice::factory()->create();$old=$this->record($a);ExtraTraining::factory()->count(21)->create(['apprentice_id'=>$a->id]);$other=ExtraTraining::factory()->create();$r=$this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/extra-training?record='.$old->id.'&show=approved&search=NO-MATCH&page=99')->assertOk()->assertSee('Showing source record '.$old->id);$this->assertSame($old->id,$r->viewData('records')->first()->id);$this->assertSame(1,$r->viewData('records')->total());$this->get('/apprentices/'.$a->id.'/extra-training?record='.$other->id)->assertNotFound();
    }
    public function test_current_tutor_and_learner_scope_and_private_confirmation_rules_remain(): void
    {
        $tutor=$this->actor('tutor');$learner=$this->actor('apprentice');$a=Apprentice::factory()->create(['tutor_id'=>$tutor->id,'user_id'=>$learner->id]);$this->record($a,'approved');$other=Apprentice::factory()->create();foreach([$tutor,$learner] as $u){$this->actingAs($u)->get('/apprentices/'.$a->id.'/extra-training')->assertOk();$this->get('/apprentices/'.$other->id.'/extra-training')->assertForbidden();}$this->actingAs($learner)->get('/apprentices/'.$a->id.'/extra-training?show=approved')->assertOk()->assertDontSee('PRIVATE CONFIRMATION');$this->actingAs($this->actor('employer'))->get('/apprentices/'.$a->id.'/extra-training')->assertForbidden();
    }
    public function test_get_filters_and_source_links_preserve_record_versions_and_audits(): void
    {
        $a=Apprentice::factory()->create();$e=$this->record($a);$before=$e->fresh()->getAttributes();$this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/extra-training?record='.$e->id)->assertOk();$this->assertSame($before,$e->fresh()->getAttributes());$this->assertDatabaseCount('learning_events',0);
    }
    public function test_invalid_query_shapes_and_values_are_rejected(): void
    {
        $a=Apprentice::factory()->create();$this->actingAs($this->actor());foreach(['show=invalid','search[]=x','page=0','record[]=1','record=0'] as $query){$this->getJson('/apprentices/'.$a->id.'/extra-training?'.$query)->assertUnprocessable();}
    }
    public function test_empty_filter_is_not_completion_and_zero_hours_stays_visible(): void
    {
        $a=Apprentice::factory()->create();$this->record($a);$r=$this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/extra-training?show=approved')->assertOk()->assertSee('No matching extra training')->assertSee('0.00 hrs')->assertSee('does not mean training was not required or completed');$this->assertSame(0,$r->viewData('records')->total());$this->assertSame(1,$r->viewData('counts')['all']);
    }
}
