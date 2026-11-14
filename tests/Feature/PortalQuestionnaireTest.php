<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\PortalQuestionnaireAssignment as Assignment;
use App\Models\PortalQuestionnaireForm as Form;
use App\Models\PortalQuestionnaireSubmission as Submission;
use App\Models\ProgrammeOutcome;
use App\Models\SupportCapability;
use App\Models\SupportConcern;
use App\Models\User;
use App\Support\ApprenticeReminderSources;
use App\Support\PortalQuestionnaires as Q;
use App\Support\ReviewedImports;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PortalQuestionnaireTest extends TestCase
{
    public function test_encrypted_draft_cannot_be_replayed_into_another_assignment(): void
    {
        [$u,$a,$x,$staff,$f] = $this->assignment();
        Q::respond($u, $u->auth_version, $x, ['version' => 1, 'answers' => ['q1' => 'PRIVATE-FIRST-DRAFT']], false);
        [$v,$b,$y] = $this->assignment($f);
        DB::table('portal_questionnaire_assignments')->where('id', $y->id)->update(['draft_answers' => DB::table('portal_questionnaire_assignments')->where('id', $x->id)->value('draft_answers')]);
        $this->actingAs($v)->get('/portal-questionnaires/'.$y->id)->assertStatus(409)->assertDontSee('PRIVATE-FIRST-DRAFT');
    }

    public function test_submitted_payload_is_bound_to_original_profile_and_cycle(): void
    {
        [$u,$a,$x] = $this->assignment();
        $this->submit($u, $x);
        $x->refresh();
        $s = Submission::firstOrFail();
        $this->assertTrue(Q::submissionIntact($s, $x, $x->form));
        $x->cycle_code = 'different-cycle';
        $this->assertFalse(Q::submissionIntact($s, $x, $x->form));
        $x->cycle_code = 'test_cycle';
        $x->apprentice_id = 999;
        $this->assertFalse(Q::submissionIntact($s, $x, $x->form));
    }

    public function test_staff_history_shows_private_cancellation_reason_and_learner_cannot_see_it(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        Q::cancel($staff, $staff->auth_version, $x, 1, 'PRIVATE-HISTORY-REASON');
        $this->actingAs($staff)->get('/portal-questionnaires/'.$x->id)->assertOk()->assertSee('PRIVATE-HISTORY-REASON')->assertSee('Private assignment history')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->actingAs($u)->get('/portal-questionnaires/'.$x->id)->assertOk()->assertDontSee('PRIVATE-HISTORY-REASON')->assertDontSee('Private assignment history');
    }

    use LazilyRefreshDatabase;

    private function manager(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function learner(): array
    {
        $u = User::factory()->create(['role' => 'apprentice']);
        $a = Apprentice::factory()->create(['user_id' => $u->id]);

        return [$u, $a];
    }

    private function data(string $kind = 'induction'): array
    {
        return ['kind' => $kind, 'title' => 'GTA fictional questionnaire', 'introduction' => 'Explain how the questionnaire will be used.', 'privacy_notice' => 'Fictional provider privacy wording for tests.', 'questions' => [['key' => 'q1', 'label' => 'What would help your training?', 'type' => 'text', 'required' => $kind !== 'ongoing', 'options' => []], ['key' => 'q2', 'label' => 'Would you like someone to contact you?', 'type' => 'choice', 'required' => false, 'options' => ['Yes', 'No', 'Prefer not to say']]]];
    }

    private function form(string $kind = 'induction'): Form
    {
        $u = $this->manager();
        $f = Q::saveForm($u, $u->auth_version, null, $this->data($kind));
        $checker = $this->manager();

        return Q::formAction($checker, $checker->auth_version, $f, 1, 'publish');
    }

    private function assignment(?Form $f = null, ?array $learner = null, string $cycle = 'test_cycle', ?string $due = null): array
    {
        [$u,$a] = $learner ?? $this->learner();
        $staff = $this->manager();
        $f = $f ?? $this->form();
        $x = Q::assign($staff, $staff->auth_version, $f, ['version' => $f->version, 'apprentice_ids' => [$a->id], 'cycle_code' => $cycle, 'due_date' => $due]);

        return [$u, $a, Assignment::findOrFail($x['created'][0]), $staff, $f];
    }

    private function submit(User $u, Assignment $x, string $text = 'PRIVATE-ANSWER-SENTINEL'): void
    {
        Q::respond($u, $u->auth_version, $x, ['version' => $x->version, 'answers' => ['q1' => $text, 'q2' => 'Yes'], 'confirm' => true], true);
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

    public function test_author_ui_creates_draft_and_different_manager_publishes_frozen_revision(): void
    {
        $u = $this->manager();
        $data = $this->data();
        $data['questions'][0]['options_text'] = '';
        $data['questions'][1]['options_text'] = "Yes\nNo\nPrefer not to say";
        $this->actingAs($u)->get('/portal-questionnaires/forms')->assertOk()->assertSee('Create a form');
        $this->post('/portal-questionnaires/forms', $data)->assertRedirect();
        $f = Form::firstOrFail();
        $this->assertSame('Draft', $f->state);
        $this->post('/portal-questionnaires/forms/'.$f->id.'/state', ['version' => 1, 'action' => 'publish'])->assertSessionHasErrors();
        $other = $this->manager();
        $this->actingAs($other)->post('/portal-questionnaires/forms/'.$f->id.'/state', ['version' => 1, 'action' => 'publish'])->assertRedirect();
        $f->refresh();
        $this->assertSame('Published', $f->state);
        $this->assertTrue(Q::intact($f));
        $this->assertSame($other->id, $f->approved_by);
        $this->post('/portal-questionnaires/forms/'.$f->id, [...$data, 'version' => 2, 'title' => 'Attempted mutation'])->assertSessionHasErrors();
        $this->assertSame($data['title'], $f->fresh()->title);
    }

    public function test_only_managers_or_inclusion_lead_can_author_and_only_managers_publish(): void
    {
        $f = $this->form();
        foreach (['apprentice', 'employer', 'tutor', 'mentor', 'careers_adviser', 'inclusion_member'] as $role) {
            $u = User::factory()->create(['role' => $role]);
            $this->actingAs($u)->get('/portal-questionnaires/forms')->assertForbidden();
        }
        $lead = User::factory()->create(['role' => 'inclusion_lead']);
        $this->actingAs($lead)->get('/portal-questionnaires/forms')->assertOk();
        $draft = Q::formAction($lead, $lead->auth_version, $f, $f->version, 'revise');
        $this->post('/portal-questionnaires/forms/'.$draft->id.'/state', ['version' => 1, 'action' => 'publish'])->assertForbidden();
    }

    public function test_invalid_question_definitions_are_refused_without_partial_form_or_events(): void
    {
        $u = $this->manager();
        foreach (['duplicate', 'type', 'options', 'required', 'choiceblank', 'unknown_key', 'oversize', 'ongoing'] as $case) {
            $d = $this->data();
            if ($case === 'duplicate') {
                $d['questions'][1]['key'] = 'q1';
            }if ($case === 'type') {
                $d['questions'][0]['type'] = 'html';
            }if ($case === 'options') {
                $d['questions'][0]['options'] = ['invalid'];
            }if ($case === 'required') {
                $d['questions'][0]['required'] = 'yes';
            }if ($case === 'choiceblank') {
                $d['questions'][1]['options'] = ['Yes', ''];
            }if ($case === 'unknown_key') {
                $d['questions'][0]['key'] = '../unsafe';
            }if ($case === 'oversize') {
                $d['questions'] = array_fill(0, 31, $d['questions'][0]);
            }if ($case === 'ongoing') {
                $d['kind'] = 'ongoing';
            }
            $this->invalid(fn () => Q::saveForm($u, $u->auth_version, null, $d));
        }$this->assertDatabaseCount('portal_questionnaire_forms', 0);
        $this->assertDatabaseCount('portal_questionnaire_events', 0);
    }

    public function test_stale_edit_and_tampered_published_form_cannot_assign_or_open(): void
    {
        [$u,$a,$x,$staff,$f] = $this->assignment();
        $f->title = 'DATABASE-TAMPER';
        $f->save();
        $this->actingAs($u)->get('/portal-questionnaires/'.$x->id)->assertStatus(409);
        $this->invalid(fn () => Q::assign($staff, $staff->auth_version, $f, ['version' => $f->version, 'apprentice_ids' => [$a->id], 'cycle_code' => 'other', 'due_date' => null]));
        $this->assertDatabaseCount('portal_questionnaire_assignments', 1);
    }

    public function test_revisions_do_not_change_existing_assignments_and_retirement_stops_new_assignments(): void
    {
        [$u,$a,$x,$staff,$f] = $this->assignment();
        $next = Q::formAction($staff, $staff->auth_version, $f, $f->version, 'revise');
        $this->assertSame(2, $next->revision);
        $this->assertSame('Draft', $next->state);
        $this->invalid(fn () => Q::formAction($staff, $staff->auth_version, $f, $f->version, 'revise'));
        $retired = Q::formAction($staff, $staff->auth_version, $f, $f->version, 'retire');
        $this->invalid(fn () => Q::assign($staff, $staff->auth_version, $retired, ['version' => $retired->version, 'apprentice_ids' => [$a->id], 'cycle_code' => 'new', 'due_date' => null]));
        $this->submit($u, $x);
        $this->assertSame('Submitted', $x->fresh()->state);
        $this->assertSame($f->id, $x->fresh()->form_id);
        $this->assertTrue(Q::intact($retired));
    }

    public function test_batch_assignment_is_atomic_and_checks_every_profile_scope(): void
    {
        $u = User::factory()->create(['role' => 'inclusion_member']);
        [$learner,$a] = $this->learner();
        [$other,$b] = $this->learner();
        SupportCapability::create(['user_id' => $u->id, 'duty' => 'member', 'scope' => 'assigned', 'apprentice_ids' => [$a->id], 'group_ids' => []]);
        $f = $this->form();
        $this->actingAs($u)->post('/portal-questionnaires/assign', ['form_id' => $f->id, 'version' => $f->version, 'cycle_code' => 'batch', 'apprentice_ids' => [$a->id, $b->id]])->assertForbidden();
        $this->assertDatabaseCount('portal_questionnaire_assignments', 0);
    }

    public function test_batch_refuses_unverified_inactive_unbound_or_multiple_profile_accounts_atomically(): void
    {
        $f = $this->form();
        $staff = $this->manager();
        foreach (['unverified', 'inactive', 'wrongrole', 'unbound'] as $case) {
            [$u,$a] = $this->learner();
            if ($case === 'unverified') {
                $u->email_verified_at = null;
            }if ($case === 'inactive') {
                $u->active = false;
            }if ($case === 'wrongrole') {
                $u->role = 'employer';
            }$u->save();
            if ($case === 'unbound') {
                $a->user_id = null;
                $a->save();
            }
            $this->invalid(fn () => Q::assign($staff, $staff->auth_version, $f, ['version' => $f->version, 'apprentice_ids' => [$a->id], 'cycle_code' => $case, 'due_date' => null]));
        }$this->assertDatabaseCount('portal_questionnaire_assignments', 0);
    }

    public function test_repeat_batch_and_different_revision_do_not_duplicate_same_series_cycle(): void
    {
        [$u,$a,$x,$staff,$f] = $this->assignment();
        $d = ['version' => $f->version, 'apprentice_ids' => [$a->id], 'cycle_code' => 'test_cycle', 'due_date' => null];
        $r = Q::assign($staff, $staff->auth_version, $f, $d);
        $this->assertSame([], $r['created']);
        $this->assertSame([$a->id], $r['existing_apprentices']);
        $next = Q::formAction($staff, $staff->auth_version, $f, $f->version, 'revise');
        $checker = $this->manager();
        $next = Q::formAction($checker, $checker->auth_version, $next, 1, 'publish');
        Q::assign($staff, $staff->auth_version, $next, [...$d, 'version' => $next->version]);
        $this->assertDatabaseCount('portal_questionnaire_assignments', 1);
    }

    public function test_incomplete_draft_is_encrypted_private_and_hidden_from_model_serialization(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        $this->actingAs($u)->post('/portal-questionnaires/'.$x->id, ['version' => 1, 'action' => 'save', 'answers' => ['q1' => 'PRIVATE-DRAFT-SENTINEL']])->assertRedirect();
        $x->refresh();
        $this->assertSame('In progress', $x->state);
        $this->assertSame('PRIVATE-DRAFT-SENTINEL', Q::draftAnswers($x, $x->form)['q1']);
        $this->assertStringNotContainsString('PRIVATE-DRAFT-SENTINEL', DB::table('portal_questionnaire_assignments')->where('id', $x->id)->value('draft_answers'));
        $this->assertArrayNotHasKey('draft_answers', $x->toArray());
        $this->actingAs($staff)->get('/portal-questionnaires/'.$x->id)->assertOk()->assertDontSee('PRIVATE-DRAFT-SENTINEL')->assertSee('Unfinished answers are private');
        $this->assertDatabaseCount('portal_questionnaire_submissions', 0);
    }

    public function test_submit_requires_required_answers_known_keys_valid_choices_and_confirmation(): void
    {
        [$u,$a,$x] = $this->assignment();
        foreach ([['answers' => [], 'confirm' => true], ['answers' => ['q1' => 'ok', 'unknown' => 'bad'], 'confirm' => true], ['answers' => ['q1' => 'ok', 'q2' => 'Wrong'], 'confirm' => true], ['answers' => ['q1' => 'ok'], 'confirm' => false], ['answers' => ['q1' => ['nested']], 'confirm' => true], ['answers' => ['q1' => str_repeat('x', 5001)], 'confirm' => true]] as $d) {
            $this->invalid(fn () => Q::respond($u, $u->auth_version, $x, ['version' => 1, ...$d], true));
        }
        $this->assertDatabaseCount('portal_questionnaire_submissions', 0);
        $this->assertSame('Assigned', $x->fresh()->state);
    }

    public function test_blank_optional_html_answers_can_be_saved_and_submitted(): void
    {
        [$u,$a,$x] = $this->assignment();
        $this->actingAs($u)->post('/portal-questionnaires/'.$x->id, ['version' => 1, 'action' => 'submit', 'answers' => ['q1' => '<script>PRIVATE-XSS</script>', 'q2' => ''], 'confirm' => 1])->assertRedirect();
        $this->get('/portal-questionnaires/'.$x->id)->assertOk()->assertSee('&lt;script&gt;PRIVATE-XSS&lt;/script&gt;', false)->assertDontSee('<script>PRIVATE-XSS</script>', false);
    }

    public function test_submission_is_immutable_duplicate_and_stale_requests_do_not_add_revisions(): void
    {
        [$u,$a,$x] = $this->assignment();
        $this->submit($u, $x);
        $s = Submission::firstOrFail();
        $this->assertTrue(Q::submissionIntact($s, $x->fresh(), $x->form));
        $this->assertArrayNotHasKey('answer_payload', $s->toArray());
        $this->assertStringNotContainsString('PRIVATE-ANSWER-SENTINEL', DB::table('portal_questionnaire_submissions')->value('answer_payload'));
        $this->invalid(fn () => $this->submit($u, $x));
        $x->refresh();
        $this->invalid(fn () => Q::respond($u, $u->auth_version, $x, ['version' => $x->version, 'answers' => ['q1' => 'changed'], 'confirm' => true], true));
        $this->assertDatabaseCount('portal_questionnaire_submissions', 1);
        $this->assertSame('PRIVATE-ANSWER-SENTINEL', $s->fresh()->answer_payload['answers']['q1']);
    }

    public function test_other_apprentice_employer_and_unassigned_tutor_cannot_read_or_mutate_response(): void
    {
        [$u,$a,$x] = $this->assignment();
        $this->submit($u, $x);
        [$other] = $this->learner();
        foreach ([$other, User::factory()->create(['role' => 'employer']), User::factory()->create(['role' => 'tutor'])] as $actor) {
            $this->actingAs($actor)->get('/portal-questionnaires/'.$x->id)->assertForbidden();
            $this->post('/portal-questionnaires/'.$x->id, ['version' => 2, 'action' => 'save', 'answers' => ['q1' => 'bad']])->assertForbidden();
            $this->post('/portal-questionnaires/'.$x->id.'/check', ['version' => 2, 'action' => 'check', 'followup' => 'Not required'])->assertForbidden();
        }
    }

    public function test_account_reassignment_blocks_old_and_new_user_from_predecessor_response(): void
    {
        [$u,$a,$x] = $this->assignment();
        $this->submit($u, $x);
        $new = User::factory()->create(['role' => 'apprentice']);
        $a->user_id = $new->id;
        $a->save();
        $this->actingAs($u)->get('/portal-questionnaires/'.$x->id)->assertForbidden();
        $this->actingAs($new)->get('/portal-questionnaires/'.$x->id)->assertForbidden();
        $this->get('/portal-questionnaires?filter=all')->assertOk()->assertDontSee($x->form->title);
        $this->assertDatabaseCount('portal_questionnaire_submissions', 1);
    }

    public function test_scoped_inclusion_duty_gains_only_current_assigned_profile_access_and_revocation_takes_effect(): void
    {
        [$u,$a,$x] = $this->assignment();
        $this->submit($u, $x);
        $staff = User::factory()->create(['role' => 'tutor']);
        $cap = SupportCapability::create(['user_id' => $staff->id, 'duty' => 'member', 'scope' => 'assigned', 'apprentice_ids' => [$a->id], 'group_ids' => []]);
        $this->actingAs($staff)->get('/portal-questionnaires/'.$x->id)->assertOk()->assertSee('PRIVATE-ANSWER-SENTINEL');
        $cap->apprentice_ids = [];
        $cap->save();
        $this->get('/portal-questionnaires/'.$x->id)->assertForbidden();
    }

    public function test_inclusion_check_records_private_note_shared_message_and_followup_without_receipt_or_concern_mutation(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        $this->submit($u, $x);
        $this->actingAs($staff)->post('/portal-questionnaires/'.$x->id.'/check', ['version' => 2, 'action' => 'check', 'followup' => 'Required', 'private_note' => 'PRIVATE-STAFF-NOTE', 'shared_note' => 'SHARED-THANK-YOU'])->assertRedirect();
        $this->assertSame('Checked', $x->fresh()->state);
        $s = Submission::firstOrFail();
        $this->assertSame('Required', $s->followup);
        $this->assertStringNotContainsString('PRIVATE-STAFF-NOTE', DB::table('portal_questionnaire_submissions')->value('private_note'));
        $this->assertDatabaseCount('survey_receipts', 0);
        $this->assertDatabaseCount('support_concerns', 0);
        $this->actingAs($u)->get('/portal-questionnaires/'.$x->id)->assertOk()->assertSee('SHARED-THANK-YOU')->assertDontSee('PRIVATE-STAFF-NOTE')->assertDontSee('Private Inclusion check');
        $this->assertSame(1, Q::counts($staff)['followup_required']);
    }

    public function test_return_requires_shared_reason_and_resubmission_preserves_both_revisions(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        $this->submit($u, $x);
        $x->refresh();
        $this->invalid(fn () => Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'return']));
        Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'return', 'shared_note' => 'Please explain the training support you want.', 'private_note' => 'PRIVATE-RETURN-NOTE']);
        $x->refresh();
        $this->assertSame('In progress', $x->state);
        $this->assertSame('PRIVATE-ANSWER-SENTINEL', Q::draftAnswers($x, $x->form)['q1']);
        $this->actingAs($u)->get('/portal-questionnaires/'.$x->id)->assertOk()->assertSee('Please explain')->assertDontSee('PRIVATE-RETURN-NOTE');
        $this->submit($u, $x, 'SECOND-RESPONSE');
        $this->assertDatabaseCount('portal_questionnaire_submissions', 2);
        $subs = Submission::orderBy('revision')->get();
        $this->assertSame('Returned', $subs[0]->state);
        $this->assertSame('PRIVATE-ANSWER-SENTINEL', $subs[0]->answer_payload['answers']['q1']);
        $this->assertSame('SECOND-RESPONSE', $subs[1]->answer_payload['answers']['q1']);
    }

    public function test_checking_unsubmitted_stale_checked_or_corrupt_response_is_refused(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        $this->invalid(fn () => Q::review($staff, $staff->auth_version, $x, ['version' => 1, 'action' => 'check', 'followup' => 'Not required']));
        $this->submit($u, $x);
        $x->refresh();
        $this->invalid(fn () => Q::review($staff, $staff->auth_version, $x, ['version' => 1, 'action' => 'check', 'followup' => 'Not required']));
        $s = Submission::firstOrFail();
        $p = $s->answer_payload;
        $p['answers']['q1'] = 'TAMPERED';
        $s->answer_payload = $p;
        $s->save();
        $this->assertFalse(Q::submissionIntact($s, $x, $x->form));
        $this->invalid(fn () => Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'check', 'followup' => 'Not required']));
        $this->actingAs($staff)->get('/portal-questionnaires/'.$x->id)->assertStatus(409);
    }

    public function test_concern_links_require_followup_and_same_apprentice(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        $this->submit($u, $x);
        $other = Apprentice::factory()->create();
        $c = SupportConcern::create(['apprentice_id' => $other->id, 'raised_by' => $staff->id, 'initial_note' => 'Private', 'status' => 'Open']);
        $this->invalid(fn () => Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'check', 'followup' => 'Required', 'concern_id' => $c->id]));
        $c->apprentice_id = $a->id;
        $c->save();
        $before = $c->fresh()->toArray();
        $this->invalid(fn () => Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'check', 'followup' => 'Not required', 'concern_id' => $c->id]));
        Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'check', 'followup' => 'Required', 'concern_id' => $c->id]);
        $this->assertSame($c->id, Submission::firstOrFail()->concern_id);
        $this->assertSame($before, $c->fresh()->toArray());
        $this->actingAs($staff)->get('/portal-questionnaires/'.$x->id)->assertOk()->assertSee('Open linked apprentice support records');
    }

    public function test_cancel_preserves_history_stops_edits_and_never_cancels_submitted_response(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        Q::cancel($staff, $staff->auth_version, $x, 1, 'PRIVATE-CANCEL-REASON');
        $x->refresh();
        $this->assertSame('Cancelled', $x->state);
        $this->invalid(fn () => $this->submit($u, $x));
        $this->actingAs($u)->get('/portal-questionnaires/'.$x->id)->assertOk()->assertDontSee('PRIVATE-CANCEL-REASON')->assertDontSee('Submit to Inclusion Team');
        $this->assertStringNotContainsString('PRIVATE-CANCEL-REASON', DB::table('portal_questionnaire_events')->where('event', 'cancelled')->value('private_reason'));
        [$other,$b,$y,$staff2] = $this->assignment();
        $this->submit($other, $y);
        $this->invalid(fn () => Q::cancel($staff2, $staff2->auth_version, $y, 2, 'Cannot erase submitted history'));
        $this->assertSame('Submitted', $y->fresh()->state);
    }

    public function test_ongoing_is_optional_undated_never_overdue_and_never_in_reminder_sources(): void
    {
        $f = $this->form('ongoing');
        [$u,$a] = $this->learner();
        $staff = $this->manager();
        $this->invalid(fn () => Q::assign($staff, $staff->auth_version, $f, ['version' => $f->version, 'apprentice_ids' => [$a->id], 'cycle_code' => 'voluntary', 'due_date' => today()->toDateString()]));
        [$u,$a,$x,$staff] = $this->assignment($f, [$u, $a]);
        Q::respond($u, $u->auth_version, $x, ['version' => 1, 'answers' => [], 'confirm' => true], true);
        $this->assertSame('Submitted', $x->fresh()->state);
        $this->assertSame(0, Q::counts($staff)['overdue']);
    }

    public function test_homepage_worklist_and_reminder_source_integrate_without_answer_or_form_title_leak(): void
    {
        [$u,$a,$x,$staff,$f] = $this->assignment(null, null, 'due', today()->addDay()->toDateString());
        $f->title = 'PRIVATE-TITLE';
        $f->content_hash = ReviewedImports::hash(Q::content($f));
        $f->save();
        ProgrammeOutcome::create(['apprentice_id' => $a->id, 'context' => ['status' => 'Active', 'sourceRef' => 'fictional-source']]);
        $this->actingAs($u)->get('/my-next-step')->assertOk()->assertSee('1 open questionnaire(s)')->assertSee('Open my questionnaires');
        $source = ApprenticeReminderSources::build($a)['source'];
        $this->assertSame('questionnaire_due', $source['items'][0]['kind']);
        $this->assertStringNotContainsString('PRIVATE-TITLE', json_encode($source));
        $this->assertSame(route('portal-questionnaires.show', $x), ApprenticeReminderSources::link($a, $source['items'][0]));
        $this->submit($u, $x);
        $this->assertNull(ApprenticeReminderSources::build($a)['source']);
        $this->get('/my-next-step')->assertOk()->assertSee('0 open questionnaire(s)');
    }

    public function test_voluntary_future_unlinked_or_corrupt_forms_do_not_add_due_reminder_items(): void
    {
        [$u,$a] = $this->learner();
        ProgrammeOutcome::create(['apprentice_id' => $a->id, 'context' => ['status' => 'Active', 'sourceRef' => 'fictional']]);
        [$u,$a,$ongoing] = $this->assignment($this->form('ongoing'), [$u, $a], 'optional');
        $ongoing->due_date = today();
        $ongoing->save();
        $this->assignment($this->form(), [$u, $a], 'future', today()->addDays(10)->toDateString());
        $this->assertNull(ApprenticeReminderSources::build($a)['source']);
    }

    public function test_status_counts_filters_and_csv_are_scoped_and_contain_no_answers_or_notes(): void
    {
        [$u,$a,$x,$staff,$f] = $this->assignment(null, null, 'due', today()->subDay()->toDateString());
        $this->assertSame(1, Q::counts($staff)['overdue']);
        $this->actingAs($staff)->get('/portal-questionnaires?filter=overdue')->assertOk()->assertSee($f->title);
        $this->submit($u, $x);
        Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'check', 'followup' => 'Required', 'private_note' => 'PRIVATE-EXPORT-NOTE', 'shared_note' => 'SHARED-EXPORT-NOTE']);
        $a->reference = '=SOMEFORMULA()';
        $a->save();
        $r = $this->actingAs($staff)->get('/portal-questionnaires/status.csv?filter=followup')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $r->streamedContent();
        $this->assertStringContainsString("'=SOMEFORMULA()", $csv);
        $this->assertStringNotContainsString('PRIVATE-ANSWER-SENTINEL', $csv);
        $this->assertStringNotContainsString('PRIVATE-EXPORT-NOTE', $csv);
        $this->assertStringNotContainsString('SHARED-EXPORT-NOTE', $csv);
        $this->assertStringNotContainsString($u->email, $csv);
        [$other,$b,$otherX] = $this->assignment();
        $member = User::factory()->create(['role' => 'inclusion_member']);
        SupportCapability::create(['user_id' => $member->id, 'duty' => 'member', 'scope' => 'assigned', 'apprentice_ids' => [$a->id], 'group_ids' => []]);
        $csv = $this->actingAs($member)->get('/portal-questionnaires/status.csv?filter=all')->streamedContent();
        $this->assertStringNotContainsString($otherX->id, $csv);
        $this->actingAs($u)->get('/portal-questionnaires/status.csv')->assertForbidden();
    }

    public function test_guests_inactive_stale_auth_and_invalid_filters_fail_without_changes(): void
    {
        $this->get('/portal-questionnaires')->assertRedirect('/login');
        [$u,$a,$x] = $this->assignment();
        $this->actingAs($u)->get('/portal-questionnaires?filter=bad')->assertSessionHasErrors();
        $u->increment('auth_version');
        $this->post('/portal-questionnaires/'.$x->id, ['version' => 1, 'action' => 'save'])->assertRedirect('/login');
        $this->assertSame('Assigned', $x->fresh()->state);
        $u->active = false;
        $u->save();
        $this->actingAs($u)->get('/portal-questionnaires')->assertForbidden();
    }

    public function test_native_workflow_never_writes_external_receipts_tasks_hours_or_qualification_results(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        $this->submit($u, $x);
        Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'check', 'followup' => 'Not required']);
        foreach (['survey_receipts', 'learning_tasks', 'historical_otj_records', 'qualifications', 'signed_review_records'] as $t) {
            $this->assertDatabaseCount($t, 0);
        }
        $events = DB::table('portal_questionnaire_events')->get()->toJson();
        $this->assertStringNotContainsString('PRIVATE-ANSWER-SENTINEL', $events);
        $this->assertDatabaseCount('portal_questionnaire_events', 5);
    }

    public function test_reads_do_not_mutate_and_no_automatic_external_requests_or_messages_occur(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        [$u,$a,$x,$staff] = $this->assignment();
        $before = DB::table('portal_questionnaire_events')->count();
        $this->actingAs($u)->get('/portal-questionnaires')->assertOk();
        $this->get('/portal-questionnaires/'.$x->id)->assertOk();
        $this->get('/my-next-step')->assertOk();
        $this->assertSame($before, DB::table('portal_questionnaire_events')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_followup_can_be_resolved_reopened_and_audited_without_altering_answers_or_original_check(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        $this->submit($u, $x);
        Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'check', 'followup' => 'Required', 'private_note' => 'ORIGINAL-CHECK']);
        $s = Submission::firstOrFail();
        $payload = $s->answer_payload;
        $checked = $s->checked_at->toIso8601String();
        $this->actingAs($staff)->post('/portal-questionnaires/'.$x->id.'/followup', ['version' => 3, 'action' => 'resolve', 'note' => 'PRIVATE-RESOLUTION-REFERENCE'])->assertRedirect();
        $x->refresh();
        $s->refresh();
        $this->assertNotNull($s->followup_resolved_at);
        $this->assertSame(0, Q::counts($staff)['followup_required']);
        $this->assertSame($payload, $s->answer_payload);
        $this->assertSame($checked, $s->checked_at->toIso8601String());
        $this->assertSame('ORIGINAL-CHECK', $s->private_note);
        $this->assertStringNotContainsString('PRIVATE-RESOLUTION-REFERENCE', DB::table('portal_questionnaire_submissions')->value('followup_history'));
        $this->actingAs($u)->get('/portal-questionnaires/'.$x->id)->assertOk()->assertDontSee('PRIVATE-RESOLUTION-REFERENCE')->assertDontSee('<h2>Follow-up outcome</h2>', false);
        $this->get('/portal-questionnaires?filter=followup')->assertForbidden();
        Q::followup($staff, $staff->auth_version, $x, ['version' => 4, 'action' => 'reopen', 'note' => 'PRIVATE-REOPEN-REASON']);
        $s->refresh();
        $this->assertNull($s->followup_resolved_at);
        $this->assertCount(2, $s->followup_history);
        $this->assertSame(1, Q::counts($staff)['followup_required']);
    }

    public function test_followup_requires_current_checked_required_response_and_refuses_stale_replays(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        $this->invalid(fn () => Q::followup($staff, $staff->auth_version, $x, ['version' => 1, 'action' => 'resolve', 'note' => 'Not submitted']));
        $this->submit($u, $x);
        Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'check', 'followup' => 'Not required']);
        $this->invalid(fn () => Q::followup($staff, $staff->auth_version, $x, ['version' => 3, 'action' => 'resolve', 'note' => 'Not required']));
        [$v,$b,$y,$checker] = $this->assignment();
        $this->submit($v, $y);
        Q::review($checker, $checker->auth_version, $y, ['version' => 2, 'action' => 'check', 'followup' => 'Required']);
        Q::followup($checker, $checker->auth_version, $y, ['version' => 3, 'action' => 'resolve', 'note' => 'Evidence checked']);
        $this->invalid(fn () => Q::followup($checker, $checker->auth_version, $y, ['version' => 3, 'action' => 'resolve', 'note' => 'Replay']));
        $this->assertCount(1, Submission::where('assignment_id', $y->id)->firstOrFail()->followup_history);
    }

    public function test_linked_open_concern_must_close_before_followup_resolution_and_no_case_is_mutated(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        $this->submit($u, $x);
        $c = SupportConcern::create(['apprentice_id' => $a->id, 'raised_by' => $staff->id, 'initial_note' => 'Fictional support case']);
        Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'check', 'followup' => 'Required', 'concern_id' => $c->id]);
        $before = $c->fresh()->toArray();
        $this->invalid(fn () => Q::followup($staff, $staff->auth_version, $x, ['version' => 3, 'action' => 'resolve', 'note' => 'Not yet closed']));
        $this->assertSame($before, $c->fresh()->toArray());
        $c->status = 'Closed';
        $c->save();
        Q::followup($staff, $staff->auth_version, $x, ['version' => 3, 'action' => 'resolve', 'note' => 'Original support record closed and checked']);
        $this->assertNotNull(Submission::firstOrFail()->followup_resolved_at);
    }

    public function test_old_or_foreign_user_cannot_resolve_private_followup(): void
    {
        [$u,$a,$x,$staff] = $this->assignment();
        $this->submit($u, $x);
        Q::review($staff, $staff->auth_version, $x, ['version' => 2, 'action' => 'check', 'followup' => 'Required']);
        $this->actingAs($u)->post('/portal-questionnaires/'.$x->id.'/followup', ['version' => 3, 'action' => 'resolve', 'note' => 'Unpermitted'])->assertForbidden();
        $this->assertNull(Submission::firstOrFail()->followup_resolved_at);
    }
}
