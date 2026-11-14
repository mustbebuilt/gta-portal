<?php
namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ManagementPack;
use App\Models\ProgressReviewDraft;
use App\Models\SignedReviewRecord;
use App\Support\PrivateEvidence;
use App\Support\SignedReviews;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class SignedReviewRecordTest extends TestCase
{
    use LazilyRefreshDatabase,CreatesOperationalPlan;

    private function readyFixture(int $daysAgo=10): array
    {
        Storage::fake('local');$f=$this->approvedFixture();[$a,$admin,$ceo,$tutor,$learner,$adviser,$plan]=$f;
        $mentor=$this->newActor('mentor');$a->mentor_id=$mentor->id;$a->save();
        $review=$this->readyReview($a,$tutor,$mentor,$daysAgo);
        return [...$f,$mentor,$review];
    }
    private function readyReview($a,$tutor,$mentor,int $daysAgo): ProgressReviewDraft
    {
        $date=today()->subDays($daysAgo)->toDateString();
        $fields=['reviewDate'=>$date,'trainingFrom'=>today()->subDays($daysAgo+60)->toDateString(),'mentor'=>$mentor->name,'tutor'=>$tutor->name,'employerRep'=>'Fictional Employer Representative','method'=>'Face to face','attendance'=>'Present','judgement'=>'Behind plan','previousCheck'=>'Existing action plan checked','planChange'=>'No','summary'=>'Fictional recorded three-way review','learning'=>'Recorded learning checked','progressDiscussion'=>'Evidence and gaps discussed','previousNotes'=>'N/A','planChangeNotes'=>'N/A','sharedSupport'=>'N/A','absence'=>'N/A','newNeeds'=>'No','trainingChecked'=>1,'targetsAgreed'=>1,'providerPrepared'=>1];
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews',['fields'=>$fields])->assertSessionHasNoErrors();
        $r=ProgressReviewDraft::where('apprentice_id',$a->id)->latest('id')->firstOrFail();
        $this->post('/apprentices/'.$a->id.'/reviews/'.$r->id.'/ready',['version'=>1,'confirmed'=>1])->assertSessionHasNoErrors();return $r->fresh();
    }
    private function copyData($a,$review): array
    {
        $pdf=$this->actingAs($a->tutor)->get('/apprentices/'.$a->id.'/reviews/'.$review->id.'/draft.pdf')->assertOk()->getContent();
        // Fictional PDF fixture exercises storage/binding. It does not validate real signatures.
        return ['version'=>$review->version,'file'=>UploadedFile::fake()->createWithContent('fictional-review-copy.pdf',$pdf),'source_reference'=>'Fictional externally signed source R'.$review->id,'identity_reference'=>'Fictional learner / authorised employer / mentor record checks','copy_checked'=>1,'apprentice_name'=>$a->first_name.' '.$a->last_name,'apprentice_date'=>today()->toDateString(),'employer_name'=>$review->fields['employerRep'],'employer_date'=>today()->toDateString(),'mentor_name'=>$review->fields['mentor'],'mentor_date'=>today()->toDateString()];
    }
    private function uploadCopy($a,$r,$actor): SignedReviewRecord
    {
        $data=$this->copyData($a,$r);$this->actingAs($actor)->post('/review-signed-copies/'.$r->id,$data)->assertSessionHasNoErrors();
        return SignedReviewRecord::where('progress_review_draft_id',$r->id)->latest('id')->firstOrFail();
    }
    private function checks($r): array {return ['version'=>$r->version,'content_checked'=>1,'identity_checked'=>1,'signatures_checked'=>1];}

    public function test_upload_is_pending_then_independent_check_tracks_meeting_plus_seventy_without_rewriting_frozen_content(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$r]=$this->readyFixture();$before=$r->getAttributes();
        $record=$this->uploadCopy($a,$r,$admin);$this->assertNull(SignedReviews::cycle($a)['next_due']);$this->assertNull($record->checked_at);
        $this->actingAs($admin)->post('/signed-review-records/'.$record->id.'/confirm',$this->checks($record))->assertSessionHasErrors('version');
        $this->actingAs($ceo)->post('/signed-review-records/'.$record->id.'/confirm',$this->checks($record))->assertSessionHasNoErrors();
        $cycle=SignedReviews::cycle($a);$this->assertSame(today()->subDays(10)->addDays(70)->toDateString(),$cycle['next_due']);$this->assertSame(1,$cycle['confirmed_count']);$this->assertSame($before,$r->fresh()->getAttributes());
        $this->actingAs($tutor)->get('/reviews')->assertOk()->assertSee($cycle['next_due']);$this->get('/apprentices/'.$a->id.'/reviews/'.$r->id)->assertOk()->assertSee('Signed review source content');
        $this->get('/signed-review-records/'.$record->id.'/source.pdf')->assertOk()->assertDownload();
        $this->actingAs($ceo)->post('/signed-review-records/'.$record->id.'/confirm',$this->checks($record))->assertSessionHasErrors('version');
        $this->assertDatabaseCount('signed_review_events',2);
        if($dir=getenv('GTA_QA_EXPORT_DIR')){$pdf=$this->get('/apprentices/'.$a->id.'/reviews/'.$r->id.'/draft.pdf')->assertOk();file_put_contents($dir.'/review-for-external-signing.pdf',$pdf->getContent());}
    }

    public function test_required_checks_signer_identity_dates_and_frozen_state_are_enforced(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$r]=$this->readyFixture();$data=$this->copyData($a,$r);
        $this->actingAs($tutor)->post('/review-signed-copies/'.$r->id,[...$data,'employer_name'=>'Other person'])->assertSessionHasErrors('version');
        $data=$this->copyData($a,$r);$this->actingAs($tutor)->post('/review-signed-copies/'.$r->id,[...$data,'mentor_date'=>today()->subDays(11)->toDateString()])->assertSessionHasErrors('version');
        $this->assertDatabaseCount('signed_review_records',0);$record=$this->uploadCopy($a,$r,$tutor);
        $this->actingAs($admin)->post('/signed-review-records/'.$record->id.'/confirm',['version'=>1])->assertSessionHasErrors('content_checked');
        $this->post('/apprentices/'.$a->id.'/reviews/'.$r->id.'/reopen',['version'=>$r->version,'reason'=>'Fictional correction'])->assertSessionHasErrors('version');
        $this->post('/signed-review-records/'.$record->id.'/withdraw',['version'=>1,'reason'=>'Wrong signed source selected'])->assertSessionHasNoErrors();
        $this->post('/apprentices/'.$a->id.'/reviews/'.$r->id.'/reopen',['version'=>$r->version,'reason'=>'Correct the source revision'])->assertSessionHasNoErrors();
        $data=$this->copyData($a,$r->fresh());$this->actingAs($tutor)->post('/review-signed-copies/'.$r->id,$data)->assertSessionHasErrors('version');
    }

    public function test_current_assignments_and_private_download_scope_do_not_expand_to_other_roles(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$r]=$this->readyFixture();$record=$this->uploadCopy($a,$r,$tutor);
        foreach([$learner,$adviser,$this->newActor('tutor'),$this->newActor('mentor')] as $u){
            $this->actingAs($u)->get('/review-signed-copies/'.$r->id)->assertForbidden();$this->get('/signed-review-records/'.$record->id.'/source.pdf')->assertForbidden();$this->post('/signed-review-records/'.$record->id.'/confirm',$this->checks($record))->assertForbidden();
        }
        $this->actingAs($mentor)->get('/review-signed-copies/'.$r->id)->assertOk();$this->get('/signed-review-records/'.$record->id.'/source.pdf')->assertOk();
        $replacement=$this->newActor('tutor');$a->tutor_id=$replacement->id;$a->save();$this->actingAs($tutor)->get('/signed-review-records/'.$record->id.'/source.pdf')->assertForbidden();
        $this->actingAs($admin)->post('/signed-review-records/'.$record->id.'/confirm',$this->checks($record))->assertSessionHasErrors('version');
    }

    public function test_file_integrity_and_current_plan_binding_are_checked_before_confirmation_or_download(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$r]=$this->readyFixture();$record=$this->uploadCopy($a,$r,$tutor);
        Storage::disk('local')->put($record->file['path'],'changed bytes');
        $this->actingAs($admin)->post('/signed-review-records/'.$record->id.'/confirm',$this->checks($record))->assertSessionHasErrors('version');$this->get('/signed-review-records/'.$record->id.'/source.pdf')->assertStatus(409);
        $this->assertNull($record->fresh()->checked_at);Storage::disk('local')->delete($record->file['path']);$this->get('/signed-review-records/'.$record->id.'/source.pdf')->assertNotFound();
        $this->post('/signed-review-records/'.$record->id.'/withdraw',['version'=>1,'reason'=>'Replace unavailable file'])->assertSessionHasNoErrors();$record=$this->uploadCopy($a,$r,$tutor);
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/delivery-plan/withdraw',['version'=>3,'reason'=>'Source applicability changed'])->assertSessionHasNoErrors();
        $this->post('/signed-review-records/'.$record->id.'/confirm',$this->checks($record))->assertSessionHasErrors('version');
    }

    public function test_duplicate_upload_and_audit_failure_do_not_leave_orphan_files_or_partial_confirmation(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$r]=$this->readyFixture();$data=$this->copyData($a,$r);
        \Tests\Support\AuditFault::install('reject_signed_event','signed_review_events','Fictional audit failure');
        $this->actingAs($tutor)->post('/review-signed-copies/'.$r->id,$data)->assertStatus(500);$this->assertDatabaseCount('signed_review_records',0);$this->assertCount(0,Storage::disk('local')->allFiles('signed-review-evidence'));
        DB::statement('DROP TRIGGER reject_signed_event');$record=$this->uploadCopy($a,$r,$tutor);$data=$this->copyData($a,$r);
        $this->actingAs($tutor)->post('/review-signed-copies/'.$r->id,$data)->assertSessionHasErrors('version');$this->assertCount(1,Storage::disk('local')->allFiles('signed-review-evidence'));
        \Tests\Support\AuditFault::install('reject_signed_event','signed_review_events','Fictional audit failure');
        $this->actingAs($admin)->post('/signed-review-records/'.$record->id.'/confirm',$this->checks($record))->assertStatus(500);$this->assertNull($record->fresh()->checked_at);$this->assertSame(1,$record->fresh()->version);
        $this->post('/signed-review-records/'.$record->id.'/withdraw',['version'=>1,'reason'=>'Fictional withdrawal'])->assertStatus(500);$this->assertNull($record->fresh()->withdrawn_at);
    }

    public function test_latest_meeting_not_upload_date_drives_cycle_and_withdrawal_retains_history_and_old_packs(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$recent]=$this->readyFixture(10);
        $new=$this->uploadCopy($a,$recent,$tutor);$this->actingAs($admin)->post('/signed-review-records/'.$new->id.'/confirm',$this->checks($new))->assertSessionHasNoErrors();
        $expected=SignedReviews::cycle($a)['next_due'];$old=$this->readyReview($a,$tutor,$mentor,100);$prior=$this->uploadCopy($a,$old,$tutor);
        $this->actingAs($admin)->post('/signed-review-records/'.$prior->id.'/confirm',$this->checks($prior))->assertSessionHasNoErrors();$this->assertSame($expected,SignedReviews::cycle($a)['next_due']);
        $this->post('/reports/management-packs',['title'=>'Fictional signed review position','confirmed'=>1])->assertSessionHasNoErrors();$pack=ManagementPack::firstOrFail();$snapshot=$pack->snapshot;
        $this->assertSame(2,$snapshot['apprentices'][0]['reviews']['confirmed_copies']);$this->assertSame(0,$snapshot['apprentices'][0]['reviews']['ready_for_signatures']);
        $new->refresh();$this->post('/signed-review-records/'.$new->id.'/withdraw',['version'=>$new->version,'reason'=>'Fictional source correction'])->assertSessionHasNoErrors();
        $cycle=SignedReviews::cycle($a);$this->assertSame(today()->subDays(30)->toDateString(),$cycle['next_due']);$this->assertSame('Overdue tracked reference',$cycle['status']);$this->assertSame($snapshot,$pack->fresh()->snapshot);
        $this->get('/signed-review-records/'.$new->id.'/source.pdf')->assertOk();$this->assertCount(3,$new->fresh()->history);
    }

    public function test_snapshot_corruption_is_not_a_valid_cycle_and_draft_readiness_alone_never_resets_reference(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$r]=$this->readyFixture();$this->assertNull(SignedReviews::cycle($a)['next_due']);
        $record=$this->uploadCopy($a,$r,$tutor);$this->actingAs($admin)->post('/signed-review-records/'.$record->id.'/confirm',$this->checks($record))->assertSessionHasNoErrors();
        $s=$record->snapshot;$s['fields']['summary']='Unexpected edit';$record->snapshot=$s;$record->save();
        $cycle=SignedReviews::cycle($a);$this->assertSame(1,$cycle['integrity_checks']);$this->assertNull($cycle['next_due']);$this->get('/signed-review-records/'.$record->id.'/source.pdf')->assertStatus(409);
    }
    public function test_duplicate_confirmed_meeting_date_is_rejected_and_signature_dates_do_not_extend_the_cycle(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$r]=$this->readyFixture();
        $one=$this->uploadCopy($a,$r,$tutor);$this->actingAs($admin)->post('/signed-review-records/'.$one->id.'/confirm',$this->checks($one))->assertSessionHasNoErrors();
        $again=$this->readyReview($a,$tutor,$mentor,10);$two=$this->uploadCopy($a,$again,$tutor);
        $this->actingAs($admin)->post('/signed-review-records/'.$two->id.'/confirm',$this->checks($two))->assertSessionHasErrors('version');
        $this->assertSame(1,SignedReviews::cycle($a)['confirmed_count']);$this->assertSame(today()->addDays(60)->toDateString(),SignedReviews::cycle($a)['next_due']);
    }

    public function test_retained_signed_source_uses_its_pinned_private_disk_after_default_upload_location_changes(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$r]=$this->readyFixture();
        config(['filesystems.disks.signed_archive'=>['driver'=>'local','root'=>storage_path('app/signed-archive'),'visibility'=>'private'],'evidence.private_disks'=>['local','signed_archive'],'evidence.disk'=>'signed_archive']);Storage::fake('signed_archive');
        $record=$this->uploadCopy($a,$r,$tutor);$this->assertSame('signed_archive',$record->file['disk']);config(['evidence.disk'=>'local']);
        $this->actingAs($admin)->post('/signed-review-records/'.$record->id.'/confirm',$this->checks($record))->assertSessionHasNoErrors();
        $this->get('/signed-review-records/'.$record->id.'/source.pdf')->assertOk()->assertDownload();$this->assertTrue(PrivateEvidence::matchesHash($record->file));
    }

}
