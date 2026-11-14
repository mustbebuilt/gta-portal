<?php
namespace Tests\Feature;
use App\Models\{ProgressReviewDraft,ReviewReminder,SignedReviewRecord,User};
use App\Support\{ReviewReminders,SignedReviews,ReminderDelivery};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Notification,Storage};
use Tests\Concerns\{CreatesOperationalPlan,ConfiguresStaging};
use Tests\TestCase;
class ReminderDeliveryReviewTest extends TestCase
{
    use DatabaseMigrations,CreatesOperationalPlan,ConfiguresStaging;
    private function fixture(int $days=80,bool $confirm=true): array
    {
        Storage::fake('local');$f=$this->approvedFixture();[$a,$admin,$ceo,$tutor]=$f;
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/outcome',['version'=>0,'context'=>['status'=>'Active','sourceRef'=>'Fictional active enrolment source']])->assertSessionHasNoErrors();
        $mentor=$this->newActor('mentor');$a->mentor_id=$mentor->id;$a->save();
        [$review,$record]=$this->meeting($a,$tutor,$mentor,$admin,$days,$confirm);return [...$f,$mentor,$review,$record];
    }
    private function meeting($a,$tutor,$mentor,$admin,int $days,bool $confirm=true): array
    {
        $fields=['reviewDate'=>today()->subDays($days)->toDateString(),'trainingFrom'=>today()->subDays($days+30)->toDateString(),'mentor'=>$mentor->name,'tutor'=>$tutor->name,'employerRep'=>'Fictional Employer Representative','method'=>'Face to face','attendance'=>'Present','judgement'=>'Behind plan','previousCheck'=>'Existing action plan checked','planChange'=>'No','summary'=>'Fictional review','learning'=>'Actual learning checked','progressDiscussion'=>'Evidence discussed','previousNotes'=>'N/A','planChangeNotes'=>'N/A','sharedSupport'=>'N/A','absence'=>'N/A','newNeeds'=>'No','trainingChecked'=>1,'targetsAgreed'=>1,'providerPrepared'=>1];
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews',['fields'=>$fields])->assertSessionHasNoErrors();$r=ProgressReviewDraft::where('apprentice_id',$a->id)->latest('id')->firstOrFail();
        $this->post('/apprentices/'.$a->id.'/reviews/'.$r->id.'/ready',['version'=>1,'confirmed'=>1])->assertSessionHasNoErrors();$r->refresh();
        $pdf=$this->get('/apprentices/'.$a->id.'/reviews/'.$r->id.'/draft.pdf')->assertOk()->getContent();
        // Fictional unsigned fixture tests attestation flow, not authenticity of signatures.
        $data=['version'=>$r->version,'source_reference'=>'Fictional external source','identity_reference'=>'Fictional participant checks','apprentice_name'=>$a->first_name.' '.$a->last_name,'apprentice_date'=>today()->toDateString(),'employer_name'=>$fields['employerRep'],'employer_date'=>today()->toDateString(),'mentor_name'=>$mentor->name,'mentor_date'=>today()->toDateString()];
        $record=SignedReviews::prepare($r,$tutor,UploadedFile::fake()->createWithContent('fictional.pdf',$pdf),$data);
        if($confirm){SignedReviews::confirm($record,$admin,1);}return [$r,$record->fresh()];
    }
    private function pilot(array $emails): void
    {
        $this->configureStaging();config(['reminder_delivery.enabled'=>true,'reminder_delivery.recipients'=>$emails,'mail.default'=>'smtp','mail.mailers.smtp'=>['transport'=>'smtp','scheme'=>'smtps','url'=>null,'host'=>'smtp.example.test','port'=>465,'timeout'=>10],'mail.from.address'=>'portal@example.test']);
    }
    public function test_checked_actual_meeting_reminders_bind_both_tutor_and_mentor_without_private_payload(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor]=$this->fixture();ReviewReminders::generate(true);$this->pilot([$tutor->email,$mentor->email]);Notification::fake();
        foreach([$tutor,$mentor]as$owner){$candidate=ReminderDelivery::candidate($owner);$this->assertCount(1,$candidate['snapshot']['items']);$this->assertSame('review',$candidate['snapshot']['items'][0]['kind']);$row=ReminderDelivery::prepare($admin,1,$owner->id,ReminderDelivery::hash($candidate['snapshot']));ReminderDelivery::transition($row,$admin,1,1,'review');$this->assertSame('accepted',ReminderDelivery::dispatch($row->id)['result']);$this->assertNull(ReviewReminder::where('recipient_id',$owner->id)->first()->seen_at);}
        $this->assertDatabaseCount('reminder_mail_outbox',2);$this->assertDatabaseCount('signed_review_events',2);
    }
    public function test_withdrawn_checked_copy_and_corrupt_reminder_source_remove_candidate(): void
    {
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan,$mentor,$review,$record]=$this->fixture();ReviewReminders::generate(true);$this->pilot([$tutor->email,$mentor->email]);$candidate=ReminderDelivery::candidate($tutor);$this->assertNotNull($candidate['snapshot']);$row=ReminderDelivery::prepare($admin,1,$tutor->id,ReminderDelivery::hash($candidate['snapshot']));ReminderDelivery::transition($row,$admin,1,1,'review');
        $r=ReviewReminder::where('recipient_id',$tutor->id)->first();$source=$r->source;$source['due_date']='2099-01-01';$r->source=$source;$r->save();$this->assertNull(ReminderDelivery::candidate($tutor)['snapshot']);Notification::fake();ReminderDelivery::dispatch($row->id);$this->assertSame('stale',$row->fresh()->state);Notification::assertNothingSent();SignedReviews::withdraw($record,$admin,$record->version,'Fictional withdrawal');$this->assertNull(ReminderDelivery::candidate($mentor)['snapshot']);
    }
}
