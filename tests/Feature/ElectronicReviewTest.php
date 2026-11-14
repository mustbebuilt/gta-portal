<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ElectronicReviewSigner as Signer;
use App\Models\Employer;
use App\Models\IndividualDeliveryPlan;
use App\Models\Programme;
use App\Models\ProgrammeOutcome;
use App\Models\ProgressReviewDraft as Review;
use App\Models\Qualification;
use App\Models\SignedReviewRecord;
use App\Models\TrainingTopic;
use App\Models\User;
use App\Support\ApprenticeReminderSources;
use App\Support\ElectronicReviews as E;
use App\Support\EmployerActions;
use App\Support\PrivateEvidence;
use App\Support\ReviewAutomation;
use App\Support\ReviewBriefs;
use App\Support\ReviewReminders;
use App\Support\SignedReviews;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\AuditFault;
use Tests\TestCase;

class ElectronicReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(bool $prepare = true): array
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        $checker = User::factory()->create(['role' => 'ceo']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $mentor = User::factory()->create(['role' => 'mentor', 'name' => 'Fictional Progress Mentor']);
        $learner = User::factory()->create(['role' => 'apprentice', 'name' => 'Fictional Apprentice']);
        $employer = User::factory()->create(['role' => 'employer', 'name' => 'Fictional Employer Representative']);
        $e = Employer::factory()->create(['name' => 'Fictional Signing Employer']);
        $programme = Programme::where('standard_code','ST0499')->where('version','1.3')->first() ?? Programme::factory()->create(['standard_code'=>'ST0499','version'=>'1.3']);
        $a = Apprentice::factory()->create(['first_name' => 'Fictional', 'last_name' => 'Apprentice', 'reference' => 'E-SIGN-'.bin2hex(random_bytes(4)), 'employer_id' => $e->id, 'programme_id' => $programme->id, 'user_id' => $learner->id, 'mentor_id' => $mentor->id, 'tutor_id' => $tutor->id, 'start_date' => today()->subDays(200)]);
        $blocks = collect(range(0, 11))->map(fn ($n) => ['start' => $a->start_date->copy()->addDays($n * 20)->toDateString(), 'end' => $a->start_date->copy()->addDays($n * 20 + 19)->toDateString(), 'hours' => $n === 11 ? 0 : 10])->all();
        $url = '/apprentices/'.$a->id.'/delivery-plan';
        $this->actingAs($admin)->post($url.'/calendar', ['version' => 0, 'blocks' => $blocks])->assertSessionHasNoErrors();
        $this->post($url.'/details', ['version' => 1, 'source_ref' => 'Fictional source plan', 'ksb_ref' => 'Fictional mapping', 'qualifications_ref' => 'Fictional requirements', 'release_ref' => 'Fictional release', 'target_hours' => 110, 'basis' => 'Full training retained'])->assertSessionHasNoErrors();
        $this->actingAs($checker)->post($url.'/approve', ['version' => 2, 'source_checked' => 1, 'hours_checked' => 1, 'curriculum_checked' => 1, 'release_checked' => 1])->assertSessionHasNoErrors();
        $plan = IndividualDeliveryPlan::where('apprentice_id', $a->id)->firstOrFail();
        $grant = EmployerActions::grant($admin, $admin->auth_version, $a, ['user_id' => $employer->id, 'version' => 0, 'identity_reference' => 'Fictional named account checks']);
        $authority = E::saveAuthority($admin, $admin->auth_version, $a, $grant, ['version' => 0, 'authority_reference' => 'PRIVATE-AUTHORITY-REFERENCE', 'expires_on' => today()->addDays(90)->toDateString()]);
        $fields = ['reviewDate' => today()->subDays(10)->toDateString(), 'trainingFrom' => today()->subDays(80)->toDateString(), 'mentor' => $mentor->name, 'tutor' => $tutor->name, 'employerRep' => $employer->name, 'method' => 'Face to face', 'attendance' => 'Present', 'judgement' => 'On track', 'learning' => 'Fictional recorded training', 'progressDiscussion' => 'Progress checked against sources', 'previousCheck' => 'Existing action plan checked', 'previousNotes' => 'Recorded previous targets checked', 'planChange' => 'No', 'planChangeNotes' => 'No change agreed', 'newNeeds' => 'No', 'sharedSupport' => 'PRIVATE-SUPPORT-NEVER-SHARE', 'summary' => 'Fictional three-way discussion', 'absence' => 'Not applicable', 'trainingChecked' => 1, 'targetsAgreed' => 1, 'providerPrepared' => 1];
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews', ['fields' => $fields])->assertSessionHasNoErrors();
        $review = Review::where('apprentice_id', $a->id)->firstOrFail();
        $this->post('/apprentices/'.$a->id.'/reviews/'.$review->id.'/ready', ['version' => 1, 'confirmed' => 1])->assertSessionHasNoErrors();
        $review->refresh();
        $p = $prepare ? E::prepare($tutor, $tutor->auth_version, $review, ['version' => $review->version, 'authority_id' => $authority->id, 'expires_on' => today()->addDays(20)->toDateString()]) : null;

        return compact('a', 'admin', 'checker', 'tutor', 'mentor', 'learner', 'employer', 'grant', 'authority', 'plan', 'review', 'p', 'e');
    }

    private function invalid(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected validation failure');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    private function release(array $f): void
    {
        E::release($f['tutor'], $f['tutor']->auth_version, $f['p'], 1);
    }

    private function data(array $f, User $u, string $decision = 'Signed'): array
    {
        $s = Signer::where('packet_id', $f['p']->id)->where('user_id', $u->id)->firstOrFail();

        return ['version' => $s->version, 'document_hash' => $f['p']->snapshot_hash, 'current_password' => 'password', 'typed_name' => $u->name, 'confirmed' => true, 'decision' => $decision, 'reason' => $decision === 'Declined' ? 'PRIVATE-DECLINE-REASON' : null];
    }

    private function sign(array $f, User $u): void
    {
        E::respond($u, $u->auth_version, $f['p']->fresh(), $this->data($f, $u));
    }

    private function allSigned(array $f): void
    {
        $this->release($f);
        foreach (['learner', 'employer', 'mentor'] as $role) {
            $this->sign($f, $f[$role]);
        }
    }

    private function confirm(array $f): SignedReviewRecord
    {
        return E::confirm($f['checker'], $f['checker']->auth_version, $f['p']->fresh(), $f['p']->fresh()->version, 'PRIVATE-FINAL-CHECK-REFERENCE');
    }

    public function test_authority_is_separate_from_reply_access_and_manager_only_with_private_reference(): void
    {
        $f = $this->fixture(false);
        extract($f);
        $this->actingAs($admin)->get('/apprentices/'.$a->id.'/review-signing-authority')->assertOk()->assertSee('PRIVATE-AUTHORITY-REFERENCE');
        $this->actingAs($tutor)->get('/apprentices/'.$a->id.'/review-signing-authority')->assertForbidden();
        $this->actingAs($employer)->get('/apprentices/'.$a->id.'/review-signing-authority')->assertForbidden();
        $this->assertStringNotContainsString('PRIVATE-AUTHORITY-REFERENCE', DB::table('review_signing_authorities')->value('authority_reference'));
        $this->assertArrayNotHasKey('authority_reference', $authority->toArray());
        E::revokeAuthority($admin, $admin->auth_version, $authority, 1, 'Fictional authority revoked');
        $this->invalid(fn () => E::prepare($tutor, $tutor->auth_version, $review, ['version' => 2, 'authority_id' => $authority->id, 'expires_on' => today()->addDay()->toDateString()]));
    }

    public function test_prepared_preview_has_three_distinct_slots_and_never_shares_restricted_source(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->assertDatabaseCount('electronic_review_signers', 3);
        $this->assertTrue(E::intact($p));
        $this->assertTrue(E::current($p));
        $this->assertSame('Draft', $p->state);
        $this->assertNull(SignedReviews::cycle($a)['next_due']);
        $this->assertArrayNotHasKey('snapshot', $p->toArray());
        $this->actingAs($tutor)->get('/review-signing/'.$p->id)->assertOk()->assertSee('Fictional recorded training')->assertDontSee('PRIVATE-SUPPORT-NEVER-SHARE')->assertDontSee('PRIVATE-AUTHORITY-REFERENCE')->assertHeader('Referrer-Policy', 'no-referrer');
        foreach ([$learner, $employer] as $u) {
            $this->actingAs($u)->get('/review-signing/'.$p->id)->assertForbidden();
        }
    }

    public function test_preparation_requires_frozen_current_plan_participant_names_and_current_authority(): void
    {
        $f = $this->fixture(false);
        extract($f);
        $r = $review->fields;
        $r['employerRep'] = 'Different Person';
        $review->fields = $r;
        $review->save();
        $this->invalid(fn () => E::prepare($tutor, $tutor->auth_version, $review, ['version' => 2, 'authority_id' => $authority->id, 'expires_on' => today()->addDay()->toDateString()]));
        $this->assertDatabaseCount('electronic_review_packets', 0);
        $r['employerRep'] = $employer->name;
        $review->fields = $r;
        $review->state = 'Draft';
        $review->save();
        $this->invalid(fn () => E::prepare($tutor, $tutor->auth_version, $review, ['version' => 2, 'authority_id' => $authority->id, 'expires_on' => today()->addDay()->toDateString()]));
    }

    public function test_duplicate_active_packet_external_copy_and_reopening_are_blocked(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->invalid(fn () => E::prepare($tutor, $tutor->auth_version, $review, ['version' => 2, 'authority_id' => $authority->id, 'expires_on' => today()->addDay()->toDateString()]));
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews/'.$review->id.'/reopen', ['version' => 2, 'reason' => 'Must withdraw packet first'])->assertSessionHasErrors();
        $this->invalid(fn () => SignedReviews::prepare($review, $tutor, UploadedFile::fake()->createWithContent('fake.pdf', '%PDF-FICTIONAL'), ['version' => 2]));
        $this->assertDatabaseCount('signed_review_records', 0);
    }

    public function test_release_requires_explicit_sharing_check_and_keeps_same_content_for_all_participants(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->actingAs($tutor)->post('/review-signing/'.$p->id.'/release', ['version' => 1])->assertSessionHasErrors('sharing_checked');
        $this->release($f);
        foreach ([$learner, $employer, $mentor] as $u) {
            $this->actingAs($u)->get('/review-signing/'.$p->id)->assertOk()->assertSee('Fictional recorded training')->assertDontSee('PRIVATE-SUPPORT-NEVER-SHARE')->assertDontSee('PRIVATE-AUTHORITY-REFERENCE');
        }$this->assertSame($p->snapshot_hash, $p->fresh()->snapshot_hash);
    }

    public function test_signing_requires_own_account_matching_name_correct_password_exact_content_and_intent(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->release($f);
        $base = $this->data($f, $learner);
        foreach ([['current_password' => 'wrong'], ['typed_name' => $employer->name], ['document_hash' => str_repeat('a', 64)], ['confirmed' => false]] as $override) {
            $this->invalid(fn () => E::respond($learner, $learner->auth_version, $p->fresh(), [...$base, ...$override]));
        }$this->assertSame('Pending', Signer::where('role', 'apprentice')->firstOrFail()->state);
    }

    public function test_other_accounts_cannot_sign_or_read_and_signing_has_no_proxy_endpoint(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->release($f);
        foreach (['tutor', 'apprentice', 'employer', 'mentor', 'careers_adviser'] as $role) {
            $u = User::factory()->create(['role' => $role]);
            $this->actingAs($u)->get('/review-signing/'.$p->id)->assertForbidden();
            $this->post('/review-signing/'.$p->id.'/respond', $this->data($f, $learner))->assertForbidden();
        }
        $this->actingAs($admin)->post('/review-signing/'.$p->id.'/respond', $this->data($f, $learner))->assertForbidden();
        $this->assertSame(0, Signer::where('state', 'Signed')->count());
    }

    public function test_signature_is_encrypted_identity_bound_and_duplicate_or_stale_posts_do_not_replace_it(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->release($f);
        $data = $this->data($f, $learner);
        $this->sign($f, $learner);
        $s = Signer::where('role', 'apprentice')->firstOrFail();
        $this->assertTrue(E::signerIntact($s, $p));
        $this->assertArrayNotHasKey('attestation', $s->toArray());
        $this->assertStringNotContainsString(E::INTENT, DB::table('electronic_review_signers')->where('id', $s->id)->value('attestation'));
        $before = $s->getAttributes();
        $this->invalid(fn () => E::respond($learner, $learner->auth_version, $p->fresh(), $data));
        $this->assertSame($before, $s->fresh()->getAttributes());
        $this->assertNull(SignedReviews::cycle($a)['next_due']);
    }

    public function test_three_signatures_need_independent_check_before_any_review_cycle_change(): void
    {
        $f = $this->fixture();
        extract($f);
        $before = $review->getAttributes();
        $this->allSigned($f);
        $this->assertSame('All signed', $p->fresh()->state);
        $this->assertNull(SignedReviews::cycle($a)['next_due']);
        $record = $this->confirm($f);
        $this->assertTrue(E::completedIntact($p->fresh()));
        $this->assertTrue(SignedReviews::intact($record));
        $this->assertSame(today()->subDays(10)->addDays(70)->toDateString(), SignedReviews::cycle($a)['next_due']);
        $this->assertSame($before, $review->fresh()->getAttributes());
        $this->assertTrue(PrivateEvidence::matchesHash($record->file));
        $this->assertSame('electronic', $record->snapshot['origin']);
        $this->actingAs($learner)->get('/review-signing/'.$p->id.'/record.pdf')->assertOk()->assertDownload();
        $this->actingAs($employer)->get('/review-signing/'.$p->id.'/record.pdf')->assertOk();
        if ($dir = getenv('GTA_QA_EXPORT_DIR')) {
            file_put_contents($dir.'/electronic-review.pdf', Storage::disk('local')->get($record->file['path']));
        }
    }

    public function test_preparer_cannot_confirm_and_missing_checkbox_or_replay_is_refused(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->allSigned($f);
        $this->actingAs($checker)->post('/review-signing/'.$p->id.'/confirm', ['version' => $p->fresh()->version, 'check_reference' => 'Source'])->assertSessionHasErrors();
        $p->prepared_by = $admin->id;
        $p->save();
        $this->invalid(fn () => E::confirm($admin, $admin->auth_version, $p, $p->version, 'Cannot self check'));
        $p->prepared_by = $tutor->id;
        $p->save();
        $this->confirm($f);
        $this->invalid(fn () => $this->confirm($f));
        $this->assertDatabaseCount('signed_review_records', 1);
    }

    public function test_decline_stops_further_signatures_retains_private_reason_and_requires_withdraw_reissue(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->release($f);
        $this->sign($f, $learner);
        E::respond($employer, $employer->auth_version, $p->fresh(), $this->data($f, $employer, 'Declined'));
        $this->assertSame('Declined', $p->fresh()->state);
        $this->invalid(fn () => $this->sign($f, $mentor));
        $this->invalid(fn () => $this->confirm($f));
        $this->actingAs($learner)->get('/review-signing/'.$p->id)->assertOk()->assertDontSee('PRIVATE-DECLINE-REASON');
        $this->actingAs($tutor)->get('/review-signing/'.$p->id)->assertOk()->assertSee('PRIVATE-DECLINE-REASON');
        E::withdraw($tutor, $tutor->auth_version, $p->fresh(), $p->fresh()->version, 'Reconcile wording');
        $this->assertSame('Withdrawn', $p->fresh()->state);
        $this->assertSame('Signed', Signer::where('role', 'apprentice')->firstOrFail()->state);
        $this->assertNull($p->fresh()->active_review_id);
    }

    public function test_identity_role_contact_auth_or_assigned_mentor_changes_block_pending_signing(): void
    {
        foreach (['name', 'email', 'auth', 'active', 'mentor'] as $case) {
            $f = $this->fixture();
            extract($f);
            $this->release($f);
            if ($case === 'name') {
                $learner->name = 'New Name';
            }if ($case === 'email') {
                $learner->email = 'changed-'.bin2hex(random_bytes(3)).'@example.test';
            }if ($case === 'auth') {
                $learner->auth_version++;
            }if ($case === 'active') {
                $learner->active = false;
            }$learner->save();
            if ($case === 'mentor') {
                $a->mentor_id = null;
                $a->save();
            }$this->assertFalse(E::current($p->fresh()));
        }
    }

    public function test_expiry_and_authority_revocation_or_grant_reverification_block_pending_records(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->release($f);
        E::revokeAuthority($admin, $admin->auth_version, $authority, 1, 'Revoked authority');
        $this->assertFalse(E::current($p->fresh()));
        $this->invalid(fn () => $this->sign($f, $learner));
        E::saveAuthority($admin, $admin->auth_version, $a, $grant, ['version' => 2, 'authority_reference' => 'Renewed authority', 'expires_on' => today()->addDays(90)->toDateString()]);
        $this->assertFalse(E::current($p->fresh()));
        $f = $this->fixture();
        $this->release($f);
        $this->travel(21)->days();
        $this->assertFalse(E::current($f['p']->fresh()));
        $this->invalid(fn () => $this->sign($f, $f['learner']));
    }

    public function test_current_plan_or_source_change_blocks_release_signing_and_confirmation(): void
    {
        $f = $this->fixture();
        extract($f);
        $fields = $review->fields;
        $fields['summary'] = 'Unexpected source change';
        $review->fields = $fields;
        $review->save();
        $this->assertFalse(E::current($p));
        $this->invalid(fn () => $this->release($f));
        $f = $this->fixture();
        extract($f);
        $this->allSigned($f);
        $plan->active = false;
        $plan->save();
        $this->invalid(fn () => $this->confirm($f));
        $this->assertDatabaseCount('signed_review_records', 0);
    }

    public function test_successor_apprentice_and_revoked_employer_cannot_read_predecessor_record(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->release($f);
        $replacement = User::factory()->create(['role' => 'apprentice', 'name' => $learner->name]);
        $a->user_id = $replacement->id;
        $a->save();
        $this->actingAs($learner)->get('/review-signing/'.$p->id)->assertForbidden();
        $this->actingAs($replacement)->get('/review-signing/'.$p->id)->assertForbidden();
        EmployerActions::revoke($admin, $admin->auth_version, $grant, $grant->version, 'Employer changed');
        $this->actingAs($employer)->get('/review-signing/'.$p->id)->assertForbidden();
    }

    public function test_checked_withdrawal_recalculates_cycle_retains_pdf_and_allows_source_reopen(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->allSigned($f);
        $record = $this->confirm($f);
        E::withdraw($checker, $checker->auth_version, $p->fresh(), $p->fresh()->version, 'PRIVATE-WITHDRAWAL-REFERENCE');
        $this->assertNull(SignedReviews::cycle($a)['next_due']);
        $this->assertNotNull($record->fresh()->withdrawn_at);
        $this->assertTrue(SignedReviews::intact($record->fresh()));
        $this->actingAs($learner)->get('/review-signing/'.$p->id)->assertOk()->assertSee('Withdrawn. Previous decisions')->assertDontSee('PRIVATE-WITHDRAWAL-REFERENCE');
        $this->get('/review-signing/'.$p->id.'/record.pdf')->assertOk()->assertDownload('Withdrawn-original-electronic-review-'.$p->id.'.pdf');
        $this->actingAs($tutor)->post('/apprentices/'.$a->id.'/reviews/'.$review->id.'/reopen', ['version' => 2, 'reason' => 'Correct source'])->assertSessionHasNoErrors();
        $this->assertSame('Draft', $review->fresh()->state);
    }

    public function test_existing_signed_copy_withdraw_route_also_withdraws_electronic_packet_atomically(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->allSigned($f);
        $record = $this->confirm($f);
        $this->actingAs($checker)->post('/signed-review-records/'.$record->id.'/withdraw', ['version' => 1, 'reason' => 'Legacy route correction'])->assertSessionHasNoErrors();
        $this->assertSame('Withdrawn', $p->fresh()->state);
        $this->assertNull($p->fresh()->active_review_id);
        $this->assertNull(SignedReviews::cycle($a)['next_due']);
    }

    public function test_signature_packet_or_completion_corruption_does_not_count_as_completed_review(): void
    {
        $f = $this->fixture();
        extract($f);
        $this->allSigned($f);
        $record = $this->confirm($f);
        $s = Signer::where('packet_id', $p->id)->where('role', 'apprentice')->firstOrFail();
        $payload = $s->attestation;
        $payload['name'] = 'Other person';
        $s->attestation = $payload;
        $s->save();
        $this->assertFalse(E::completedIntact($p->fresh()));
        $this->assertFalse(SignedReviews::intact($record));
        $this->assertNull(SignedReviews::cycle($a)['next_due']);
        $this->actingAs($checker)->get('/review-signing/'.$p->id.'/record.pdf')->assertStatus(409);
    }

    public function test_file_corruption_is_refused_on_participant_download(): void
    {
        $f = $this->fixture();
        $this->allSigned($f);
        $record = $this->confirm($f);
        Storage::disk('local')->put($record->file['path'], 'BAD-BYTES');
        $this->actingAs($f['learner'])->get('/review-signing/'.$f['p']->id.'/record.pdf')->assertStatus(409);
    }

    public function test_audit_failure_rolls_back_participant_decision_without_partial_signature(): void
    {
        $f = $this->fixture();
        $this->release($f);
        AuditFault::install('reject_e_sign', 'electronic_review_events', 'Fictional event failure');
        $this->actingAs($f['learner'])->post('/review-signing/'.$f['p']->id.'/respond', $this->data($f, $f['learner']))->assertStatus(500);
        $this->assertSame('Released', $f['p']->fresh()->state);
        $this->assertSame(0, Signer::where('state', 'Signed')->count());
    }

    public function test_final_audit_failure_leaves_no_checked_row_or_orphan_pdf(): void
    {
        $f = $this->fixture();
        $this->allSigned($f);
        AuditFault::install('reject_e_final', 'electronic_review_events', 'Fictional final event failure');
        $this->actingAs($f['checker'])->post('/review-signing/'.$f['p']->id.'/confirm', ['version' => $f['p']->fresh()->version, 'check_reference' => 'Final checks', 'content_checked' => 1, 'identity_checked' => 1, 'signatures_checked' => 1])->assertStatus(500);
        $this->assertDatabaseCount('signed_review_records', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles('electronic-review-records'));
        $this->assertSame('All signed', $f['p']->fresh()->state);
    }

    public function test_signing_rate_limit_is_applied_to_real_route_and_password_is_not_flashed(): void
    {
        $f = $this->fixture();
        $this->release($f);
        $this->actingAs($f['learner']);
        $d = $this->data($f, $f['learner']);
        $d['current_password'] = 'SECRET-WRONG-PASSWORD';
        for ($i = 0; $i < 6; $i++) {
            $this->post('/review-signing/'.$f['p']->id.'/respond', $d)->assertSessionHasErrors('current_password');
        }$this->assertNull(session()->getOldInput('current_password'));
        $this->post('/review-signing/'.$f['p']->id.'/respond', $d)->assertStatus(429);
    }

    public function test_signing_due_item_and_checked_cycle_feed_existing_reminders_without_private_text(): void
    {
        $f = $this->fixture();
        extract($f);
        ProgrammeOutcome::create(['apprentice_id' => $a->id, 'context' => ['status' => 'Active', 'sourceRef' => 'Fictional programme source']]);
        $this->release($f);
        $source = ApprenticeReminderSources::build($a)['source'];
        $this->assertSame('review_signing', $source['items'][0]['kind']);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($source));
        $this->assertSame(route('electronic-reviews.show', $p), ApprenticeReminderSources::link($a, $source['items'][0]));
        $this->sign($f, $learner);
        $this->assertNull(ApprenticeReminderSources::build($a)['source']);
        $this->sign($f, $employer);
        $this->sign($f, $mentor);
        $record = $this->confirm($f);
        $this->travel(61)->days();
        $position = ReviewReminders::position($a);
        $this->assertTrue($position['due']);
        $this->assertSame($record->id, $position['cycle']['latest_record_id']);
        $this->assertCount(2, $position['items']);
    }

    public function test_unauthenticated_inactive_and_other_learner_cannot_reach_signing_records(): void
    {
        $this->get('/review-signing')->assertRedirect('/login');
        $f = $this->fixture();
        $this->release($f);
        $u = User::factory()->create(['role' => 'apprentice', 'active' => false]);
        $this->actingAs($u)->get('/review-signing')->assertForbidden();
        $this->actingAs($f['learner'])->get('/review-signing?filter=mine')->assertOk()->assertSee($f['a']->reference);
    }

    public function test_reads_and_signatures_send_no_external_message_or_change_training_or_gateway(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        $f = $this->fixture();
        $this->allSigned($f);
        $this->confirm($f);
        foreach (['learning_tasks', 'qualifications', 'gateway_readiness_checks', 'survey_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_automation_captures_current_topic_qualification_otj_and_plan_facts_without_private_notes(): void
    {
        $f = $this->fixture(false);
        extract($f);
        $r = Review::factory()->create(['apprentice_id' => $a->id, 'author_id' => $tutor->id]);
        $topic = TrainingTopic::create(['apprentice_id' => $a->id, 'subject' => 'english', 'level' => 2, 'topic' => 'Purpose', 'area' => 'Reading', 'planned_hours' => 2]);
        $topic->status = 'Completed';
        $topic->completion_date = today()->subDay();
        $topic->save();
        Qualification::create(['apprentice_id' => $a->id, 'subject' => 'maths', 'type' => 'Functional Skills', 'title' => 'Maths Level 2', 'level' => 2, 'status' => 'Working towards', 'start_date' => today()->subYear(), 'notes' => 'PRIVATE-QUAL-NOTE']);
        $source = ReviewBriefs::source($a, $r, today()->subDays(70)->toDateString(), today()->toDateString());
        $notes = ReviewBriefs::suggest($source);
        $this->assertStringContainsString('English assigned topics: 1/1 completed', $notes['progressDiscussion']);
        $this->assertStringContainsString('Maths Level 2', $notes['progressDiscussion']);
        $this->assertStringContainsString('Current recorded OTJ subtotal:', $notes['progressDiscussion']);
        $this->assertStringContainsString('Historical completeness is not verified', $notes['progressDiscussion']);
        $this->assertStringNotContainsString('PRIVATE-QUAL-NOTE', json_encode($source));
        $this->assertStringNotContainsString('PRIVATE-SUPPORT-NEVER-SHARE', json_encode($source));
        $this->assertArrayNotHasKey('captured_at', $source['automation']['otj']);
    }

    public function test_automation_source_change_stops_stale_application_and_resets_manual_agreement_checks(): void
    {
        $f = $this->fixture(false);
        extract($f);
        $r = Review::factory()->create(['apprentice_id' => $a->id, 'author_id' => $tutor->id]);
        $d = ['version' => 1, 'period_from' => today()->subDays(70)->toDateString(), 'period_to' => today()->toDateString()];
        $b = ReviewBriefs::prepare($tutor, $tutor->auth_version, $r, $d);
        Qualification::create(['apprentice_id' => $a->id, 'subject' => 'maths', 'type' => 'Functional Skills', 'title' => 'Maths', 'level' => 2, 'status' => 'Achieved', 'achievement_date' => today()]);
        $this->invalid(fn () => ReviewBriefs::apply($tutor, $tutor->auth_version, $b, ['version' => 1, 'selected' => ['summary'], 'text' => ['summary' => 'Checked factual summary']]));
        $b = ReviewBriefs::prepare($tutor, $tutor->auth_version, $r, $d);
        ReviewBriefs::apply($tutor, $tutor->auth_version, $b, ['version' => 1, 'selected' => ['summary'], 'text' => ['summary' => 'Checked factual summary']]);
        $this->assertFalse($r->fresh()->fields['targetsAgreed']);
        $this->assertFalse($r->fresh()->fields['providerPrepared']);
        $this->assertFalse($r->fresh()->fields['trainingChecked']);
    }

    public function test_current_names_are_suggested_but_no_meeting_participation_or_judgement_is_invented(): void
    {
        $f = $this->fixture(false);
        extract($f);
        $d = ReviewAutomation::defaults($a);
        $this->assertSame($mentor->name,$d['mentor']);
        $this->assertSame($tutor->name,$d['tutor']);
        $this->assertSame($employer->name,$d['employerRep']);
        foreach (['reviewDate', 'attendance', 'judgement', 'targetsAgreed'] as $key) {
            $this->assertArrayNotHasKey($key,$d);
        }$this->actingAs($tutor)->get('/apprentices/'.$a->id.'/reviews/new')->assertOk()->assertSee('name="fields[employerRep]" value="'.e($employer->name).'"',false)->assertSee($mentor->name);
    }
}
