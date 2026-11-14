<?php
namespace Tests\Feature;

use App\Models\{Apprentice,EvidenceCopyPlan,LearningTask,Qualification,TaskEvidence,User};
use App\Support\{EvidenceCopies,PrivateEvidence,ReviewedImports};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;

class EvidenceCopiesTest extends TestCase
{
    use LazilyRefreshDatabase,ConfiguresStaging;
    private function fixture(): array
    {
        $this->configureStaging();config(['evidence_copy.enabled'=>true,'evidence.disk'=>'local','evidence.legacy_disk'=>'local','evidence.private_disks'=>['local','copy_target'],'filesystems.disks.copy_target'=>['driver'=>'local','root'=>storage_path('framework/testing/copy-target'),'visibility'=>'private','serve'=>false,'throw'=>true]]);
        Storage::fake('local');Storage::fake('copy_target');
        $user=User::factory()->create();$apprentice=Apprentice::factory()->create();$task=LearningTask::factory()->create(['apprentice_id'=>$apprentice->id]);
        $entry=PrivateEvidence::store(UploadedFile::fake()->createWithContent('Private learner.txt','Fictional original evidence bytes'),'task-evidence',$user->id);
        $file=TaskEvidence::create(['learning_task_id'=>$task->id,'uploaded_by'=>$user->id,...collect($entry)->only('disk','path','name','mime','sha256','bytes')->all(),'kind'=>'supporting-evidence']);
        $qualification=Qualification::factory()->create(['apprentice_id'=>$apprentice->id,'files'=>[$entry],'history'=>[['before'=>['files'=>[$entry]]]]]);
        return [$entry,$file,$qualification];
    }
    private function plan(): EvidenceCopyPlan
    {
        return EvidenceCopies::prepare('local','copy_target',EvidenceCopies::proposal('local','copy_target')['proposal_hash'],'Controlled fictional rehearsal');
    }
    private function executePlan(EvidenceCopyPlan $plan): EvidenceCopyPlan
    {
        return EvidenceCopies::execute($plan,$plan->version,$plan->manifest_hash);
    }
    private function refused(callable $fn): void
    {
        try{$fn();$this->fail('Expected a refused operation.');}catch(\RuntimeException $e){$this->assertNotEmpty($e->getMessage());}
    }
    public function test_preview_has_no_uploaded_file_io_or_database_writes_and_redacts_private_locators(): void
    {
        [$entry]=$this->fixture();Storage::shouldReceive('disk')->never();
        $this->artisan('gta:evidence-copy-plan',['--source'=>'local','--target'=>'copy_target'])->expectsOutputToContain('"file_reads":false')->assertSuccessful();
        $p=EvidenceCopies::proposal('local','copy_target');$this->assertSame(1,$p['objects']);$this->assertSame($entry['bytes'],$p['bytes']);
        $this->assertDatabaseCount('evidence_copy_plans',0);$this->assertDatabaseCount('evidence_copy_events',0);
    }
    public function test_record_requires_exact_preview_and_staging_and_audit_is_atomic(): void
    {
        $this->fixture();$p=EvidenceCopies::proposal('local','copy_target');
        $this->refused(fn()=>EvidenceCopies::prepare('local','copy_target',str_repeat('a',64),'Ref'));
        $this->app['env']='production';$this->refused(fn()=>EvidenceCopies::prepare('local','copy_target',$p['proposal_hash'],'Ref'));$this->app['env']='staging';
        $this->refused(fn()=>EvidenceCopies::prepare('local','copy_target',$p['proposal_hash'],''));
        DB::listen(function($q){if(str_contains($q->sql,'insert into') && str_contains($q->sql,'evidence_copy_events')){throw new \RuntimeException('Fixture audit unavailable');}});
        $this->refused(fn()=>$this->plan());$this->assertDatabaseCount('evidence_copy_plans',0);$this->assertDatabaseCount('evidence_copy_events',0);
    }
    public function test_success_deduplicates_retained_refs_verifies_private_copies_and_preserves_originals_links_and_default(): void
    {
        [$entry,$file,$q]=$this->fixture();$before=[$file->fresh()->getAttributes(),$q->fresh()->getAttributes()];$p=$this->plan();
        $this->assertCount(1,$p->manifest['items']);$this->assertSame('intact',EvidenceCopies::summary($p)['integrity']);
        $r=$this->executePlan($p);$this->assertSame('Verified staging copies',$r->state);$this->assertSame(3,$r->version);$this->assertSame(1,$r->result['verified_copies']);$this->assertTrue($r->result['source_stable_at_finish']);
        $dest=$r->manifest['items'][0]['destination'];$this->assertSame(PrivateEvidence::fingerprint($entry),PrivateEvidence::fingerprint($dest));
        $this->assertSame(0600,fileperms(Storage::disk('copy_target')->path($dest['path']))&0777);
        $this->assertSame($before,[$file->fresh()->getAttributes(),$q->fresh()->getAttributes()]);$this->assertSame('local',config('evidence.disk'));$this->assertDatabaseCount('evidence_copy_events',5);
        $this->refused(fn()=>$this->executePlan($p));$this->assertDatabaseCount('evidence_copy_events',5);
        $text=json_encode(EvidenceCopies::summary($r));foreach([$entry['path'],$entry['name'],$dest['path'],'Controlled fictional'] as $private){$this->assertStringNotContainsString($private,$text);}
    }
    public function test_configuration_and_reference_drift_or_stale_version_hash_refuse_before_claim_and_io(): void
    {
        [$entry,$file,$q]=$this->fixture();$p=$this->plan();
        $this->refused(fn()=>EvidenceCopies::execute($p,7,$p->manifest_hash));$this->refused(fn()=>EvidenceCopies::execute($p,1,str_repeat('f',64)));
        config(['filesystems.disks.copy_target.root'=>storage_path('framework/testing/changed')]);$this->refused(fn()=>$this->executePlan($p));config(['filesystems.disks.copy_target.root'=>storage_path('framework/testing/copy-target')]);
        $q->version++;$q->save();$this->refused(fn()=>$this->executePlan($p));$this->assertSame('Planned',$p->fresh()->state);Storage::disk('copy_target')->assertDirectoryEmpty('/');
    }
    public function test_disabled_and_production_execution_refuse_before_file_access(): void
    {
        $this->fixture();$p=$this->plan();Storage::shouldReceive('disk')->never();config(['evidence_copy.enabled'=>false]);$this->refused(fn()=>$this->executePlan($p));config(['evidence_copy.enabled'=>true]);$this->app['env']='production';$this->refused(fn()=>$this->executePlan($p));
        $this->assertSame('Planned',$p->fresh()->state);
    }
    public function test_bad_or_conflicting_metadata_empty_sources_and_size_limits_are_not_planned(): void
    {
        [$entry,$file,$q]=$this->fixture();config(['evidence_copy.max_object_bytes'=>1]);$this->refused(fn()=>$this->plan());config(['evidence_copy.max_object_bytes'=>20*1024*1024]);
        $this->refused(fn()=>EvidenceCopies::proposal('local','local'));$this->refused(fn()=>EvidenceCopies::proposal('local','public'));$this->refused(fn()=>EvidenceCopies::proposal('copy_target','local'));
        $q->files=[[...$entry,'sha256'=>str_repeat('a',64)]];$q->save();$this->refused(fn()=>$this->plan());$this->assertDatabaseCount('evidence_copy_plans',0);
    }
    public function test_changed_source_fails_before_destination_write_and_is_not_retried(): void
    {
        [$entry]=$this->fixture();$p=$this->plan();Storage::disk('local')->put($entry['path'],'Changed source');$r=$this->executePlan($p);
        $this->assertSame('Needs checks',$r->state);$this->assertSame(0,$r->result['verified_copies']);$this->assertFalse($r->result['source_stable_at_finish']);Storage::disk('copy_target')->assertMissing($p->manifest['items'][0]['destination']['path']);$this->refused(fn()=>$this->executePlan($r));
        $this->assertSame('Changed source',Storage::disk('local')->get($entry['path']));
    }
    public function test_existing_destination_is_never_overwritten_or_adopted_even_when_bytes_match(): void
    {
        [$entry]=$this->fixture();$p=$this->plan();$dest=$p->manifest['items'][0]['destination'];Storage::disk('copy_target')->put($dest['path'],Storage::disk('local')->get($entry['path']));
        $r=$this->executePlan($p);$this->assertSame('Needs checks',$r->state);$this->assertSame(0,$r->result['verified_copies']);$this->assertTrue(PrivateEvidence::matchesHash($dest));$this->assertTrue(PrivateEvidence::matchesHash($entry));
    }
    public function test_symlink_destination_parent_cannot_escape_private_root(): void
    {
        [$entry]=$this->fixture();$p=$this->plan();$root=Storage::disk('copy_target')->path('');$outside=Storage::disk('local')->path('outside');mkdir($outside,0700,true);symlink($outside,$root.'/gta-staged-copies');
        try{$r=$this->executePlan($p);$this->assertSame('Needs checks',$r->state);$this->assertSame([],glob($outside.'/*'));$this->assertTrue(PrivateEvidence::matchesHash($entry));}finally{unlink($root.'/gta-staged-copies');}
    }
    public function test_start_audit_failure_prevents_external_writes_and_rolls_back_claim(): void
    {
        $this->fixture();$p=$this->plan();DB::listen(function($q){if(str_contains($q->sql,'insert into') && str_contains($q->sql,'evidence_copy_events')){throw new \RuntimeException('Fixture start audit unavailable');}});
        $this->refused(fn()=>$this->executePlan($p));$this->assertSame('Planned',$p->fresh()->state);$this->assertSame(1,$p->fresh()->version);Storage::disk('copy_target')->assertDirectoryEmpty('/');$this->assertDatabaseCount('evidence_copy_events',1);
    }
    public function test_finish_audit_failure_leaves_running_manifest_and_retains_copied_bytes(): void
    {
        [$entry]=$this->fixture();$p=$this->plan();DB::listen(function($q){if(str_contains($q->sql,'insert into') && str_contains($q->sql,'evidence_copy_events') && in_array('copy_finished',$q->bindings,true)){throw new \RuntimeException('Fixture finish audit unavailable');}});
        $this->refused(fn()=>$this->executePlan($p));$this->assertSame('Running',$p->fresh()->state);$this->assertSame(2,$p->fresh()->version);$this->assertTrue(PrivateEvidence::matchesHash($p->manifest['items'][0]['destination']));$this->assertTrue(PrivateEvidence::matchesHash($entry));$this->refused(fn()=>$this->executePlan($p->fresh()));
    }
    public function test_destination_readback_failure_retains_written_copy_and_reports_checks(): void
    {
        [$entry]=$this->fixture();$p=$this->plan();$real=Storage::disk('copy_target');$proxy=\Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class)->makePartial();
        foreach(['path','makeDirectory'] as $method){$proxy->shouldReceive($method)->andReturnUsing(fn(...$args)=>$real->$method(...$args));}$proxy->shouldReceive('fileExists')->andReturn(false);Storage::set('copy_target',$proxy);
        $r=$this->executePlan($p);$this->assertSame('Needs checks',$r->state);$this->assertSame(0,$r->result['verified_copies']);$this->assertSame(Storage::disk('local')->get($entry['path']),$real->get($p->manifest['items'][0]['destination']['path']));
    }
    public function test_final_source_and_reference_drift_is_detected_without_reverting_copies(): void
    {
        [$entry,$file,$q]=$this->fixture();$p=$this->plan();$real=Storage::disk('local');$proxy=\Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class)->makePartial();$reads=0;
        $proxy->shouldReceive('readStream')->andReturnUsing(function($path)use($real,$entry,$q,&$reads){$reads++;if($reads===2){$q->version++;$q->save();$real->put($entry['path'],'Changed after copy');}return $real->readStream($path);});$proxy->shouldReceive('fileExists')->andReturnUsing(fn($path)=>$real->fileExists($path));Storage::set('local',$proxy);
        $r=$this->executePlan($p);$this->assertSame('Needs checks',$r->state);$this->assertFalse($r->result['source_stable_at_finish']);$this->assertTrue(PrivateEvidence::matchesHash($p->manifest['items'][0]['destination']));$this->assertSame('Changed after copy',$real->get($entry['path']));
    }
    public function test_partial_run_preserves_first_copy_and_does_not_adopt_the_later_collision(): void
    {
        [$entry,$file,$q]=$this->fixture();$other=PrivateEvidence::store(UploadedFile::fake()->createWithContent('Other.txt','Second fictional evidence'),'task-evidence',$file->uploaded_by);$q->files=[$entry,$other];$q->save();$p=$this->plan();$items=$p->manifest['items'];$collision=$items[1]['destination'];Storage::disk('copy_target')->put($collision['path'],'Existing target');
        $r=$this->executePlan($p);$this->assertSame('Needs checks',$r->state);$this->assertSame(1,$r->result['verified_copies']);$this->assertTrue(PrivateEvidence::matchesHash($items[0]['destination']));$this->assertSame('Existing target',Storage::disk('copy_target')->get($collision['path']));$this->assertTrue(PrivateEvidence::matchesHash($entry));$this->assertTrue(PrivateEvidence::matchesHash($other));
    }
    public function test_final_pass_detects_destination_changed_after_its_immediate_readback(): void
    {
        $this->fixture();$p=$this->plan();$real=Storage::disk('copy_target');$proxy=\Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class)->makePartial();$reads=0;
        foreach(['path','makeDirectory','fileExists'] as $method){$proxy->shouldReceive($method)->andReturnUsing(fn(...$args)=>$real->$method(...$args));}
        $proxy->shouldReceive('readStream')->andReturnUsing(function($path)use($real,&$reads){$reads++;if($reads===2){$real->put($path,'Changed destination after verification');}return $real->readStream($path);});Storage::set('copy_target',$proxy);
        $r=$this->executePlan($p);$this->assertSame('Needs checks',$r->state);$this->assertFalse($r->result['source_stable_at_finish']);$this->assertSame('Changed destination after verification',$real->get($p->manifest['items'][0]['destination']['path']));
    }
    public function test_rehashed_manifest_cannot_redirect_destination_or_change_pinned_source(): void
    {
        $this->fixture();$p=$this->plan();$m=$p->manifest;$m['items'][0]['destination']['path']='outside-namespace.bin';$p->manifest=$m;$p->manifest_hash=ReviewedImports::hash($m);$p->save();Storage::shouldReceive('disk')->never();
        $this->assertSame('needs_checks',EvidenceCopies::summary($p)['integrity']);$this->refused(fn()=>$this->executePlan($p));$this->assertSame('Planned',$p->fresh()->state);
    }
    public function test_inspection_is_read_only_and_cli_does_not_expose_manifest_or_result_extras(): void
    {
        [$entry]=$this->fixture();$p=$this->plan();$p->result=['secret'=>'Sensitive private note','items'=>[]];$p->save();Storage::shouldReceive('disk')->never();
        $this->artisan('gta:evidence-copy',['plan'=>$p->id])->expectsOutputToContain('"application_links_changed":false')->assertSuccessful();$this->assertStringNotContainsString('Sensitive',json_encode(EvidenceCopies::summary($p)));$this->assertSame('Planned',$p->fresh()->state);$this->assertDatabaseCount('evidence_copy_events',1);
    }
}
