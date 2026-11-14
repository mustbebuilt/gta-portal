<?php
namespace Tests\Feature;
use App\Models\{Apprentice,LearningTask,Programme,SourceReconciliation,TutorGroup,User};
use App\Support\{CurriculumSources,ReviewedImports,SourceReconciliations,TaskCatalogue};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;
class CurriculumSourcesTest extends TestCase
{
    use LazilyRefreshDatabase;
    private function fixture(string $key='autocare',bool $allocate=true): array
    {
        $source=TaskCatalogue::PROGRAMMES[$key];$p=Programme::factory()->create(['standard_code'=>$source['code'],'version'=>$source['version']]);$admin=User::factory()->create(['role'=>'admin']);$reviewer=User::factory()->create(['role'=>'ceo']);$tutor=User::factory()->create(['role'=>'tutor']);$a=Apprentice::factory()->create(['programme_id'=>$p->id,'tutor_id'=>$tutor->id,'start_date'=>today()->subMonths(6)]);if($allocate){$this->actingAs($admin)->post('/apprentices/'.$a->id.'/learning',['confirmed'=>1])->assertSessionHasNoErrors();}return [$a,$admin,$reviewer,$tutor,$p];
    }
    private function data(Apprentice $a,string $decision='retain'): array
    {
        return ['binding'=>CurriculumSources::apprentice($a)['binding'],'decision'=>$decision,'source_reference'=>'Fictional controlled source v1; actual review required','scope_note'=>'Fictional cohort source assessment','reason'=>'Fictional assessment for workflow tests, not actual curriculum approval','confirmed'=>1];
    }
    private function submit(Apprentice $a,User $actor,string $decision='retain'): SourceReconciliation
    {
        $this->actingAs($actor)->post('/apprentices/'.$a->id.'/source-comparison',$this->data($a,$decision))->assertSessionHasNoErrors();return SourceReconciliation::latest('id')->firstOrFail();
    }
    #[TestWith(['autocare'])] #[TestWith(['heavy-vehicle'])] #[TestWith(['paint'])] #[TestWith(['panel'])]
    public function test_loaded_release_audits_all_fifty_sources_and_exact_saved_allocations_without_modifying_work(string $key): void
    {
        [$a,$admin]=$this->fixture($key);$before=LearningTask::where('apprentice_id',$a->id)->get()->map->getAttributes()->all();$release=CurriculumSources::release($key);$this->assertTrue($release['technical_ok']);$this->assertSame(50,$release['task_count']);$this->assertSame(50,$release['pdfs_checked']);$audit=CurriculumSources::apprentice($a);$this->assertSame(50,$audit['counts']['current']);$this->assertSame(0,$audit['missing_count']);$this->assertSame($before,LearningTask::where('apprentice_id',$a->id)->get()->map->getAttributes()->all());$this->assertFalse($release['curriculum_approval']);$this->actingAs($admin)->get('/apprentices/'.$a->id.'/source-comparison')->assertOk()->assertSee('Matches current task source');
    }
    public function test_paint_and_autocare_warnings_remain_visible_after_independent_review(): void
    {
        foreach(['paint'=>'Controlled Paint SOW version','autocare'=>'Existing Autocare KSB reconciliation']as$key=>$notice){[$a,$admin,$reviewer,$tutor]=$this->fixture($key);$record=$this->submit($a,$tutor);$this->actingAs($reviewer)->post('/apprentices/'.$a->id.'/source-comparison/'.$record->id,['version'=>1,'action'=>'record','note'=>'Fictional independent source review; actual decision still required','confirmed'=>1])->assertSessionHasNoErrors();$this->assertSame('Review recorded',$record->fresh()->state);$this->get('/apprentices/'.$a->id.'/source-comparison')->assertSee($notice);$this->get('/apprentices/'.$a->id.'/learning')->assertSee($notice);}
    }
    public function test_current_schema_change_and_recognised_prior_pdf_are_distinguished_without_migration(): void
    {
        [$a]=$this->fixture();$task=LearningTask::where('apprentice_id',$a->id)->first();$schema=$task->template;$schema['instructions'].=' Fictional saved historic amendment.';$task->template=$schema;$task->save();$audit=CurriculumSources::apprentice($a);$this->assertSame('different',$audit['snapshot']['items'][0]['category']);$prior=json_decode(file_get_contents(resource_path('data/final-task-pack-v1-receipt.json')),true);$pdf=collect($prior['pdfs'])->first(fn($row)=>$row['catalogue']==='autocare' && $row['reference']===$task->reference);$schema['sourcePdfFilename']=$pdf['filename'];$schema['sourceTemplateHash']=$pdf['sha256'];$task->template=$schema;$task->save();$audit=CurriculumSources::apprentice($a);$this->assertSame('retained',$audit['snapshot']['items'][0]['category']);$this->assertSame($schema,$task->fresh()->template);
    }
    public function test_legacy_implicit_programme_is_flagged_and_cannot_be_recorded_as_retain(): void
    {
        [$a,$admin]=$this->fixture();$a->programme_id=null;$a->save();$audit=CurriculumSources::apprentice($a);$this->assertFalse($audit['scope_ok']);$this->assertStringContainsString('legacy Autocare fallback',$audit['snapshot']['context_issues'][0]);$this->actingAs($admin)->post('/apprentices/'.$a->id.'/source-comparison',$this->data($a))->assertSessionHasErrors('source_review');$record=$this->submit($a,$admin,'investigate');$this->assertSame('investigate',$record->decision);
    }
    public function test_missing_allocation_inactive_programme_and_unrecognised_saved_pdf_require_investigation(): void
    {
        [$a,$admin,$reviewer,$tutor,$p]=$this->fixture(allocate:false);$audit=CurriculumSources::apprentice($a);$this->assertSame(50,$audit['missing_count']);$this->actingAs($admin)->post('/apprentices/'.$a->id.'/source-comparison',$this->data($a))->assertSessionHasErrors('source_review');$this->submit($a,$tutor,'investigate');$p->active=false;$p->save();$this->assertFalse(CurriculumSources::apprentice($a)['scope_ok']);
    }
    public function test_mutated_saved_schema_invalid_pdf_locator_is_never_followed_and_cannot_be_retained(): void
    {
        [$a,$admin]=$this->fixture();$task=LearningTask::where('apprentice_id',$a->id)->first();$schema=$task->template;$schema['sourcePdfFilename']='../PRIVATE-OUTSIDE.pdf';$task->template=$schema;$task->save();$audit=CurriculumSources::apprentice($a);$this->assertSame('invalid',$audit['snapshot']['items'][0]['category']);$this->assertSame('invalid_pdf_location',$audit['snapshot']['items'][0]['saved_pdf']['status']);$this->actingAs($admin)->post('/apprentices/'.$a->id.'/source-comparison',$this->data($a))->assertSessionHasErrors('source_review');
    }
    public function test_answers_status_marking_and_deadline_changes_do_not_change_the_source_binding_or_leak_into_snapshot(): void
    {
        [$a]=$this->fixture();$before=CurriculumSources::apprentice($a);$task=LearningTask::where('apprentice_id',$a->id)->first();$task->answers=['private'=>'PRIVATE-APPRENTICE-ANSWER'];$task->status='Approved';$task->due_date=today()->addDays(30);$task->version++;$task->submissions=[['private'=>'PRIVATE-MARKING-FEEDBACK']];$task->save();$after=CurriculumSources::apprentice($a);$this->assertSame($before['binding'],$after['binding']);$json=json_encode($after['snapshot']);foreach(['PRIVATE-APPRENTICE-ANSWER','PRIVATE-MARKING-FEEDBACK',$a->last_name]as$value){$this->assertStringNotContainsString($value,$json);}
    }
    public function test_cohort_programme_tutor_start_and_saved_source_changes_stale_the_assessment(): void
    {
        [$a,$admin,$reviewer,$tutor]=$this->fixture();$record=$this->submit($a,$tutor);$audit=CurriculumSources::apprentice($a);$this->assertTrue(SourceReconciliations::current($record,$audit));$a->start_date=$a->start_date->addDay();$a->save();$this->assertFalse(SourceReconciliations::current($record,CurriculumSources::apprentice($a)));$this->actingAs($reviewer)->post('/apprentices/'.$a->id.'/source-comparison/'.$record->id,['version'=>1,'action'=>'record','note'=>'Fictional note','confirmed'=>1])->assertSessionHasErrors('source_review');$this->post('/apprentices/'.$a->id.'/source-comparison/'.$record->id,['version'=>1,'action'=>'return','note'=>'Cohort source changed; investigate','confirmed'=>1])->assertSessionHasNoErrors();$this->assertSame('Returned',$record->fresh()->state);
    }
    public function test_author_cannot_self_review_and_other_tutor_or_learner_cannot_read_or_submit(): void
    {
        [$a,$admin,$reviewer,$tutor]=$this->fixture();$record=$this->submit($a,$admin);$this->actingAs($admin)->post('/apprentices/'.$a->id.'/source-comparison/'.$record->id,['version'=>1,'action'=>'record','note'=>'Self review refused','confirmed'=>1])->assertForbidden();foreach(['tutor','apprentice','employer','mentor','functional_skills_tutor','inclusion_lead']as$role){$actor=User::factory()->create(['role'=>$role]);$this->actingAs($actor)->get('/apprentices/'.$a->id.'/source-comparison')->assertForbidden();$this->get('/apprentices/'.$a->id.'/source-comparison/'.$record->id)->assertForbidden();$this->post('/apprentices/'.$a->id.'/source-comparison',$this->data($a))->assertForbidden();}
    }
    public function test_independent_review_preserves_source_task_records_and_has_exact_version_and_confirmation_guards(): void
    {
        [$a,$admin,$reviewer,$tutor]=$this->fixture();$before=LearningTask::where('apprentice_id',$a->id)->get()->map->getAttributes()->all();$record=$this->submit($a,$tutor);$this->actingAs($reviewer)->post('/apprentices/'.$a->id.'/source-comparison/'.$record->id,['version'=>0,'action'=>'record','note'=>'Fictional note','confirmed'=>1])->assertSessionHasErrors('source_review');$this->post('/apprentices/'.$a->id.'/source-comparison/'.$record->id,['version'=>1,'action'=>'record','note'=>'Fictional note'])->assertSessionHasErrors('confirmed');$this->post('/apprentices/'.$a->id.'/source-comparison/'.$record->id,['version'=>1,'action'=>'record','note'=>'Fictional independent review','confirmed'=>1])->assertSessionHasNoErrors();$this->assertSame(2,$record->fresh()->version);$this->assertDatabaseCount('source_reconciliation_events',2);$this->assertSame($before,LearningTask::where('apprentice_id',$a->id)->get()->map->getAttributes()->all());
    }
    public function test_stale_submission_and_cross_apprentice_record_url_are_refused(): void
    {
        [$a,$admin,$reviewer,$tutor]=$this->fixture();$data=$this->data($a);$task=LearningTask::where('apprentice_id',$a->id)->first();$task->title.=' changed';$task->save();$this->actingAs($tutor)->post('/apprentices/'.$a->id.'/source-comparison',$data)->assertSessionHasErrors('source_review');$record=$this->submit($a,$tutor,'investigate');$other=Apprentice::factory()->create();$this->actingAs($admin)->get('/apprentices/'.$other->id.'/source-comparison/'.$record->id)->assertNotFound();$this->post('/apprentices/'.$other->id.'/source-comparison/'.$record->id,['version'=>1,'action'=>'withdraw','note'=>'Wrong cohort','confirmed'=>1])->assertNotFound();
    }
    public function test_withdrawal_preserves_history_and_previous_review_note(): void
    {
        [$a,$admin,$reviewer,$tutor]=$this->fixture();$record=$this->submit($a,$tutor);SourceReconciliations::review($record,$reviewer,1,1,'record','Fictional independent review');SourceReconciliations::review($record->fresh(),$admin,1,2,'withdraw','Source decision superseded');$fresh=$record->fresh();$this->assertSame('Withdrawn',$fresh->state);$this->assertSame('Fictional independent review',$fresh->review_note);$this->assertDatabaseCount('source_reconciliation_events',3);$this->actingAs($reviewer)->post('/apprentices/'.$a->id.'/source-comparison/'.$record->id,['version'=>3,'action'=>'record','note'=>'Cannot restore withdrawn record','confirmed'=>1])->assertSessionHasErrors('source_review');
    }
    public function test_canonical_database_json_order_preserves_review_binding_and_tampering_fails_closed(): void
    {
        [$a,$admin,$reviewer,$tutor]=$this->fixture();$record=$this->submit($a,$tutor);$snapshot=$record->snapshot;ksort($snapshot);$record->snapshot=$snapshot;$record->save();$this->assertTrue(SourceReconciliations::current($record->fresh(),CurriculumSources::apprentice($a)));$snapshot['catalogue_key']='paint';$record->snapshot=$snapshot;$record->save();$this->assertFalse(SourceReconciliations::current($record->fresh(),CurriculumSources::apprentice($a)));
    }
    public function test_blank_references_oversized_notes_unknown_decisions_and_missing_confirmation_are_rejected(): void
    {
        [$a,$admin]=$this->fixture();$data=$this->data($a);foreach([['source_reference',''],['scope_note',''],['reason',str_repeat('x',2001)],['decision','approve-funding'],['confirmed',0]]as[$field,$value]){$bad=$data;$bad[$field]=$value;$this->actingAs($admin)->post('/apprentices/'.$a->id.'/source-comparison',$bad)->assertSessionHasErrors($field);}$this->assertDatabaseCount('source_reconciliations',0);
    }
    public function test_global_technical_command_is_readonly_and_not_a_curriculum_approval(): void
    {
        $this->artisan('gta:curriculum-source-check')->expectsOutputToContain('"curriculum_approval":false')->assertExitCode(0);$this->artisan('gta:curriculum-source-check',['--programme'=>'unknown'])->assertExitCode(1);$this->assertDatabaseCount('source_reconciliations',0);$this->assertDatabaseCount('learning_tasks',0);$admin=User::factory()->create(['role'=>'admin']);$this->actingAs($admin)->get('/curriculum-source-checks')->assertOk()->assertSee('Controlled Paint SOW version')->assertSee('Existing Autocare KSB reconciliation');
    }
    public function test_history_is_paginated_and_assigned_tutor_can_read_but_cannot_review(): void
    {
        [$a,$admin,$reviewer,$tutor]=$this->fixture();$record=$this->submit($a,$tutor);for($i=0;$i<11;$i++){$clone=$record->replicate();$clone->save();}$this->actingAs($tutor)->get('/apprentices/'.$a->id.'/source-comparison?page=2')->assertOk()->assertSee('page=1');$this->post('/apprentices/'.$a->id.'/source-comparison/'.$record->id,['version'=>1,'action'=>'record','note'=>'Tutor cannot review','confirmed'=>1])->assertForbidden();
    }
    private function isolatedSources(callable $fn): void
    {
        $original=base_path();$tmp=storage_path('framework/testing/source-fixture-'.\Illuminate\Support\Str::uuid());mkdir($tmp.'/resources/data',0700,true);
        foreach(array_keys(TaskCatalogue::PROGRAMMES)as$key){foreach(['tasks','catalogue']as$suffix){copy(resource_path('data/'.$key.'-'.$suffix.'.json'),$tmp.'/resources/data/'.$key.'-'.$suffix.'.json');}}
        foreach(['final-task-pack-receipt','final-task-pack-v1-receipt','task-pack-qa2-audit','original-pdf-hashes','heavy-vehicle-source-receipt','paint-source-receipt','panel-source-receipt']as$name){copy(resource_path('data/'.$name.'.json'),$tmp.'/resources/data/'.$name.'.json');}
        symlink(resource_path('task-pdfs'),$tmp.'/resources/task-pdfs');$this->app->setBasePath($tmp);
        try{$fn($tmp);}finally{$this->app->setBasePath($original);if(is_link($tmp.'/resources/task-pdfs')){unlink($tmp.'/resources/task-pdfs');}(new \Illuminate\Filesystem\Filesystem)->deleteDirectory($tmp);}
    }
    public function test_invalid_catalogue_and_duplicate_task_definitions_fail_closed_in_an_isolated_source_copy(): void
    {
        $this->isolatedSources(function($root){$path=$root.'/resources/data/autocare-tasks.json';$tasks=json_decode(file_get_contents($path),true);$tasks[1]['id']=$tasks[0]['id'];file_put_contents($path,json_encode($tasks));$result=CurriculumSources::release('autocare');$this->assertFalse($result['technical_ok']);$this->assertNotEmpty($result['technical_issues']);file_put_contents($root.'/resources/data/autocare-catalogue.json','{invalid');$result=CurriculumSources::release('autocare');$this->assertContains('source_data_unreadable_or_incompatible',$result['technical_issues']);});
    }
    public function test_receipt_mismatch_and_removed_source_notice_are_detected_without_modifying_shipped_assets(): void
    {
        $this->isolatedSources(function($root){$path=$root.'/resources/data/final-task-pack-receipt.json';$receipt=json_decode(file_get_contents($path),true);$receipt['pdfs'][0]['sha256']=str_repeat('f',64);file_put_contents($path,json_encode($receipt));$result=CurriculumSources::release('autocare');$this->assertFalse($result['technical_ok']);$path=$root.'/resources/data/task-pack-qa2-audit.json';$audit=json_decode(file_get_contents($path),true);$audit['open_items']=[];file_put_contents($path,json_encode($audit));$this->assertContains('retained_curriculum_notice_missing',CurriculumSources::release('paint')['technical_issues']);});
    }
    public function test_changed_blank_task_pdf_changes_the_source_binding_and_prevents_retention_assessment(): void
    {
        [$a,$admin,$reviewer,$tutor]=$this->fixture();$record=$this->submit($a,$tutor);$this->isolatedSources(function($root)use($a,$record){$source=CurriculumSources::release('autocare');$name=TaskCatalogue::template('b1-t1')['sourcePdfFilename'];unlink($root.'/resources/task-pdfs');mkdir($root.'/resources/task-pdfs');file_put_contents($root.'/resources/task-pdfs/'.$name,'Fictional changed blank source');$after=CurriculumSources::apprentice($a);$this->assertFalse($after['scope_ok']);$this->assertFalse(SourceReconciliations::current($record,$after));$this->assertSame('invalid',$after['snapshot']['items'][0]['category']);$this->assertNotSame($source['binding'],$after['release']['binding']);});
    }
    public function test_tutor_reassignment_revokes_old_tutor_scope_and_stales_the_recorded_cohort_binding(): void
    {
        [$a,$admin,$reviewer,$tutor]=$this->fixture();$record=$this->submit($a,$tutor);$new=User::factory()->create(['role'=>'tutor']);$a->tutor_id=$new->id;$a->save();$this->actingAs($tutor)->get('/apprentices/'.$a->id.'/source-comparison/'.$record->id)->assertForbidden();$this->actingAs($new)->get('/apprentices/'.$a->id.'/source-comparison/'.$record->id)->assertOk()->assertSee('Source position changed');$this->assertFalse(SourceReconciliations::current($record,CurriculumSources::apprentice($a)));
    }
    public function test_stale_signed_in_admin_session_cannot_review_a_pending_source_assessment(): void
    {
        [$a,$admin,$reviewer,$tutor]=$this->fixture();$record=$this->submit($a,$tutor);$this->actingAs($reviewer);$reviewer->auth_version++;$reviewer->save();$this->post('/apprentices/'.$a->id.'/source-comparison/'.$record->id,['version'=>1,'action'=>'record','note'=>'Fictional review','confirmed'=>1])->assertRedirect('/login');$this->assertSame('Submitted',$record->fresh()->state);$this->assertDatabaseCount('source_reconciliation_events',1);
    }
    public function test_oversized_saved_task_set_is_bounded_and_cannot_create_a_partial_source_assessment(): void
    {
        [$a,$admin]=$this->fixture(allocate:false);$schema=TaskCatalogue::template('b1-t1');for($i=0;$i<201;$i++){LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'unexpected-'.$i,'template'=>$schema]);}$audit=CurriculumSources::apprentice($a);$this->assertSame(201,$audit['recorded_count']);$this->assertCount(200,$audit['snapshot']['items']);$this->assertFalse($audit['scope_ok']);$this->actingAs($admin)->post('/apprentices/'.$a->id.'/source-comparison',$this->data($a,'investigate'))->assertSessionHasErrors('source_review');$this->assertDatabaseCount('source_reconciliations',0);
    }
    public function test_final_release_pdf_registered_for_a_different_task_cannot_be_treated_as_matching_this_task(): void
    {
        [$a,$admin]=$this->fixture();$task=LearningTask::where('apprentice_id',$a->id)->first();$wrong=TaskCatalogue::template('b1-t2');$schema=$task->template;$schema['sourcePdfFilename']=$wrong['sourcePdfFilename'];$schema['sourceTemplateHash']=$wrong['sourceTemplateHash'];$task->template=$schema;$task->save();$audit=CurriculumSources::apprentice($a);$this->assertSame('invalid',$audit['snapshot']['items'][0]['category']);$this->actingAs($admin)->post('/apprentices/'.$a->id.'/source-comparison',$this->data($a))->assertSessionHasErrors('source_review');
    }
}
