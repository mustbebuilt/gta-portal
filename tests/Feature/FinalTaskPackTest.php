<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\Programme;
use App\Models\User;
use App\Support\TaskCatalogue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class FinalTaskPackTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[TestWith(['autocare'])] #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_each_route_has_fifty_final_pdf_sources_with_no_extra_task_and_unlimited_marking_narrative(string $key): void
    {
        $p=TaskCatalogue::PROGRAMMES[$key];$programme=Programme::factory()->create(['standard_code'=>$p['code'],'version'=>$p['version']]);
        $a=Apprentice::factory()->create(['programme_id'=>$programme->id]);
        $admin=User::factory()->create();$admin->role='admin';$admin->save();
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/learning',['confirmed'=>1])->assertSessionHasNoErrors();
        $page=$this->get('/apprentices/'.$a->id.'/learning')->assertOk();
        if($key==='paint'){$page->assertSee('Controlled Paint SOW version');}
        elseif($key==='autocare'){$page->assertSee('Existing Autocare KSB reconciliation');}
        else{$page->assertDontSee('Controlled Paint SOW version')->assertDontSee('Existing Autocare KSB reconciliation');}
        $tasks=LearningTask::where('apprentice_id',$a->id)->get();$this->assertCount(50,$tasks);
        foreach($tasks as $t) {
            $this->assertStringStartsWith('final-20261113-qa2--'.$key.'--',$t->template['sourcePdfFilename']);
            $this->assertSame($t->template['sourceTemplateHash'],hash_file('sha256',resource_path('task-pdfs/'.$t->template['sourcePdfFilename'])));
            $this->assertNotSame('source-pending',$t->template['kind']);
        }
        $paper=$tasks->first(fn($t)=>$t->block===1 && $t->template['kind']==='knowledge');
        $title=str_repeat("Actual paper test title with paragraph breaks.\n",600)."END TITLE MARKER";
        $feedback=str_repeat("Actual tutor feedback retained without truncation.\n",800)."END FEEDBACK MARKER";
        $this->post('/tasks/'.$paper->id.'/decision',['version'=>1,'decision'=>'Approved','decision_date'=>today()->toDateString(),'confirmed'=>1,'test_title'=>$title,'marks_available'=>20,'marks_achieved'=>16,'feedback'=>$feedback])->assertSessionHasNoErrors();
        $d=$paper->fresh()->submissions[0]['decision'];
        $this->assertSame(trim($title),$d['source_fields']['test_title']);
        $this->assertSame(trim($feedback),$d['feedback']);
        if ($key==='autocare' && ($dir=getenv('GTA_QA_EXPORT_DIR'))) {
            $pdf=$this->get('/tasks/'.$paper->id.'/recorded-work.pdf')->assertOk();
            file_put_contents($dir.'/final-paper-long-answer.pdf',$pdf->getContent());
        }
    }
}
