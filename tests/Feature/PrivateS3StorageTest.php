<?php
namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Models\User;
use App\Support\EvidenceStorage;
use App\Support\PrivateEvidence;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivateS3StorageTest extends TestCase
{
    use LazilyRefreshDatabase;
    private array $objects=[];
    private array $requests=[];
    private string $fault='';

    private function configure(string $disk='private_cloud',string $prefix=''): void
    {
        $handler=function(CommandInterface $command,$request){
            $this->requests[]=['operation'=>$command->getName(),'acl'=>$command['ACL']??null,'headers'=>$request->getHeaders(),'key'=>$command['Key']??null];
            $key=($command['Bucket']??'').'/'.($command['Key']??'');
            if($command->getName()==='PutObject'){
                if($this->fault==='write'){return Create::rejectionFor(new S3Exception('FictionalSecret must never appear in output',$command,['code'=>'AccessDenied','response'=>new Response(403)]));}
                $this->objects[$key]=(string)Utils::streamFor($command['Body']);return Create::promiseFor(new Result(['ETag'=>'"fictional"']));
            }
            if($command->getName()==='DeleteObject'){
                if($this->fault==='delete'){return Create::rejectionFor(new S3Exception('FictionalSecret delete error',$command,['code'=>'AccessDenied','response'=>new Response(403)]));}
                unset($this->objects[$key]);return Create::promiseFor(new Result);
            }
            if(!isset($this->objects[$key])){return Create::rejectionFor(new S3Exception('Missing fictional object',$command,['code'=>'NoSuchKey','response'=>new Response(404)]));}
            if($command->getName()==='HeadObject'){return Create::promiseFor(new Result(['ContentLength'=>strlen($this->objects[$key]),'ContentType'=>'text/plain']));}
            if($command->getName()==='GetObject'){$body=$this->fault==='read'?'Changed fictional bytes':$this->objects[$key];return Create::promiseFor(new Result(['Body'=>Utils::streamFor($body),'ContentLength'=>strlen($body),'ContentType'=>'text/plain']));}
            throw new \RuntimeException('Unexpected fixture S3 operation');
        };
        config(['filesystems.disks.'.$disk=>['driver'=>'s3','key'=>'FictionalAccessKey','secret'=>'FictionalSecret','region'=>'us-east-1','bucket'=>'fictional-private','endpoint'=>'https://storage.example.test','use_path_style_endpoint'=>true,'root'=>$prefix,'throw'=>true,'handler'=>$handler], 'evidence.disk'=>$disk,'evidence.legacy_disk'=>'local','evidence.private_disks'=>['local','private_cloud','second_private']]);
        Storage::forgetDisk($disk);
    }
    private function file(): UploadedFile {return UploadedFile::fake()->createWithContent('fictional-proof.txt','Fictional private storage proof');}

    public function test_locked_real_s3_adapter_uploads_without_acl_headers_and_preserves_hash_and_private_download(): void
    {
        $this->configure();$entry=PrivateEvidence::store($this->file(),'task-evidence',1);
        $this->assertSame('private_cloud',$entry['disk']);$this->assertTrue(PrivateEvidence::matchesHash($entry));$response=PrivateEvidence::download($entry);$this->assertSame('Fictional private storage proof',$response->getCallback() ? $this->stream($response):'');
        $this->assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
        $put=collect($this->requests)->firstWhere('operation','PutObject');$this->assertNull($put['acl']);$this->assertArrayNotHasKey('x-amz-acl',array_change_key_case($put['headers']));
        $this->assertTrue(PrivateEvidence::delete($entry));$this->assertFalse(PrivateEvidence::exists($entry));PrivateEvidence::store($this->file(),'task-evidence',1);foreach($this->requests as $request){$this->assertNull($request['acl']);}
    }
    private function stream($response): string {ob_start();$response->sendContent();return ob_get_clean();}

    public function test_configuration_command_is_read_only_and_does_not_construct_or_contact_s3(): void
    {
        $this->configure();$r=EvidenceStorage::inspect();$this->assertSame('configuration_present',$r['result']);$this->assertFalse($r['network_performed']);$this->assertSame([],$this->requests);
        $this->artisan('gta:evidence-storage')->assertSuccessful();$this->assertSame([],$this->requests);$this->assertDatabaseCount('task_evidence',0);
    }
    public function test_synthetic_probe_hash_reads_checks_unsigned_api_and_removes_only_its_prefixed_object(): void
    {
        $this->configure(prefix:'private-prefix');$this->objects['fictional-private/private-prefix/existing-proof.txt']='Keep this source';
        Http::fake(['storage.example.test/*'=>Http::response('',403)]);$r=EvidenceStorage::probe();$this->assertSame('passed',$r['result'],json_encode($r));$this->assertFalse($r['bucket_visibility_verified']);$this->assertFalse($r['database_records_created']);$this->assertSame(['fictional-private/private-prefix/existing-proof.txt'=>'Keep this source'],$this->objects);
        Http::assertSent(fn($request)=>str_contains($request->url(),'/private-prefix/gta-storage-probes/') && !$request->hasHeader('Authorization') && !str_contains($request->url(),'X-Amz-Signature'));
        $this->assertDatabaseCount('task_evidence',0);$this->assertStringNotContainsString('FictionalSecret',json_encode($r));
    }
    public function test_anonymously_readable_object_fails_probe_and_is_still_removed(): void
    {
        $this->configure();Http::fake(['*'=>Http::response('Public fictional bytes',200)]);$r=EvidenceStorage::probe();$this->assertSame('failed',$r['result']);$this->assertSame('anonymous_api_check',$r['failure']);$this->assertTrue($r['checks']['cleanup']);$this->assertSame([],$this->objects);
    }
    public function test_changed_read_bytes_fail_and_cleanup_still_runs(): void
    {
        $this->configure();$this->fault='read';Http::fake();$r=EvidenceStorage::probe();$this->assertSame('hash_read',$r['failure']);$this->assertTrue($r['checks']['cleanup']);$this->assertSame([],$this->objects);Http::assertNothingSent();
    }
    public function test_write_and_delete_failures_are_redacted_and_unremoved_synthetic_key_is_reported(): void
    {
        $this->configure();Http::fake(['*'=>Http::response('',403)]);$this->fault='write';$r=EvidenceStorage::probe();$this->assertSame('write',$r['failure']);$this->assertTrue($r['checks']['cleanup']);$this->assertStringNotContainsString('FictionalSecret',json_encode($r));
        $this->fault='delete';$r=EvidenceStorage::probe();$this->assertSame('failed',$r['result']);$this->assertFalse($r['checks']['cleanup']);$this->assertStringStartsWith('gta-storage-probes/',$r['cleanup_reference']);$this->assertCount(1,$this->objects);$this->assertStringNotContainsString('FictionalSecret',json_encode($r));
    }
    public function test_redirect_or_error_is_not_accepted_as_anonymous_privacy_proof(): void
    {
        $this->configure();foreach([301,500] as $status){Http::fake(['*'=>Http::response('',$status,['Location'=>'https://other.example.test'])]);$r=EvidenceStorage::probe();$this->assertSame('failed',$r['result']);$this->assertTrue($r['checks']['cleanup']);$this->assertSame([],$this->objects);}
    }
    public function test_probe_refuses_production_and_non_s3_without_network_or_database_changes(): void
    {
        $this->configure();$this->app['env']='production';$r=EvidenceStorage::probe();$this->assertFalse($r['network_performed']);$this->assertSame([],$this->requests);$this->app['env']='testing';config(['evidence.disk'=>'local']);$r=EvidenceStorage::probe();$this->assertFalse($r['network_performed']);$this->assertDatabaseCount('task_evidence',0);
    }
    public function test_public_url_visibility_and_grant_options_are_rejected_before_upload(): void
    {
        $this->configure();foreach([['url'=>'https://public.example.test'],['visibility'=>'public'],['options'=>['ACL'=>'public-read']],['options'=>['GrantRead'=>'uri=public']]] as $bad){$this->configure();config(['filesystems.disks.private_cloud'=>array_replace(config('filesystems.disks.private_cloud'),$bad)]);$r=EvidenceStorage::inspect();$this->assertSame('invalid_configuration',$r['result']);$this->assertSame([],$this->requests);}
    }
    public function test_task_controller_real_s3_adapter_keeps_scope_disk_pins_and_failed_audit_cleanup(): void
    {
        $this->configure();$learner=User::factory()->create();$learner->role='apprentice';$learner->save();$a=Apprentice::factory()->create(['user_id'=>$learner->id]);$task=LearningTask::factory()->create(['apprentice_id'=>$a->id]);
        $this->actingAs($learner)->post('/tasks/'.$task->id.'/evidence',['version'=>1,'kind'=>'supporting-evidence','file'=>UploadedFile::fake()->createWithContent('work.pdf',"%PDF-1.4\nFictional S3 task proof")])->assertSessionHasNoErrors();$entry=$task->evidence()->firstOrFail();$this->assertSame('private_cloud',$entry->disk);
        $this->configure('second_private');$this->get('/tasks/'.$task->id.'/evidence/'.$entry->id)->assertOk()->assertDownload();
        $other=User::factory()->create();$other->role='tutor';$other->save();$this->actingAs($other)->get('/tasks/'.$task->id.'/evidence/'.$entry->id)->assertForbidden();
        $count=count($this->objects);\Tests\Support\AuditFault::install('reject_s3_audit','learning_events','Fictional S3 audit failure');
        $this->actingAs($learner)->post('/tasks/'.$task->id.'/evidence',['version'=>2,'kind'=>'supporting-evidence','file'=>UploadedFile::fake()->createWithContent('work.pdf',"%PDF-1.4\nAnother fictional proof")])->assertStatus(500);$this->assertCount($count,$this->objects);$this->assertDatabaseCount('task_evidence',1);
    }
}
