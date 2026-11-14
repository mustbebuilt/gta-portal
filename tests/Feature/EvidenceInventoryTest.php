<?php
namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ExtraTraining;
use App\Models\LearningTask;
use App\Models\ProgressReviewDraft;
use App\Models\Qualification;
use App\Models\SignedReviewRecord;
use App\Models\StaffDevelopmentProfile;
use App\Models\SupportPlan;
use App\Models\TaskEvidence;
use App\Models\TrainingTopic;
use App\Models\User;
use App\Support\EvidenceInventory;
use App\Support\PrivateEvidence;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvidenceInventoryTest extends TestCase
{
    use LazilyRefreshDatabase;
    private function setupFiles(): array
    {
        Storage::fake('local');$u=User::factory()->create();$a=Apprentice::factory()->create();$task=LearningTask::factory()->create(['apprentice_id'=>$a->id]);
        $entry=PrivateEvidence::store(UploadedFile::fake()->createWithContent('Sensitive learner name.txt','Fictional original private bytes'),'task-evidence',$u->id);
        return [$u,$a,$task,$entry];
    }
    private function taskFile($task,$user,array $entry): TaskEvidence
    {
        return TaskEvidence::create(['learning_task_id'=>$task->id,'uploaded_by'=>$user->id,...collect($entry)->only('disk','path','name','mime','sha256','bytes')->all(),'kind'=>'supporting-evidence']);
    }
    public function test_default_scans_all_registered_source_families_and_retained_copies_without_file_io_or_private_output(): void
    {
        [$u,$a,$task,$entry]=$this->setupFiles();$file=$this->taskFile($task,$u,$entry);
        $task->submissions=[['evidence'=>[['id'=>$file->id,'name'=>'Sensitive learner name.txt','sha256'=>$entry['sha256']]]]];$task->save();
        $topic=TrainingTopic::factory()->create(['apprentice_id'=>$a->id,'files'=>[$entry],'submissions'=>[['files'=>[$entry]]]]);
        Qualification::factory()->create(['apprentice_id'=>$a->id,'files'=>[$entry]]);
        $plan=SupportPlan::create(['apprentice_id'=>$a->id,'concern_ids'=>[],'draft'=>[]]);$plan->files=[$entry];$plan->history=[['before'=>['files'=>[$entry]],'private_note'=>'Secret support diagnosis']];$plan->save();
        StaffDevelopmentProfile::create(['user_id'=>$u->id,'payload'=>['entries'=>[['private_notes'=>'Secret HR discussion','files'=>[$entry],'history'=>[['before'=>['files'=>[$entry]]]]]]]]);
        $extra=ExtraTraining::factory()->create(['apprentice_id'=>$a->id]);$extra->file=$entry;$extra->history=[['before'=>['file'=>$entry]]];$extra->save();
        $review=ProgressReviewDraft::create(['apprentice_id'=>$a->id,'author_id'=>$u->id,'fields'=>[],'source_snapshot'=>[]]);
        SignedReviewRecord::create(['apprentice_id'=>$a->id,'progress_review_draft_id'=>$review->id,'review_version'=>1,'meeting_date'=>today(),'snapshot'=>['identity_reference'=>'Secret identity record'],'snapshot_hash'=>str_repeat('a',64),'file'=>$entry,'prepared_by'=>$u->id,'history'=>[]]);
        $before=$plan->fresh()->getAttributes();Storage::shouldReceive('disk')->never();
        $r=EvidenceInventory::run(details:true);$this->assertSame('references_scanned',$r['result']);$this->assertCount(8,$r['modules']);$this->assertSame(1,$r['totals']['objects']);$this->assertSame(12,$r['totals']['references']);$this->assertSame(0,$r['totals']['verified_objects']);$this->assertFalse($r['files_read']);$this->assertFalse($r['writes_performed']);
        $text=json_encode($r);foreach(['Sensitive learner name','Secret support','Secret HR','Secret identity',$entry['path'],$entry['sha256']] as $secret){$this->assertStringNotContainsString($secret,$text);}
        $this->assertSame($before,$plan->fresh()->getAttributes());$this->assertDatabaseCount('account_access_events',0);
    }
    public function test_explicit_verification_deduplicates_objects_and_preserves_every_source_and_file(): void
    {
        [$u,$a,$task,$entry]=$this->setupFiles();$file=$this->taskFile($task,$u,$entry);$q=Qualification::factory()->create(['apprentice_id'=>$a->id,'files'=>[$entry]]);$before=$q->fresh()->getAttributes();
        $r=EvidenceInventory::run(true,true);$this->assertSame('verified_referenced_objects',$r['result']);$this->assertSame(2,$r['totals']['references']);$this->assertSame(1,$r['totals']['verified_objects']);$this->assertSame($entry['bytes'],$r['totals']['declared_unique_bytes']);$this->assertFalse($r['reference_change_detected']);$this->assertFalse($r['migration_authorised']);$this->assertSame($before,$q->fresh()->getAttributes());$this->assertTrue(PrivateEvidence::matchesHash($entry));
        $this->artisan('gta:evidence-inventory',['--verify-files'=>true])->assertSuccessful();$this->assertDatabaseCount('task_evidence',1);
    }
    public function test_legacy_entries_stay_on_legacy_disk_after_new_upload_default_changes(): void
    {
        [$u,$a,$task,$entry]=$this->setupFiles();$file=$this->taskFile($task,$u,[...$entry,'disk'=>null]);
        config(['filesystems.disks.other_private'=>['driver'=>'local','root'=>storage_path('framework/testing/other-private'),'visibility'=>'private','serve'=>false],'evidence.disk'=>'other_private','evidence.private_disks'=>['local','other_private']]);Storage::fake('other_private');
        $legacy=$entry;unset($legacy['disk']);Qualification::factory()->create(['apprentice_id'=>$a->id,'files'=>[$legacy]]);
        $r=EvidenceInventory::run(true);$this->assertSame('verified_referenced_objects',$r['result']);$this->assertSame(2,$r['totals']['legacy_references']);$this->assertSame(1,$r['totals']['objects']);$this->assertSame(1,$r['totals']['verified_objects']);$this->assertNull($file->fresh()->disk);Storage::disk('other_private')->assertMissing($entry['path']);
    }
    public function test_missing_changed_or_unreadable_files_need_checks_and_are_not_repaired(): void
    {
        [$u,$a,$task,$entry]=$this->setupFiles();$this->taskFile($task,$u,$entry);
        Storage::disk('local')->put($entry['path'],'Changed bytes');$r=EvidenceInventory::run(true);$this->assertSame(1,$r['totals']['changed_objects']);$this->assertSame('needs_checks',$r['result']);
        Storage::disk('local')->delete($entry['path']);$r=EvidenceInventory::run(true);$this->assertSame(1,$r['totals']['missing_objects']);$this->assertDatabaseCount('task_evidence',1);
        $this->artisan('gta:evidence-inventory',['--verify-files'=>true])->assertExitCode(1);
    }
    public function test_conflicting_declarations_of_one_object_are_flagged_before_file_reads(): void
    {
        [$u,$a,$task,$entry]=$this->setupFiles();$this->taskFile($task,$u,$entry);Qualification::factory()->create(['apprentice_id'=>$a->id,'files'=>[[...$entry,'sha256'=>str_repeat('b',64)]]]);
        Storage::shouldReceive('disk')->never();$r=EvidenceInventory::run(true,true);$this->assertSame(1,$r['totals']['conflicting_objects']);$this->assertSame(0,$r['totals']['verified_objects']);$this->assertSame('needs_checks',$r['result']);
    }
    public function test_missing_parent_links_wrong_owner_and_historical_hash_disagreement_are_not_guessed(): void
    {
        [$u,$a,$task,$entry]=$this->setupFiles();$file=$this->taskFile($task,$u,$entry);$other=LearningTask::factory()->create(['apprentice_id'=>$a->id,'reference'=>'b1-t4']);
        $other->submissions=[['evidence'=>[['id'=>$file->id,'sha256'=>$entry['sha256']],['id'=>999999,'sha256'=>$entry['sha256']],['id'=>['invalid']]]]];$other->save();
        $task->submissions=[['evidence'=>[['id'=>$file->id,'sha256'=>str_repeat('c',64)]]]];$task->save();
        $r=EvidenceInventory::run(details:true);$this->assertSame('needs_checks',$r['result']);$codes=array_column($r['issue_details'],'status');foreach(['task_file_owner_mismatch','missing_task_file_record','invalid_task_file_link','task_file_hash_disagrees'] as $code){$this->assertContains($code,$codes);}$this->assertSame(1,$r['totals']['objects']);
    }
    public function test_bad_metadata_paths_hashes_and_private_disk_configuration_do_not_trigger_file_reads(): void
    {
        [$u,$a,$task,$entry]=$this->setupFiles();Qualification::factory()->create(['apprentice_id'=>$a->id,'files'=>[[...$entry,'path'=>'../outside.txt'],[...$entry,'sha256'=>'unknown'],[...$entry,'bytes'=>'123'],[...$entry,'disk'=>'public']]]);
        Storage::shouldReceive('disk')->never();$r=EvidenceInventory::run(true,true);$this->assertSame('needs_checks',$r['result']);$this->assertSame(4,$r['totals']['metadata_issues']);$this->assertSame(0,$r['totals']['verified_objects']);
    }
    public function test_changed_references_during_reads_are_detected_by_post_verification_scan(): void
    {
        [$u,$a,$task,$entry]=$this->setupFiles();$this->taskFile($task,$u,$entry);$q=Qualification::factory()->create(['apprentice_id'=>$a->id,'files'=>[$entry]]);
        $real=Storage::disk('local');$proxy=\Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class)->makePartial();
        $proxy->shouldReceive('fileExists')->andReturnUsing(fn($path)=>$real->fileExists($path));
        $proxy->shouldReceive('readStream')->andReturnUsing(function($path)use($q,$real){$q->version++;$q->save();return $real->readStream($path);});Storage::set('local',$proxy);
        $r=EvidenceInventory::run(true);$this->assertTrue($r['reference_change_detected']);$this->assertSame('needs_checks',$r['result']);$this->assertSame(1,$r['totals']['verified_objects']);
    }
    public function test_missing_schema_and_malformed_json_are_explicit_and_default_output_is_summary_only(): void
    {
        [$u,$a,$task,$entry]=$this->setupFiles();Schema::table('qualifications',fn(Blueprint $t)=>$t->renameColumn('files','fixture_old_files'));
        $r=EvidenceInventory::run();$this->assertSame(1,$r['totals']['schema_issues']);$this->assertSame('needs_checks',$r['result']);$this->assertArrayNotHasKey('reference_details',$r);
        Schema::table('qualifications',fn(Blueprint $t)=>$t->renameColumn('fixture_old_files','files'));
        $topic=TrainingTopic::factory()->create(['apprentice_id'=>$a->id]);
        // A valid JSON scalar is still not an upload metadata collection on either engine.
        DB::table('training_topics')->where('id',$topic->id)->update(['files'=>'123']);$r=EvidenceInventory::run(details:true);$this->assertContains('json_not_array_or_object',array_column($r['issue_details'],'status'));
    }
    public function test_storage_profile_drift_is_detected_even_if_database_references_stay_the_same(): void
    {
        [$u,$a,$task,$entry]=$this->setupFiles();$this->taskFile($task,$u,$entry);$real=Storage::disk('local');
        $disk=\Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class)->makePartial();$disk->shouldReceive('fileExists')->andReturn(true);$disk->shouldReceive('readStream')->andReturnUsing(function($path)use($real){config(['filesystems.disks.local.root'=>storage_path('framework/testing/other-legacy-root')]);return $real->readStream($path);});Storage::set('local',$disk);
        $r=EvidenceInventory::run(true);$this->assertTrue($r['reference_change_detected']);$this->assertSame('needs_checks',$r['result']);$this->assertSame(1,$r['totals']['verified_objects']);
    }
    public function test_unreadable_storage_errors_are_redacted_and_never_become_verified(): void
    {
        [$u,$a,$task,$entry]=$this->setupFiles();$this->taskFile($task,$u,$entry);
        $disk=\Mockery::mock(\Illuminate\Filesystem\FilesystemAdapter::class)->makePartial();$disk->shouldReceive('fileExists')->andReturn(true);$disk->shouldReceive('readStream')->andThrow(new \RuntimeException('Secret endpoint credential and document name'));Storage::set('local',$disk);
        $r=EvidenceInventory::run(true,true);$this->assertSame('needs_checks',$r['result']);$this->assertSame(1,$r['totals']['unreadable_objects']);$this->assertSame(0,$r['totals']['verified_objects']);$this->assertStringNotContainsString('Secret endpoint',json_encode($r));
    }
    public function test_empty_but_complete_schema_reports_no_registered_uploads_without_backup_claim(): void
    {
        $r=EvidenceInventory::run(true);$this->assertSame('verified_referenced_objects',$r['result']);$this->assertSame(0,$r['totals']['references']);$this->assertStringContainsString('unregistered-file',$r['coverage']);$this->assertFalse($r['writes_performed']);$this->artisan('gta:evidence-inventory')->assertSuccessful();
    }
}
