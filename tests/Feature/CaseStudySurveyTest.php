<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\CaseStudy;
use App\Models\SupportConcern;
use App\Models\SurveyReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CaseStudySurveyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function fields(): array
    {
        return ['title' => 'Fictional travel barrier resolved', 'category' => 'Attendance and engagement', 'area' => 'Participation and development', 'scope' => 'Individual', 'challenge' => 'Travel disrupted attendance', 'startingPoint' => 'A recorded travel barrier', 'response' => 'Helped compare travel options', 'adaptation' => 'Reviewed the route after two sessions', 'measure' => 'Attendance at the next two sessions', 'before' => 'Both sessions missed', 'beforeDate' => '2026-10-01', 'after' => 'Both sessions attended', 'afterDate' => '2026-10-20', 'evidence' => 'Anonymised register evidence REF-001', 'evidenceBasis' => 'Recorded source checked', 'voice' => 'Learner reported a more reliable journey', 'voiceBasis' => 'Learner account', 'outcome' => 'Retained in learning', 'sustained' => 'Sustained at follow-up', 'followup' => 'Continue routine attendance checks', 'learning' => 'Earlier discussion helped identify the barrier', 'limitations' => 'One example; longer-term outcomes not yet established', 'typicality' => 'Single example'];
    }

    public function test_non_apprentice_creates_case_but_apprentice_is_excluded(): void
    {
        $u = $this->actor('employer');
        $this->actingAs($u)->post('/case-studies', ['fields' => $this->fields(), 'privacy' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $row = CaseStudy::firstOrFail();
        $this->assertSame($u->id, $row->author_id);
        $this->actingAs($this->actor('apprentice'))->get('/case-studies')->assertForbidden();
        $this->post('/case-studies', ['fields' => $this->fields()])->assertForbidden();
    }

    public function test_only_author_edits_and_other_staff_cannot_read_the_case(): void
    {
        $author = $this->actor('tutor');
        $row = CaseStudy::factory()->create(['author_id' => $author->id]);
        $this->actingAs($this->actor('tutor'))->get('/case-studies/'.$row->id)->assertForbidden();
        $this->actingAs($this->actor())->post('/case-studies/'.$row->id, ['version' => 1, 'fields' => $this->fields()])->assertForbidden();
        $this->actingAs($author)->get('/case-studies/'.$row->id)->assertOk();
    }

    public function test_independent_review_requires_checks_and_current_approved_copy_enters_inspection(): void
    {
        $author = $this->actor('tutor');
        $this->actingAs($author)->post('/case-studies', ['fields' => $this->fields(), 'privacy' => 1]);
        $row = CaseStudy::firstOrFail();
        $this->post('/case-studies/'.$row->id.'/transition', ['version' => 1, 'action' => 'submit'])->assertRedirect()->assertSessionHasNoErrors();
        $reviewer = $this->actor();
        $this->actingAs($reviewer)->get('/reports/inspection')->assertOk()->assertDontSee('Fictional travel barrier resolved');
        $this->post('/case-studies/'.$row->id.'/transition', ['version' => 2, 'action' => 'approve', 'note' => 'Checked anonymised source references'])->assertSessionHasErrors(['sources', 'privacy', 'balance']);
        $this->post('/case-studies/'.$row->id.'/transition', ['version' => 2, 'action' => 'approve', 'note' => 'Checked references, anonymisation and limitations', 'sources' => 1, 'privacy' => 1, 'balance' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($row->fresh()->currentApprovedCopy());
        $this->get('/reports/inspection')->assertOk()->assertSee('Fictional travel barrier resolved');
    }

    public function test_admin_author_cannot_approve_own_case_and_missing_evidence_blocks_submission(): void
    {
        $u = $this->actor();
        $this->actingAs($u)->post('/case-studies', ['fields' => ['title' => 'Incomplete fictional case', 'category' => 'Other']]);
        $row = CaseStudy::firstOrFail();
        $this->post('/case-studies/'.$row->id.'/transition', ['version' => 1, 'action' => 'submit'])->assertSessionHasErrors('fields');
        $this->post('/case-studies/'.$row->id, ['version' => 1, 'fields' => $this->fields(), 'privacy' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/case-studies/'.$row->id.'/transition', ['version' => 2, 'action' => 'submit'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/case-studies/'.$row->id.'/transition', ['version' => 3, 'action' => 'approve', 'note' => 'Self approval', 'sources' => 1, 'privacy' => 1, 'balance' => 1])->assertSessionHasErrors('action');
    }

    public function test_submitted_case_locks_draft_and_stale_version_is_rejected(): void
    {
        $u = $this->actor('tutor');
        $this->actingAs($u)->post('/case-studies', ['fields' => $this->fields(), 'privacy' => 1]);
        $row = CaseStudy::firstOrFail();
        $this->post('/case-studies/'.$row->id.'/transition', ['version' => 1, 'action' => 'submit']);
        $this->post('/case-studies/'.$row->id, ['version' => 2, 'fields' => $this->fields()])->assertSessionHasErrors('fields');
        $this->post('/case-studies/'.$row->id.'/transition', ['version' => 1, 'action' => 'withdraw'])->assertSessionHasErrors('version');
    }

    public function test_unresolved_outcome_needs_followup_and_no_wider_pattern_without_reference(): void
    {
        $u = $this->actor('tutor');
        $f = [...$this->fields(), 'outcome' => 'Outcome not yet known', 'typicality' => 'Comparable cases evidenced'];
        $this->actingAs($u)->post('/case-studies', ['fields' => $f, 'privacy' => 1]);
        $row = CaseStudy::firstOrFail();
        $this->post('/case-studies/'.$row->id.'/transition', ['version' => 1, 'action' => 'submit'])->assertSessionHasErrors('fields');
    }

    public function test_survey_receipt_and_inclusion_check_are_separate_and_store_no_answers(): void
    {
        $a = Apprentice::factory()->create();
        $u = $this->actor('inclusion_member');
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/surveys', ['kind' => 'sixweek', 'submitted_date' => today()->toDateString(), 'answers' => ['health' => 'DO NOT STORE ANSWERS']])->assertRedirect()->assertSessionHasNoErrors();
        $r = SurveyReceipt::firstOrFail();
        $this->assertNull($r->currentCheck());
        $this->assertStringNotContainsString('DO NOT STORE ANSWERS', $r->toJson());
        $this->post('/survey-receipts/'.$r->id.'/check', ['version' => 1, 'checked_date' => today()->toDateString(), 'followup' => 'Not required'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Not required', $r->fresh()->currentCheck()['followup']);
        $this->get('/apprentices/'.$a->id.'/surveys')->assertOk()->assertSee('Checked · no follow-up required');
    }

    public function test_survey_duplicate_wrong_concern_and_check_before_submission_are_rejected(): void
    {
        $a = Apprentice::factory()->create();
        $other = SupportConcern::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/surveys', ['kind' => 'induction', 'submitted_date' => today()->toDateString()]);
        $r = SurveyReceipt::firstOrFail();
        $this->post('/apprentices/'.$a->id.'/surveys', ['kind' => 'induction', 'submitted_date' => today()->toDateString()])->assertSessionHasErrors('submitted_date');
        $this->post('/survey-receipts/'.$r->id.'/check', ['version' => 1, 'checked_date' => today()->toDateString(), 'followup' => 'Required', 'concern_id' => $other->id])->assertSessionHasErrors('concern_id');
        $this->post('/survey-receipts/'.$r->id.'/check', ['version' => 1, 'checked_date' => today()->subDay()->toDateString(), 'followup' => 'Not required'])->assertSessionHasErrors('checked_date');
    }

    public function test_corrected_survey_date_does_not_reuse_an_old_check_even_when_date_is_changed_back(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/surveys', ['kind' => 'sixweek', 'submitted_date' => today()->toDateString()]);
        $r = SurveyReceipt::firstOrFail();
        $this->post('/survey-receipts/'.$r->id.'/check', ['version' => 1, 'checked_date' => today()->toDateString(), 'followup' => 'Not required']);
        $this->post('/apprentices/'.$a->id.'/surveys/'.$r->id, ['version' => 2, 'kind' => 'sixweek', 'submitted_date' => today()->subDay()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($r->fresh()->currentCheck());
        $this->post('/apprentices/'.$a->id.'/surveys/'.$r->id, ['version' => 3, 'kind' => 'sixweek', 'submitted_date' => today()->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($r->fresh()->currentCheck());
    }

    public function test_tutor_apprentice_and_employer_cannot_access_survey_tracking(): void
    {
        $a = Apprentice::factory()->create();
        foreach (['tutor', 'apprentice', 'employer'] as $role) {
            $this->actingAs($this->actor($role))->get('/apprentices/'.$a->id.'/surveys')->assertForbidden();
            $this->post('/apprentices/'.$a->id.'/surveys', ['kind' => 'sixweek', 'submitted_date' => today()->toDateString()])->assertForbidden();
        }
    }
}
