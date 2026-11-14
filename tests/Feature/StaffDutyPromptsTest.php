<?php

namespace Tests\Feature;

use App\Models\{CareersPrompt, ProgressReviewDraft, ReviewReminder, StaffDuty, User};
use App\Support\{CareersPrompts, ReminderDelivery, ReviewReminders, SignedReviews, StaffDuties};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\{ConfiguresStaging, CreatesOperationalPlan};
use Tests\TestCase;

class StaffDutyPromptsTest extends TestCase
{
    use LazilyRefreshDatabase, CreatesOperationalPlan, ConfiguresStaging;

    private function fixture(): array
    {
        Storage::fake('local');[$a,$admin,$ceo,$tutor]=$this->approvedFixture();
        $staff=User::factory()->create(['role'=>'staff']);StaffDuties::save($staff,$admin,1,1,0,['careers_adviser'=>true,'mentor'=>true]);$staff->refresh();
        $a->mentor_id=$staff->id;$a->careers_adviser_id=$staff->id;$a->save();
        $this->actingAs($admin)->post('/apprentices/'.$a->id.'/outcome',['version'=>0,'context'=>['status'=>'Active','sourceRef'=>'Fictional active status']])->assertSessionHasNoErrors();
        $fields=['reviewDate'=>today()->subDays(80)->toDateString(),'trainingFrom'=>today()->subDays(110)->toDateString(),
            'mentor'=>$staff->name,'tutor'=>$tutor->name,'employerRep'=>'Fictional Employer Representative','method'=>'Face to face',
            'attendance'=>'Present','judgement'=>'Behind plan','previousCheck'=>'Existing action plan checked','planChange'=>'No',
            'summary'=>'Fictional review','learning'=>'Actual learning checked','progressDiscussion'=>'Evidence checked','previousNotes'=>'N/A',
            'planChangeNotes'=>'N/A','sharedSupport'=>'N/A','absence'=>'N/A','newNeeds'=>'No','trainingChecked'=>1,'targetsAgreed'=>1,'providerPrepared'=>1];
        $this->actingAs($staff)->post('/apprentices/'.$a->id.'/reviews',['fields'=>$fields])->assertSessionHasNoErrors();
        $review=ProgressReviewDraft::where('apprentice_id',$a->id)->firstOrFail();
        $this->post('/apprentices/'.$a->id.'/reviews/'.$review->id.'/ready',['version'=>1,'confirmed'=>1])->assertSessionHasNoErrors();$review->refresh();
        $pdf=$this->get('/apprentices/'.$a->id.'/reviews/'.$review->id.'/draft.pdf')->assertOk()->getContent();
        $record=SignedReviews::prepare($review,$staff,UploadedFile::fake()->createWithContent('fictional.pdf',$pdf),[
            'version'=>$review->version,'source_reference'=>'Fictional external-copy workflow; not authentic signatures','identity_reference'=>'Fictional participant checks',
            'apprentice_name'=>$a->first_name.' '.$a->last_name,'apprentice_date'=>today()->toDateString(),
            'employer_name'=>$fields['employerRep'],'employer_date'=>today()->toDateString(),'mentor_name'=>$staff->name,'mentor_date'=>today()->toDateString()]);
        SignedReviews::confirm($record,$admin,1);
        return [$a,$admin,$staff,$review];
    }

    public function test_additional_duties_integrate_with_current_sources_and_acknowledgement_without_recording_advice_or_a_new_meeting(): void
    {
        [$a,$admin,$staff,$review]=$this->fixture();
        $this->assertSame(2,CareersPrompts::generate(true)['created']);$this->assertSame(2,ReviewReminders::generate(true)['created']);
        $career=CareersPrompt::firstOrFail();$mentor=ReviewReminder::where('recipient_id',$staff->id)->firstOrFail();
        $this->assertSame('mentor',$mentor->source['recipient_role']);$this->assertArrayHasKey('duty_binding',$mentor->source);
        $this->actingAs($staff)->post('/careers/prompts/'.$career->id.'/seen')->assertSessionHasNoErrors();
        $this->post('/reviews/reminders/'.$mentor->id.'/seen')->assertSessionHasNoErrors();
        $this->assertNotNull($career->fresh()->seen_at);$this->assertNotNull($mentor->fresh()->seen_at);
        $this->assertDatabaseCount('career_discussions',0);$this->assertDatabaseCount('signed_review_records',1);
        $this->get('/careers')->assertOk();$this->get('/reviews')->assertOk();
    }

    public function test_revoke_and_regrant_stale_old_sources_instead_of_reusing_old_acknowledgements(): void
    {
        [$a,$admin,$staff]=$this->fixture();CareersPrompts::generate(true);ReviewReminders::generate(true);
        $oldCareer=CareersPrompt::firstOrFail();$oldReview=ReviewReminder::where('recipient_id',$staff->id)->firstOrFail();
        StaffDuties::save($staff,$admin,1,$staff->record_version,1,[]);
        $this->actingAs($staff->fresh())->get('/careers')->assertForbidden();$this->get('/reviews')->assertForbidden();
        StaffDuties::save($staff,$admin,1,$staff->fresh()->record_version,2,['careers_adviser'=>true,'mentor'=>true]);
        CareersPrompts::generate(true);ReviewReminders::generate(true);
        $this->assertDatabaseCount('careers_prompts',4);$this->assertDatabaseCount('review_reminders',3);
        $this->actingAs($staff->fresh())->post('/careers/prompts/'.$oldCareer->id.'/seen')->assertForbidden();
        $this->post('/reviews/reminders/'.$oldReview->id.'/seen')->assertSessionHasErrors('reminder');
        $this->assertNull($oldCareer->fresh()->seen_at);$this->assertNull($oldReview->fresh()->seen_at);
    }

    public function test_additional_mentor_can_be_an_explicit_staging_mail_candidate_but_revocation_excludes_it(): void
    {
        [$a,$admin,$staff]=$this->fixture();ReviewReminders::generate(true);
        $this->configureStaging();config(['reminder_delivery.enabled'=>true,'reminder_delivery.recipients'=>[$staff->email],
            'mail.default'=>'smtp','mail.mailers.smtp'=>['transport'=>'smtp','scheme'=>'smtps','url'=>null,'host'=>'smtp.example.test','port'=>465,'timeout'=>10],
            'mail.from.address'=>'portal@example.test']);
        $snapshot=ReminderDelivery::candidate($staff)['snapshot'];$this->assertNotNull($snapshot);$this->assertSame('review',$snapshot['items'][0]['kind']);
        StaffDuties::save($staff,$admin,1,$staff->record_version,1,[]);
        $this->assertNull(ReminderDelivery::candidate($staff->fresh())['snapshot']);
        $this->assertDatabaseCount('reminder_mail_outbox',0);
    }
}
