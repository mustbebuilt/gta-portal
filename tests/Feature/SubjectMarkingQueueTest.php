<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Qualification;
use App\Models\TrainingTopic;
use App\Models\User;
use App\Support\SubjectMarkingQueue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SubjectMarkingQueueTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function topic(Apprentice $apprentice, string $subject, string $name): TrainingTopic
    {
        $topic = TrainingTopic::factory()->create([
            'apprentice_id' => $apprentice->id, 'subject' => $subject, 'topic' => $name,
        ]);
        $topic->forceFill([
            'evidence_stage' => 'Awaiting review', 'draft' => 'FICTIONAL PRIVATE WORK',
            'submissions' => [['version' => 1, 'comment' => 'FICTIONAL PRIVATE WORK', 'submitted_at' => now()->toIso8601String(), 'decision' => null]],
        ])->save();

        return $topic;
    }

    public function test_marking_menu_and_caseload_open_only_permitted_submissions_and_results(): void
    {
        $tutor = User::factory()->create(['role' => 'functional_skills_tutor', 'subject_scope' => 'both']);
        $admin = User::factory()->create(['role' => 'admin']);
        $apprentice = Apprentice::factory()->create(['maths_tutor_id' => $tutor->id]);
        $other = Apprentice::factory()->create();
        $maths = $this->topic($apprentice, 'maths', 'Fictional assigned maths');
        $english = $this->topic($apprentice, 'english', 'Fictional unassigned English');
        $hidden = $this->topic($other, 'maths', 'Fictional unassigned maths');
        $qualification = Qualification::factory()->create(['apprentice_id' => $apprentice->id, 'subject' => 'maths', 'start_date' => today()->subYear()->subDay()]);
        $this->actingAs($tutor)->get(route('marking.index'))->assertOk()
            ->assertSee('Work to mark')->assertSee($maths->topic)->assertDontSee($english->topic)
            ->assertDontSee($hidden->topic)->assertDontSee('FICTIONAL PRIVATE WORK')->assertDontSee('Vocational tasks');
        $this->get(SubjectMarkingQueue::indexUrl(['subject' => 'english']))->assertOk()->assertDontSee($english->topic);
        $this->get(route('em.caseload'))->assertOk()->assertSee('Review submitted evidence')
            ->assertSee(route('topics.show', $maths))->assertSee(route('qualifications.edit', [$apprentice, $qualification]));
        $this->get(route('topics.show', $english))->assertForbidden();
        $this->get(route('marking.index', ['kind' => 'vocational']))->assertForbidden();
        $this->assertDatabaseCount('learning_events', 0);
        $this->actingAs($admin)->get(route('marking.index'))->assertOk()->assertSee('Maths and English evidence');
        $this->get(SubjectMarkingQueue::indexUrl())->assertOk()->assertSee($english->topic)->assertSee($hidden->topic);
        $this->actingAs(User::factory()->create(['role' => 'employer']))->get(SubjectMarkingQueue::indexUrl())->assertForbidden();
    }

    public function test_save_and_next_preserves_filters_and_does_not_complete_training(): void
    {
        $tutor = User::factory()->create(['role' => 'functional_skills_tutor', 'subject_scope' => 'maths']);
        $person = Apprentice::factory()->create(['maths_tutor_id' => $tutor->id, 'last_name' => 'FictionalFiltered']);
        $outside = Apprentice::factory()->create(['maths_tutor_id' => $tutor->id, 'last_name' => 'AnotherFamily']);
        $older = $this->topic($person, 'maths', 'Check the missing submitted version');
        $older->forceFill(['submissions' => [], 'updated_at' => now()->subHour()])->save();
        $first = $this->topic($person, 'maths', 'Fractions');
        $second = $this->topic($person, 'maths', 'Circles');
        $first->forceFill(['draft' => 'LATER EDIT OUTSIDE THE SUBMITTED VERSION'])->save();
        $this->topic($outside, 'maths', 'Outside the search');
        $filters = ['subject' => 'maths', 'search' => 'FictionalFiltered'];
        $review = SubjectMarkingQueue::url($first->id, $filters);
        $this->actingAs($tutor)->get(SubjectMarkingQueue::indexUrl($filters))->assertOk()
            ->assertSee($review)->assertDontSee('Outside the search')->assertViewHas('nextTopic', $first->id);
        $this->get(SubjectMarkingQueue::url($older->id, $filters))->assertOk()
            ->assertSee('The submitted version needs checking')->assertDontSee('Save decision and open next');
        $this->get($review)->assertOk()->assertSee('Save decision and open next')->assertSee('Back to Work to mark')
            ->assertSee('Feedback template')->assertSee('Missing evidence')->assertSee('FICTIONAL PRIVATE WORK')->assertDontSee('LATER EDIT OUTSIDE THE SUBMITTED VERSION')
            ->assertDontSee('name="status"', false)->assertDontSee('name="completion_date"', false);
        $data = ['version' => $first->version, 'evidence_only' => 1, 'feedback' => 'Fictional checked feedback',
            'after_save' => 'next', 'queue' => $filters];
        $this->from($review)->post(route('topics.update', $first), $data)->assertSessionHasErrors('decision');
        $this->from($review)->post(route('topics.update', $first), [
            ...$data, 'decision' => 'Referred', 'feedback' => 'Explain [[ADD DETAIL]] before resubmitting.',
        ])->assertSessionHasErrors('feedback');
        $this->assertSame('Awaiting review', $first->fresh()->evidence_stage);
        $this->from($review)->post(route('topics.update', $first), $data + ['decision' => 'Accepted', 'status' => 'Completed'])
            ->assertSessionHasErrors('status');
        $this->post(route('topics.update', $first), $data + ['decision' => 'Accepted'])
            ->assertRedirect(SubjectMarkingQueue::url($second->id, $filters))->assertSessionHasNoErrors();
        $first->refresh();
        $this->assertSame('Accepted', $first->evidence_stage);
        $this->assertSame('Not Started', $first->status);
        $this->assertNull($first->completion_date);
        $this->assertEmpty($first->history);
        $this->assertSame('Fictional checked feedback', $first->submissions[0]['decision']['feedback']);
        $this->from($review)->post(route('topics.update', $first), $data + ['decision' => 'Referred'])
            ->assertSessionHasErrors('version');
        $this->post(route('topics.update', $second), $data + ['decision' => 'Referred'])
            ->assertRedirect(SubjectMarkingQueue::indexUrl($filters))->assertSessionHasNoErrors();
        $this->get(SubjectMarkingQueue::indexUrl($filters))->assertOk()->assertSee($older->topic)
            ->assertDontSee('Start evidence review');
        $this->assertSame('Awaiting review', $older->fresh()->evidence_stage);
    }
}
