<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\LearningTask;
use App\Models\Programme;
use App\Models\ProgressReviewDraft;
use App\Models\SignedReviewRecord;
use App\Models\TrainingSession;
use App\Models\User;
use App\Support\ApprenticeExperience;
use App\Support\EmployerActions;
use App\Support\JourneyEvents;
use App\Support\JourneyMarker;
use App\Support\JourneyOverview as J;
use App\Support\ReviewAppointments as A;
use App\Support\SignedReviews;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\CreatesOperationalPlan;
use Tests\TestCase;

class JourneyOverview56Test extends TestCase
{
    use CreatesOperationalPlan,LazilyRefreshDatabase;

    private function readyFixture(int $daysAgo = 10): array
    {
        Storage::fake('local');
        $f = $this->approvedFixture();
        [$a,$admin,$ceo,$tutor,$learner,$adviser,$plan] = $f;
        $mentor = $this->newActor('mentor');
        $a->mentor_id = $mentor->id;
        $a->save();
        $review = $this->readyReview($a, $tutor, $mentor, $daysAgo);

        return [...$f, $mentor, $review];
    }

    private function readyReview($a, $tutor, $mentor, int $daysAgo): ProgressReviewDraft
    {
        $date = today()->subDays($daysAgo)->toDateString();
        $fields = ['reviewDate' => $date, 'trainingFrom' => today()->subDays($daysAgo + 60)->toDateString(), 'mentor' => $mentor->name, 'tutor' => $tutor->name, 'employerRep' => 'Fictional Employer Representative', 'method' => 'Face to face', 'attendance' => 'Present', 'judgement' => 'Behind plan', 'previousCheck' => 'Existing action plan checked', 'planChange' => 'No', 'summary' => 'Fictional recorded three-way review', 'learning' => 'Recorded learning checked', 'progressDiscussion' => 'Evidence and gaps discussed', 'previousNotes' => 'N/A', 'planChangeNotes' => 'N/A', 'sharedSupport' => 'N/A', 'absence' => 'N/A', 'newNeeds' => 'No', 'trainingChecked' => 1, 'targetsAgreed' => 1, 'providerPrepared' => 1, 'safetyAnswer' => 'no'];
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews', ['fields' => $fields])->assertSessionHasNoErrors();
        $r = ProgressReviewDraft::where('apprentice_id', $a->id)->latest('id')->firstOrFail();
        $this->post('/apprentices/'.$a->id.'/reviews/'.$r->id.'/ready', ['version' => 1, 'confirmed' => 1])->assertSessionHasNoErrors();

        return $r->fresh();
    }

    private function copyData($a, $review): array
    {
        $pdf = $this->actingAs($a->tutor)->get('/apprentices/'.$a->id.'/reviews/'.$review->id.'/draft.pdf')->assertOk()->getContent();

        // Fictional PDF fixture exercises storage/binding. It does not validate real signatures.
        return ['version' => $review->version, 'file' => UploadedFile::fake()->createWithContent('fictional-review-copy.pdf', $pdf), 'source_reference' => 'Fictional externally signed source R'.$review->id, 'identity_reference' => 'Fictional learner / authorised employer / mentor record checks', 'copy_checked' => 1, 'apprentice_name' => $a->first_name.' '.$a->last_name, 'apprentice_date' => today()->toDateString(), 'employer_name' => $review->fields['employerRep'], 'employer_date' => today()->toDateString(), 'mentor_name' => $review->fields['mentor'], 'mentor_date' => today()->toDateString()];
    }

    private function uploadCopy($a, $r, $actor): SignedReviewRecord
    {
        $data = $this->copyData($a, $r);
        $this->actingAs($actor)->post('/review-signed-copies/'.$r->id, $data)->assertSessionHasNoErrors();

        return SignedReviewRecord::where('progress_review_draft_id', $r->id)->latest('id')->firstOrFail();
    }

    private function checks($r): array
    {
        return ['version' => $r->version, 'content_checked' => 1, 'identity_checked' => 1, 'signatures_checked' => 1];
    }

    private function f(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(12, 0));
        $u = User::factory()->create(['role' => 'apprentice']);
        $t = User::factory()->create(['role' => 'tutor']);
        $a = Apprentice::factory()->create(['user_id' => $u->id, 'tutor_id' => $t->id, 'start_date' => '2026-09-01']);

        return [$u, $a, $t];
    }

    private function trainingFixture($a, array $changes = []): TrainingSession
    {
        return TrainingSession::factory()->create(array_replace(['members' => [$a->id], 'session_date' => today()->addDay()->toDateString(), 'name' => 'Fictional next training56'], $changes));
    }

    private function appointment(bool $publish = true): array
    {
        [$u,$a,$t] = $this->f();
        $admin = User::factory()->create(['role' => 'admin']);
        $employer = User::factory()->create(['role' => 'employer']);
        $a->update(['email' => $u->email, 'programme_id' => Programme::factory()->create()->id, 'employer_id' => Employer::factory()->create()->id]);
        $g = EmployerActions::grant($admin, 1, $a, ['user_id' => $employer->id, 'version' => 0, 'identity_reference' => 'Fictional verified identity']);
        $p = A::save($admin, 1, $a, null, 0, (string) Str::uuid(), ['grant_id' => $g->id, 'host_id' => $t->id, 'date' => today()->addDays(3)->toDateString(), 'start' => '10:00', 'end' => '11:00', 'mode' => 'Training centre', 'place' => 'PRIVATE-LOCATION56']);
        if ($publish) {
            A::publish($admin, 1, $p, 1);
            $p->refresh();
        }

return [$u, $a, $t, $admin, $p];
    }

    public function test_next_action_and_saved_training_do_not_advance_the_latest_milestone(): void
    {
        [$u,$a] = $this->f();
        $task = LearningTask::factory()->create(['apprentice_id' => $a->id, 'title' => 'Continue fictional task56', 'due_date' => today()->addDays(4)]);
        $this->trainingFixture($a);
        $before = DB::table('audit_events')->count();
        $this->actingAs($u)->get(route('journey.show', $a))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('What happens next?')->assertSee($task->title)->assertSee('Fictional next training56')->assertSee('17/11/2026')->assertSee('Go to latest recorded point')->assertSee('id="journey-current-point"', false)->assertDontSee('Booked review appointment');
        $data = J::for($u, $a);
        $this->assertCount(1, $data['events']);
        $this->assertSame('2026-09-01', JourneyMarker::for($a, $data['events'])['date']);
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_numeric_text_roster_is_recognised_by_home_and_journey(): void
    {
        [$u,$a] = $this->f();
        $s = $this->trainingFixture($a, ['members' => [(string) $a->id]]);
        $this->assertSame($s->id, ApprenticeExperience::home($a)['session']->id);
        $this->assertSame($s->id, J::for($u, $a)['nextStep']['session']->id);
    }

    #[TestWith(['duplicate'])] #[TestWith(['wrong'])] #[TestWith(['cancelled'])] #[TestWith(['ended'])] #[TestWith(['badtime'])] #[TestWith(['reversed'])]
    public function test_invalid_sessions_are_not_next_training(string $kind): void
    {
        [$u,$a] = $this->f();
        $changes = match ($kind) {
            'duplicate' => ['members' => [$a->id, (string) $a->id]],'wrong' => ['members' => [$a->id + 999]],'cancelled' => ['state' => 'Cancelled'],'ended' => ['session_date' => today()->toDateString(), 'end_time' => '10:00'],'badtime' => ['start_time' => 'bad'],'reversed' => ['start_time' => '15:00', 'end_time' => '09:00']
        };
        $this->trainingFixture($a, $changes);
        $this->assertNull(J::for($u, $a)['nextStep']['session']);
    }

    public function test_current_published_booking_is_separate_and_location_is_not_shared_in_summary(): void
    {
        [$u,$a,$t,$admin,$p] = $this->appointment();
        $position = J::for($u, $a);
        $this->assertSame($p->id, $position['appointment']->id);
        $this->assertCount(1, $position['events']);
        $this->actingAs($u)->get(route('journey.show', $a))->assertOk()->assertSee('Booked review appointment')->assertSee('19/11/2026 10:00')->assertSee(route('review-appointments.show', $p), false)->assertDontSee('PRIVATE-LOCATION56')->assertSee('Booking, attendance and signatures are separate.');
    }

    public function test_draft_booking_is_not_a_booked_review(): void
    {
        [$u,$a] = $this->appointment(false);
        $this->assertNull(J::for($u, $a)['appointment']);
    }

    public function test_cancelled_booking_is_not_next_review(): void
    {
        [$u,$a,$t,$admin,$p] = $this->appointment();
        A::cancel($admin, 1, $p, 2, 'Private cancellation56');
        $this->assertNull(J::for($u, $a)['appointment']);
    }

    public function test_authority_drift_hides_booking_even_from_staff_summary(): void
    {
        [$u,$a,$t,$admin,$p] = $this->appointment();
        $a->update(['tutor_id' => User::factory()->create(['role' => 'tutor'])->id]);
        $this->assertNull(J::for($u, $a)['appointment']);
        $this->assertNull(J::for($admin, $a)['appointment']);
    }

    public function test_tampered_publication_is_not_current_booking(): void
    {
        [$u,$a,$t,$admin,$p] = $this->appointment();
        $p->source_hash = str_repeat('0', 64);
        $p->save();
        $this->assertNull(J::for($u, $a)['appointment']);
    }

    public function test_checked_review_adds_only_safe_meeting_milestone_and_separate_due_reference(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 16)->setTime(12, 0));
        [$a,$admin,$ceo,$tutor,$u,$adviser,$plan,$mentor,$review] = $this->readyFixture();
        $record = $this->uploadCopy($a, $review, $admin);
        $this->assertCount(1, J::for($u, $a)['events']);
        SignedReviews::confirm($record, $ceo, 1);
        $data = J::for($u, $a);
        $this->assertCount(2, $data['events']);
        $event = $data['events'][1];
        $this->assertSame('2026-11-06', $event['date']);
        $this->assertNull($event['url']);
        $this->assertSame('2027-01-15', $data['nextStep']['review']['next_due']);
        $this->actingAs($u)->get(route('journey.show', $a))->assertOk()->assertSee('Checked progress review')->assertSee('06/11/2026')->assertSee('15/01/2027')->assertDontSee('Fictional recorded three-way review')->assertDontSee('source.pdf');
        $staff = J::for($tutor, $a);
        $this->assertSame(route('reviews.signed.form', $review), $staff['events'][1]['url']);
        $this->actingAs($tutor)->get($staff['events'][1]['url'])->assertOk();
    }

    public function test_withdrawn_copy_retires_review_milestone(): void
    {
        [$a,$admin,$ceo,$tutor,$u,$adviser,$plan,$mentor,$review] = $this->readyFixture();
        $record = $this->uploadCopy($a, $review, $admin);
        SignedReviews::confirm($record, $ceo, 1);
        SignedReviews::withdraw($record->fresh(), $admin, 2, 'Fictional correction');
        $this->assertCount(1, J::for($u, $a)['events']);
        $this->assertNull(J::for($u, $a)['nextStep']['review']['next_due']);
    }

    public function test_tampered_copy_shows_a_source_check_and_no_milestone(): void
    {
        [$a,$admin,$ceo,$tutor,$u,$adviser,$plan,$mentor,$review] = $this->readyFixture();
        $record = $this->uploadCopy($a, $review, $admin);
        SignedReviews::confirm($record, $ceo, 1);
        $record->refresh()->update(['snapshot_hash' => str_repeat('0', 64)]);
        $this->assertCount(1, J::for($u, $a)['events']);
        $this->assertSame(1, J::for($u, $a)['reviewChecks']);
        $this->actingAs($u)->get(route('journey.show', $a))->assertOk()->assertSee('A checked review record needs source checking');
    }

    #[TestWith(['2026-11-99'])] #[TestWith(['nonsense'])] #[TestWith(['2026-02-30'])] #[TestWith(['2026-12-01'])] #[TestWith([['wrong']])]
    public function test_malformed_future_task_dates_cannot_break_or_advance_journey(mixed $date): void
    {
        [$u,$a] = $this->f();
        LearningTask::factory()->create(['apprentice_id' => $a->id, 'status' => 'Approved', 'title' => 'INVALID-DATE56', 'submissions' => [['decision' => ['date' => $date, 'feedback' => 'Invalid fixture']]]]);
        $this->assertCount(1, JourneyEvents::for($a));
        $this->actingAs($u)->get(route('journey.show', $a))->assertOk()->assertDontSee('INVALID-DATE56');
    }

    public function test_other_learner_unassigned_tutor_employer_specialist_cannot_read_overview(): void
    {
        [$u,$a] = $this->f();
        foreach (['apprentice', 'tutor', 'employer', 'careers_adviser'] as $role) {
            $other = User::factory()->create(['role' => $role]);
            $this->actingAs($other)->get(route('journey.show', $a))->assertForbidden();
        }
    }

    public function test_no_available_action_does_not_invent_completion(): void
    {
        [$u,$a] = $this->f();
        LearningTask::factory()->create(['apprentice_id' => $a->id, 'status' => 'Awaiting marking']);
        $this->actingAs($u)->get(route('journey.show', $a))->assertOk()->assertSee('1 task(s) await marking')->assertSee('No current upcoming training session')->assertSee('reference needs checking')->assertDontSee('100%');
    }

    public function test_no_external_messages_or_provider_requests_on_read(): void
    {
        Mail::fake();
        Http::preventStrayRequests();
        [$u,$a] = $this->f();
        $this->actingAs($u)->get(route('journey.show', $a))->assertOk();
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    #[TestWith(['self'])] #[TestWith(['future'])]
    public function test_impossible_checker_metadata_cannot_establish_milestone_or_due_reference(string $kind): void
    {
        [$a,$admin,$ceo,$tutor,$u,$adviser,$plan,$mentor,$review] = $this->readyFixture();
        $record = $this->uploadCopy($a, $review, $admin);
        SignedReviews::confirm($record,$ceo,1);
        $record->refresh()->update($kind === 'self' ? ['checked_by' => $admin->id] : ['checked_at' => now()->addDay()]);
        $data = J::for($u,$a);
        $this->assertCount(1,$data['events']);
        $this->assertSame(1,$data['reviewChecks']);
        $this->assertNull($data['nextStep']['review']['next_due']);
    }
}
