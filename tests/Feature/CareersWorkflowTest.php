<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\CareerDiscussion;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CareersWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function records(): array
    {
        $adviser = $this->actor('careers_adviser');
        $learner = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['careers_adviser_id' => $adviser->id, 'user_id' => $learner->id]);

        return [$a, $adviser, $learner];
    }

    private function data(): array
    {
        return ['type' => 'progression', 'discussion_date' => '2026-11-01', 'goals' => 'Build diagnostic skills', 'options' => 'Compare further training options', 'advice' => 'Discuss the relevant next qualification', 'outcome' => 'Next step agreed', 'next_step' => 'Compare the course details'];
    }

    public function test_assigned_adviser_saves_and_apprentice_reopens_private_discussion(): void
    {
        [$a,$u,$l] = $this->records();
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/careers', $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $d = CareerDiscussion::firstOrFail();
        $this->assertSame($u->id, $d->adviser_id);
        $this->actingAs($l)->get('/apprentices/'.$a->id.'/careers')->assertOk()->assertSee('Build diagnostic skills');
        $this->actingAs($u)->get('/careers')->assertOk()->assertSee($a->first_name);
    }

    public function test_employer_main_tutor_and_previous_adviser_cannot_read_or_write(): void
    {
        [$a,$u,$l] = $this->records();
        $a->tutor_id = $this->actor('tutor')->id;
        $a->save();
        foreach ([$a->tutor, $this->actor('employer'), $this->actor('careers_adviser')] as $actor) {
            $this->actingAs($actor)->get('/apprentices/'.$a->id.'/careers')->assertForbidden();
            $this->post('/apprentices/'.$a->id.'/careers', $this->data())->assertForbidden();
        }$this->actingAs($u)->post('/apprentices/'.$a->id.'/careers', $this->data());
        $a->careers_adviser_id = $this->actor('careers_adviser')->id;
        $a->save();
        $this->get('/apprentices/'.$a->id.'/careers')->assertForbidden();
    }

    public function test_apprentice_cannot_record_advice_or_read_another_apprentice(): void
    {
        [$a,$u,$l] = $this->records();
        $other = Apprentice::factory()->create();
        $this->actingAs($l)->post('/apprentices/'.$a->id.'/careers', $this->data())->assertForbidden();
        $this->get('/apprentices/'.$other->id.'/careers')->assertForbidden();
    }

    public function test_missing_adviser_and_specialist_referral_are_not_invented(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/careers', $this->data())->assertSessionHasErrors('adviser');
        [$a,$u] = $this->records();
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/careers', [...$this->data(), 'outcome' => 'Specialist referral'])->assertSessionHasErrors('referral');
        $this->assertDatabaseCount('career_discussions', 0);
    }

    public function test_private_smart_action_and_apprentice_followup_require_adviser_confirmation(): void
    {
        [$a,$u,$l] = $this->records();
        $d = CareerDiscussion::factory()->create(['apprentice_id' => $a->id, 'adviser_id' => $u->id]);
        $due = today()->addDays(14)->toDateString();
        $this->actingAs($u)->post('/career-discussions/'.$d->id.'/actions', ['version' => 1, 'title' => 'Compare two course options', 'owner' => 'Apprentice', 'due_date' => $due, 'criteria' => 'Record entry requirements and dates for both options.', 'support' => 'Adviser appointment', 'observable' => 1, 'achievable' => 1, 'agreed' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $id = $d->fresh()->actions[0]['id'];
        $data = ['version' => 2, 'date' => today()->toDateString(), 'note' => 'Compared both options.', 'impact' => 'Next step taken', 'action_id' => $id, 'decision' => 'Ready for adviser check'];
        $this->actingAs($l)->post('/career-discussions/'.$d->id.'/followups', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Ready for adviser check', $d->fresh()->actions[0]['status']);
        $this->post('/career-discussions/'.$d->id.'/followups', [...$data, 'version' => 3, 'decision' => 'Confirm completed'])->assertForbidden();
        $this->actingAs($u)->post('/career-discussions/'.$d->id.'/followups', [...$data, 'version' => 3, 'decision' => 'Confirm completed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Completed', $d->fresh()->actions[0]['status']);
        $this->assertDatabaseCount('smart_actions', 0);
        $this->get('/apprentices/'.$a->id.'/careers')->assertOk()->assertSee('Compared both options.');
    }

    public function test_action_needs_smart_confirmation_and_stale_save_is_rejected(): void
    {
        [$a,$u] = $this->records();
        $d = CareerDiscussion::factory()->create(['apprentice_id' => $a->id, 'adviser_id' => $u->id]);
        $this->actingAs($u)->post('/career-discussions/'.$d->id.'/actions', ['version' => 1, 'title' => 'Vague idea'])->assertSessionHasErrors(['criteria', 'agreed', 'achievable']);
        $this->post('/career-discussions/'.$d->id.'/followups', ['version' => 9, 'date' => today()->toDateString(), 'note' => 'Check', 'impact' => 'No progress yet', 'decision' => 'Update only'])->assertSessionHasErrors('version');
    }

    public function test_followup_does_not_allow_an_action_from_another_discussion(): void
    {
        [$a,$u] = $this->records();
        $d = CareerDiscussion::factory()->create(['apprentice_id' => $a->id, 'adviser_id' => $u->id]);
        $this->actingAs($u)->post('/career-discussions/'.$d->id.'/followups', ['version' => 1, 'date' => today()->toDateString(), 'note' => 'Check', 'impact' => 'No progress yet', 'decision' => 'Confirm completed', 'action_id' => 'unknown-id'])->assertSessionHasErrors('action_id');
    }

    public function test_resources_are_available_to_staff_and_apprentices_but_not_employers(): void
    {
        $this->actingAs($this->actor('tutor'))->get('/careers/resources')->assertOk()->assertSee('IMI Autocity');
        $this->actingAs($this->actor('employer'))->get('/careers/resources')->assertForbidden();
    }

    public function test_private_text_is_escaped_and_does_not_appear_in_shared_action_plan(): void
    {
        [$a,$u,$l] = $this->records();
        $this->actingAs($u)->post('/apprentices/'.$a->id.'/careers', [...$this->data(), 'goals' => '<script>private()</script>'])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/apprentices/'.$a->id.'/careers')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>private()</script>', false);
        $this->actingAs($l)->get('/apprentices/'.$a->id.'/actions')->assertOk()->assertDontSee('private()');
    }
}
