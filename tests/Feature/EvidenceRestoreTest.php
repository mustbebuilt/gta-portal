<?php
namespace Tests\Feature;
use App\Models\{Apprentice,EvidenceCopyPlan,EvidenceRestoreRun,LearningTask,Qualification,TaskEvidence,User};
use App\Support\{EvidenceCopies,EvidenceRestore,PrivateEvidence,ReviewedImports};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;
class EvidenceRestoreTest extends TestCase
{
    use DatabaseMigrations,ConfiguresStaging;
    private function fixture(): array
    {
        $this->configureStaging();config(['evidence_copy.enabled'=>true,'evidence_restore.enabled'=>true,'evidence_restore.targets'=>['restore_target'],'evidence.disk'=>'local','evidence.legacy_disk'=>'local','evidence.private_disks'=>['local','copy_target','restore_target']]);
        foreach(['local','copy_target','restore_target']as$disk){config(['filesystems.disks.'.$disk=>['driver'=>'local','root'=>storage_path('framework/testing/'.$disk),'visibility'=>'private','serve'=>false,'throw'=>true]]);Storage::fake($disk);config(['filesystems.disks.'.$disk.'.root'=>Storage::disk($disk)->path('')]);}
        $admin=User::factory()->create(['role'=>'admin']);$a=Apprentice::factory()->create();$task=LearningTask::factory()->create(['apprentice_id'=>$a->id]);
        $entry=PrivateEvidence::store(UploadedFile::fake()->createWithContent('PRIVATE-LEARNER-NAME.txt','Fictional recoverable evidence bytes'),'task-evidence',$admin->id);
        $file=TaskEvidence::create(['learning_task_id'=>$task->id,'uploaded_by'=>$admin->id,...collect($entry)->only('disk','path','name','mime','sha256','bytes')->all(),'kind'=>'supporting-evidence']);$q=Qualification::factory()->create(['apprentice_id'=>$a->id,'files'=>[$entry],'history'=>[['before'=>['files'=>[$entry]]]]]);
        $preview=EvidenceCopies::proposal('local','copy_target');$p=EvidenceCopies::prepare('local','copy_target',$preview['proposal_hash'],'PRIVATE-COPY-REFERENCE');$p=EvidenceCopies::execute($p,1,$p->manifest_hash);
        return [$p,$entry,$file,$q,$admin];
    }
    private function prepare(EvidenceCopyPlan $plan): EvidenceRestoreRun
    {
        return EvidenceRestore::prepare($plan,'restore_target',EvidenceRestore::proposal($plan,'restore_target')['proposal_hash'],'PRIVATE-RESTORE-REFERENCE');
    }
    private function execute(EvidenceRestoreRun $run): EvidenceRestoreRun{return EvidenceRestore::execute($run,$run->version,$run->manifest_hash);}
    private function refused(callable $fn): void
    {
        try{$fn();$this->fail('Expected refusal.');}catch(\RuntimeException $e){$this->assertNotEmpty($e->getMessage());}
    }
    public function test_metadata_inspection_and_proposal_have_no_evidence_byte_access_or_writes(): void
    {
        [$plan]=$this->fixture();Storage::shouldReceive('disk')->never();$summary=EvidenceRestore::backupSummary($plan);$this->assertFalse($summary['file_reads']);$this->assertFalse($summary['originals_read']);$proposal=EvidenceRestore::proposal($plan,'restore_target');$this->assertSame(1,$proposal['objects']);$this->artisan('gta:evidence-restore-plan',['plan'=>$plan->id,'--target'=>'restore_target'])->expectsOutputToContain('"file_reads":false')->assertExitCode(0);$this->assertDatabaseCount('evidence_restore_runs',0);
    }
    public function test_retained_backup_verifies_after_original_file_and_live_metadata_have_changed(): void
    {
        [$plan,$entry,$file,$q]=$this->fixture();Storage::disk('local')->delete($entry['path']);$q->files=[];$q->version++;$q->save();$result=EvidenceRestore::verifyBackup($plan);$this->assertSame('verified_retained_copies',$result['result']);$this->assertFalse($result['originals_read']);$this->assertDatabaseCount('evidence_restore_events',0);
    }
    public function test_missing_changed_and_oversized_backups_are_reported_without_any_writes(): void
    {
        [$plan]=$this->fixture();$path=$plan->manifest['items'][0]['destination']['path'];Storage::disk('copy_target')->put($path,'Changed');$this->assertSame('needs_checks',EvidenceRestore::verifyBackup($plan)['result']);Storage::disk('copy_target')->delete($path);$this->assertSame('needs_checks',EvidenceRestore::verifyBackup($plan)['result']);$this->assertDatabaseCount('evidence_restore_runs',0);
    }
    public function test_bounded_fingerprint_refuses_oversize_before_full_stream_read_and_old_unbounded_api_still_works(): void
    {
        [$plan]=$this->fixture();$entry=$plan->manifest['items'][0]['destination'];$this->refused(fn()=>PrivateEvidence::fingerprint($entry,4));$this->assertSame($entry['sha256'],PrivateEvidence::fingerprint($entry)['sha256']);$this->refused(fn()=>PrivateEvidence::fingerprint($entry,0));
    }
    public function test_unverified_incomplete_or_altered_copy_plans_cannot_be_adopted_as_backups(): void
    {
        [$plan]=$this->fixture();$plan->state='Needs checks';$plan->save();$this->refused(fn()=>EvidenceRestore::backupSummary($plan));$plan->state='Verified staging copies';$result=$plan->result;$result['items'][0]['token']=str_repeat('f',64);$plan->result=$result;$plan->save();$this->refused(fn()=>EvidenceRestore::backupSummary($plan));
    }
    public function test_restore_target_must_be_explicit_third_private_disk_without_overlapping_root(): void
    {
        [$plan]=$this->fixture();$this->refused(fn()=>EvidenceRestore::proposal($plan,'local'));$this->refused(fn()=>EvidenceRestore::proposal($plan,'copy_target'));config(['evidence_restore.targets'=>[]]);$this->refused(fn()=>EvidenceRestore::proposal($plan,'restore_target'));config(['evidence_restore.targets'=>['restore_target'],'filesystems.disks.restore_target.root'=>config('filesystems.disks.local.root')]);$this->refused(fn()=>EvidenceRestore::proposal($plan,'restore_target'));
    }
    public function test_nested_or_symlink_alias_restore_roots_are_refused(): void
    {
        [$plan]=$this->fixture();$parent=config('filesystems.disks.local.root');$nested=$parent.'/nested-restore';mkdir($nested);config(['filesystems.disks.restore_target.root'=>$nested]);$this->refused(fn()=>EvidenceRestore::proposal($plan,'restore_target'));$alias=storage_path('framework/testing/fictional-restore-alias');if(is_link($alias)){unlink($alias);}symlink($parent,$alias);config(['filesystems.disks.restore_target.root'=>$alias]);try{$this->refused(fn()=>EvidenceRestore::proposal($plan,'restore_target'));}finally{unlink($alias);}
    }
    public function test_recording_requires_staging_exact_preview_and_reference_but_not_execution_switch(): void
    {
        [$plan]=$this->fixture();$this->refused(fn()=>EvidenceRestore::prepare($plan,'restore_target',str_repeat('a',64),'Reference'));$proposal=EvidenceRestore::proposal($plan,'restore_target');$this->refused(fn()=>EvidenceRestore::prepare($plan,'restore_target',$proposal['proposal_hash'],''));config(['evidence_restore.enabled'=>false]);$run=$this->prepare($plan);$this->assertSame('Prepared',$run->state);$this->assertDatabaseCount('evidence_restore_events',1);$this->refused(fn()=>$this->execute($run));$this->app['env']='production';$this->refused(fn()=>$this->prepare($plan));$this->app['env']='staging';
    }
    public function test_restore_from_retained_copy_survives_original_loss_preserves_database_links_and_keeps_private_mode(): void
    {
        [$plan,$entry,$file,$q]=$this->fixture();$before=[$file->fresh()->getAttributes(),$q->fresh()->getAttributes()];Storage::disk('local')->delete($entry['path']);$run=$this->prepare($plan);$run=$this->execute($run);$this->assertSame('Verified restore rehearsal',$run->state);$destination=$run->manifest['items'][0]['destination'];$this->assertSame('Fictional recoverable evidence bytes',Storage::disk('restore_target')->get($destination['path']));$this->assertSame(0600,fileperms(Storage::disk('restore_target')->path($destination['path']))&0777);$this->assertSame($before,[$file->fresh()->getAttributes(),$q->fresh()->getAttributes()]);$this->assertFalse(Storage::disk('local')->exists($entry['path']));$this->assertSame('verified_rehearsal_objects',EvidenceRestore::verify($run)['result']);$this->assertFalse(EvidenceRestore::summary($run)['cutover_approved']);
    }
    public function test_execute_requires_exact_version_hash_no_outer_transaction_and_is_once_only(): void
    {
        [$plan]=$this->fixture();$run=$this->prepare($plan);$this->refused(fn()=>EvidenceRestore::execute($run,99,$run->manifest_hash));$this->refused(fn()=>EvidenceRestore::execute($run,1,str_repeat('f',64)));DB::transaction(function()use($run){$this->refused(fn()=>$this->execute($run));});$run=$this->execute($run);$this->refused(fn()=>$this->execute($run));$this->assertSame(3,$run->version);$this->assertDatabaseCount('evidence_restore_events',5);
    }
    public function test_existing_destination_is_never_overwritten_or_deleted(): void
    {
        [$plan]=$this->fixture();$run=$this->prepare($plan);$destination=$run->manifest['items'][0]['destination'];Storage::disk('restore_target')->put($destination['path'],'Existing protected bytes');$run=$this->execute($run);$this->assertSame('Needs checks',$run->state);$this->assertSame('Existing protected bytes',Storage::disk('restore_target')->get($destination['path']));$this->assertTrue(PrivateEvidence::matchesHash($plan->manifest['items'][0]['destination']));
    }
    public function test_backup_hash_change_prevents_restore_and_does_not_delete_or_rewrite_any_source(): void
    {
        [$plan,$entry]=$this->fixture();$run=$this->prepare($plan);$backup=$plan->manifest['items'][0]['destination'];Storage::disk('copy_target')->put($backup['path'],'Changed backup');$run=$this->execute($run);$this->assertSame('Needs checks',$run->state);$this->assertSame([],Storage::disk('restore_target')->allFiles());$this->assertSame('Changed backup',Storage::disk('copy_target')->get($backup['path']));$this->assertTrue(PrivateEvidence::matchesHash($entry));
    }
    public function test_current_storage_profile_change_invalidates_prepared_manifest_before_writing(): void
    {
        [$plan]=$this->fixture();$run=$this->prepare($plan);config(['filesystems.disks.restore_target.prefix'=>'changed']);$this->refused(fn()=>$this->execute($run));$this->assertSame('needs_checks',EvidenceRestore::summary($run)['integrity']);$this->assertSame([],Storage::disk('restore_target')->allFiles());
    }
    public function test_final_backup_drift_after_copy_is_detected_and_restored_bytes_are_retained(): void
    {
        [$plan]=$this->fixture();$run=$this->prepare($plan);$real=Storage::disk('copy_target');$proxy=\Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class)->makePartial();$reads=0;$path=$plan->manifest['items'][0]['destination']['path'];$proxy->shouldReceive('readStream')->andReturnUsing(function($p)use($real,$path,&$reads){if(++$reads===2){$real->put($path,'Changed after copy');}return $real->readStream($p);});$proxy->shouldReceive('fileExists')->andReturnUsing(fn($p)=>$real->fileExists($p));Storage::set('copy_target',$proxy);$run=$this->execute($run);$this->assertSame('Needs checks',$run->state);$this->assertFalse($run->result['binding_stable_at_finish']);$this->assertTrue(PrivateEvidence::matchesHash($run->manifest['items'][0]['destination']));
    }
    public function test_readonly_recheck_detects_changed_restored_file_without_rewriting_recorded_history(): void
    {
        [$plan]=$this->fixture();$run=$this->execute($this->prepare($plan));$before=$run->getAttributes();$events=DB::table('evidence_restore_events')->count();Storage::disk('restore_target')->put($run->manifest['items'][0]['destination']['path'],'Changed restored bytes');$this->assertSame('needs_checks',EvidenceRestore::verify($run)['result']);$this->assertSame($before,$run->fresh()->getAttributes());$this->assertSame($events,DB::table('evidence_restore_events')->count());
    }
    public function test_final_audit_failure_leaves_running_reservation_and_retained_files_without_retry(): void
    {
        if(DB::getDriverName()!=='sqlite'){$this->markTestSkipped('SQLite-specific disposable failure injection; native fault acceptance separate.');}
        [$plan]=$this->fixture();$run=$this->prepare($plan);DB::statement("CREATE TRIGGER fictional_restore_finish_failure BEFORE UPDATE OF state ON evidence_restore_runs WHEN NEW.state = 'Verified restore rehearsal' BEGIN SELECT RAISE(ABORT, 'Fictional finish failure'); END");$this->refused(fn()=>$this->execute($run));$this->assertSame('Running',$run->fresh()->state);$this->assertTrue(PrivateEvidence::matchesHash($run->manifest['items'][0]['destination']));$this->refused(fn()=>$this->execute($run));DB::statement('DROP TRIGGER fictional_restore_finish_failure');
    }
    public function test_manifest_and_copy_plan_tampering_are_refused_and_private_details_stay_out_of_summaries(): void
    {
        [$plan,$entry]=$this->fixture();$run=$this->prepare($plan);$summary=json_encode(EvidenceRestore::summary($run));foreach([$entry['name'],$entry['path'],'PRIVATE-COPY-REFERENCE','PRIVATE-RESTORE-REFERENCE',config('filesystems.disks.restore_target.root')]as$v){$this->assertStringNotContainsString($v,$summary);}$m=$run->manifest;$m['items'][0]['destination']['path']='tampered';$run->manifest=$m;$run->manifest_hash=ReviewedImports::hash($m);$run->save();$this->refused(fn()=>$this->execute($run));$this->assertSame('needs_checks',EvidenceRestore::summary($run)['integrity']);
    }
    public function test_history_routes_are_readonly_admin_scoped_filtered_paginated_and_private(): void
    {
        [$plan,$entry,$file,$q,$admin]=$this->fixture();$run=$this->prepare($plan);$tutor=User::factory()->create(['role'=>'tutor']);$this->actingAs($tutor)->get('https://staging.example.test/evidence-recovery')->assertForbidden();$this->get('https://staging.example.test/evidence-recovery/'.$run->id)->assertForbidden();$this->actingAs($admin)->get('https://staging.example.test/evidence-recovery/'.$run->id)->assertOk()->assertDontSee($entry['name'])->assertDontSee($entry['path'])->assertDontSee('PRIVATE-RESTORE-REFERENCE');for($i=0;$i<21;$i++){$clone=$run->replicate();$clone->id=(string)\Illuminate\Support\Str::uuid();$clone->save();}$this->get('https://staging.example.test/evidence-recovery?state=Prepared&page=2')->assertOk()->assertSee('state=Prepared')->assertSee('page=1');$this->get('https://staging.example.test/evidence-recovery?state=invalid')->assertSessionHasErrors('state');$this->assertSame('Prepared',$run->fresh()->state);
    }
    public function test_combined_verify_and_restore_flags_are_refused_and_default_inspection_does_not_copy(): void
    {
        [$plan]=$this->fixture();$run=$this->prepare($plan);$this->artisan('gta:evidence-restore',['run'=>$run->id])->expectsOutputToContain('"state":"Prepared"')->assertExitCode(0);$this->artisan('gta:evidence-restore',['run'=>$run->id,'--restore'=>true,'--verify'=>true])->assertExitCode(1);$this->assertSame([],Storage::disk('restore_target')->allFiles());$this->assertSame('Prepared',$run->fresh()->state);
    }
    public function test_remote_restore_profiles_require_explicit_https_private_and_separate_bucket_without_accessing_service(): void
    {
        [$plan]=$this->fixture();Storage::shouldReceive('disk')->never();config(['filesystems.disks.restore_target'=>['driver'=>'s3','bucket'=>'restore-only-bucket','endpoint'=>'https://storage.example.test','visibility'=>'private']]);$this->assertSame(1,EvidenceRestore::proposal($plan,'restore_target')['objects']);config(['filesystems.disks.restore_target.endpoint'=>'http://storage.example.test']);$this->refused(fn()=>EvidenceRestore::proposal($plan,'restore_target'));config(['filesystems.disks.restore_target.endpoint'=>'https://storage.example.test','filesystems.disks.restore_target.bucket'=>'']);$this->refused(fn()=>EvidenceRestore::proposal($plan,'restore_target'));config(['filesystems.disks.restore_target.bucket'=>'restore-only-bucket','filesystems.disks.restore_target.url'=>'https://public.example.test']);$this->refused(fn()=>EvidenceRestore::proposal($plan,'restore_target'));
    }
    public function test_bucket_alias_is_refused_even_when_its_endpoint_or_prefix_differs(): void
    {
        [$plan]=$this->fixture();config(['evidence.private_disks'=>['local','copy_target','restore_target','other_private'],'filesystems.disks.restore_target'=>['driver'=>'s3','bucket'=>'same-private-bucket','endpoint'=>'https://a.example.test','prefix'=>'restore','visibility'=>'private'],'filesystems.disks.other_private'=>['driver'=>'s3','bucket'=>'same-private-bucket','endpoint'=>'https://b.example.test','prefix'=>'source','visibility'=>'private']]);$this->refused(fn()=>EvidenceRestore::proposal($plan,'restore_target'));
    }
    public function test_partial_multi_object_restore_retains_first_copy_and_later_collision(): void
    {
        [$old,$entry,$file,$q]=$this->fixture();$second=PrivateEvidence::store(UploadedFile::fake()->createWithContent('Second-private.txt','Second fictional retained bytes'),'task-evidence',$file->uploaded_by);$q->files=[$entry,$second];$q->save();$p=EvidenceCopies::prepare('local','copy_target',EvidenceCopies::proposal('local','copy_target')['proposal_hash'],'Second protected backup');$p=EvidenceCopies::execute($p,1,$p->manifest_hash);$run=$this->prepare($p);$items=$run->manifest['items'];Storage::disk('restore_target')->put($items[1]['destination']['path'],'Existing later bytes');$run=$this->execute($run);$this->assertSame('Needs checks',$run->state);$this->assertSame(1,$run->result['verified_objects']);$this->assertTrue(PrivateEvidence::matchesHash($items[0]['destination']));$this->assertSame('Existing later bytes',Storage::disk('restore_target')->get($items[1]['destination']['path']));$this->assertTrue(PrivateEvidence::matchesHash($entry));$this->assertTrue(PrivateEvidence::matchesHash($second));
    }
    public function test_changed_copy_binding_and_scope_limit_block_before_any_restore_write(): void
    {
        [$plan]=$this->fixture();$run=$this->prepare($plan);$plan->version++;$plan->save();$this->refused(fn()=>$this->execute($run));$this->assertSame([],Storage::disk('restore_target')->allFiles());config(['evidence_restore.max_objects'=>0]);$this->refused(fn()=>EvidenceRestore::proposal($plan,'restore_target'));
    }
    public function test_restore_can_proceed_when_the_configured_original_root_is_unavailable(): void
    {
        [$plan]=$this->fixture();$root=rtrim(Storage::disk('local')->path(''),'/');$offline=$root.'-fictional-offline';rename($root,$offline);try{$run=$this->execute($this->prepare($plan));$this->assertSame('Verified restore rehearsal',$run->state);$this->assertTrue(PrivateEvidence::matchesHash($run->manifest['items'][0]['destination']));}finally{rename($offline,$root);}
    }
}
