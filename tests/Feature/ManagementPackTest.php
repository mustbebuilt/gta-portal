<?php
namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ManagementPack;
use App\Models\TutorGroup;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class ManagementPackTest extends TestCase
{
    use LazilyRefreshDatabase,CreatesOperationalPlan;

    public function test_capture_preserves_fixed_position_and_pdf_without_private_notes_or_unfounded_scores(): void
    {
        [$a,$admin]=$this->approvedFixture();
        $this->actingAs($admin)->get('/reports/management-packs')->assertOk()->assertSee('No qualification aims captured');
        $this->post('/reports/management-packs',['title'=>'Fictional management evidence','confirmed'=>1])->assertSessionHasNoErrors();$pack=ManagementPack::firstOrFail();$snapshot=$pack->snapshot;
        $this->assertEquals(1,$snapshot['summary']['apprentices']);$this->assertFalse(isset($snapshot['compliance_score']));$this->assertArrayNotHasKey('careers',$snapshot['apprentices'][0]);$this->assertArrayNotHasKey('support',$snapshot['apprentices'][0]);
        $a->first_name='Later changed name';$a->save();$admin->name='Renamed after capture';$admin->save();
        $this->get('/reports/management-packs/'.$pack->id)->assertOk()->assertSee('Fictional Apprentice')->assertDontSee('Later changed name')->assertSee($snapshot['captured_actor']['name']);$this->assertSame($snapshot,$pack->fresh()->snapshot);
        $pdf=$this->get('/reports/management-packs/'.$pack->id.'/report.pdf')->assertOk()->assertDownload();if($dir=getenv('GTA_QA_EXPORT_DIR')){file_put_contents($dir.'/management-pack.pdf',$pdf->getContent());}
    }
    public function test_group_filter_and_scope_are_enforced_and_snapshot_tampering_is_rejected(): void
    {
        [$a,$admin,$ceo,$tutor,$learner]=$this->approvedFixture();$g=TutorGroup::factory()->create();$a->tutor_group_id=$g->id;$a->save();Apprentice::factory()->create();
        $this->actingAs($admin)->post('/reports/management-packs',['title'=>'Scoped pack','confirmed'=>1,'group'=>$g->id])->assertSessionHasNoErrors();$pack=ManagementPack::firstOrFail();$this->assertSame([$a->id],$pack->snapshot['scope_apprentice_ids']);
        foreach([$tutor,$learner] as $u){$this->actingAs($u)->get('/reports/management-packs')->assertForbidden();$this->get('/reports/management-packs/'.$pack->id)->assertForbidden();$this->get('/reports/management-packs/'.$pack->id.'/report.pdf')->assertForbidden();$this->post('/reports/management-packs',['title'=>'Forbidden','confirmed'=>1])->assertForbidden();}
        $value=$pack->snapshot;$value['summary']['apprentices']=999;$pack->snapshot=$value;$pack->save();$this->actingAs($admin)->get('/reports/management-packs/'.$pack->id)->assertStatus(409);
        $this->post('/reports/management-packs',['title'=>'Invalid scope','confirmed'=>1,'group'=>999999])->assertSessionHasErrors('group');
    }
}
