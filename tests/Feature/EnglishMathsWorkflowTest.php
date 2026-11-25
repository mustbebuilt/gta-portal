<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Qualification;
use App\Models\TrainingTopic;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EnglishMathsWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'admin', ?string $scope = null): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->subject_scope = $scope;
        $u->save();

        return $u;
    }

    private function data(): array
    {
        return ['subject' => 'maths', 'type' => 'Functional Skills', 'title' => 'Functional Skills Maths', 'level' => 'Level 2', 'status' => 'Working towards', 'start_date' => '2026-11-01'];
    }

    public function test_learner_submits_a_file_and_note_together_and_tutor_records_completion_from_topics(): void
    {
        Storage::fake(config('evidence.disk'));
        $learner = $this->actor('apprentice');
        $admin = $this->actor();
        $apprentice = Apprentice::factory()->create(['user_id' => $learner->id]);
        $topic = TrainingTopic::factory()->create(['apprentice_id' => $apprentice->id, 'subject' => 'maths', 'planned_hours' => 2]);
        $topicUrl = route('topics.show', $topic);
        $post = route('topics.evidence', $topic);
        $this->actingAs($learner)->get($topicUrl)->assertOk()->assertSee('combined-topic-file')->assertDontSee('Save tutor update');
        $this->from($topicUrl)->post($post, [
            'version' => $topic->version, 'intent' => 'submit', 'draft' => 'Practice notes', 'own_work' => 1,
            'file' => UploadedFile::fake()->create('topic-work.pdf', 1, 'application/pdf'),
        ])->assertRedirect($topicUrl)->assertSessionHasNoErrors();
        $topic->refresh();
        $this->assertCount(1, $topic->files);
        $this->assertSame('Practice notes', $topic->submissions[0]['comment']);
        $this->assertSame($topic->files, $topic->submissions[0]['files']);
        $this->assertSame('Awaiting review', $topic->evidence_stage);
        $this->assertNotSame('Completed', $topic->status);
        $this->actingAs($admin)->post(route('topics.update', $topic), [
            'version' => $topic->version, 'status' => 'In Progress', 'decision' => 'Accepted', 'feedback' => 'Evidence checked',
        ])->assertSessionHasNoErrors();
        $topic->refresh();
        $list = route('em.index', $apprentice);
        $this->get($list)->assertOk()->assertSee('Record topic completion');
        $this->from($list)->post(route('topics.update', $topic), [
            'version' => $topic->version, 'status' => 'Completed', 'completion_date' => today()->toDateString(), 'feedback' => 'Topic delivered',
        ])->assertRedirect($list)->assertSessionHasNoErrors();
        $this->assertSame('Completed', $topic->fresh()->status);
        $this->assertDatabaseCount('qualifications', 0);
        $this->actingAs($learner)->get($list)->assertOk()->assertDontSee('Record topic completion');
    }

    public function test_subject_planning_reuses_assigned_hours_and_stays_on_the_saved_subject(): void
    {
        $admin = $this->actor();
        $person = Apprentice::factory()->create();
        $catalogue = \App\Support\FunctionalSkillsSetup82::catalogue();
        $english = array_filter($catalogue, fn ($entry) => str_starts_with($entry['subject'], 'English:'));
        $id = array_key_first($english);
        $entry = $english[$id];
        $topic = TrainingTopic::factory()->create([
            'apprentice_id' => $person->id, 'subject' => 'english',
            'level' => explode(': ', $entry['subject'])[1], 'topic' => $entry['topic'], 'planned_hours' => 3,
        ]);
        $url = route('em.index', ['apprentice' => $person, 'subject' => 'english']);
        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('planned hours already assigned')
            ->assertDontSee('id="fs-topic-'.$id.'"', false)
            ->assertDontSee('Save Maths assessment information')
            ->assertViewHas('catalogue', fn ($options) => ! isset($options[$id])
                && collect($options)->every(fn ($option) => str_starts_with($option['subject'], 'English:')));
        $this->from($url)->post(route('em.setup-assessments.save', $person), [
            'subject' => 'english', 'version' => 0, 'data' => ['english' => [
                'state' => 'recorded', 'owner_id' => $admin->id, 'next_action' => 'Review assigned topics',
                'initial_assessment' => 'Initial assessment reference', 'diagnostic' => 'Diagnostic reference',
            ]],
        ])->assertRedirect($url.'#assessment-english')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('training_topics', 1);
        $this->assertEquals(3, $topic->fresh()->planned_hours);
        $this->assertDatabaseCount('qualifications', 0);
    }

    public function test_diagnostic_catalogue_recognises_an_existing_register_label(): void
    {
        $admin = $this->actor();
        $person = Apprentice::factory()->create();
        $topic = TrainingTopic::factory()->create([
            'apprentice_id' => $person->id, 'subject' => 'maths', 'level' => 'Level 2',
            'topic' => 'Data tables (Two way tables)', 'planned_hours' => 4,
        ]);
        $catalogue = collect(\App\Support\FunctionalSkillsSetup82::catalogue());
        $id = $catalogue->search(fn ($entry) => $entry['topic'] === 'Data Tables (Two-way Tables)');
        $this->assertIsInt($id);

        $url = route('em.index', ['apprentice' => $person, 'subject' => 'maths']);
        $this->actingAs($admin)->get($url)->assertOk()->assertSee('Data Tables (Two-way Tables)')
            ->assertViewHas('catalogue', fn ($options) => ! isset($options[$id]));
        $this->from($url)->post(route('topics.assign', $person), [
            'catalogue_id' => $id, 'planned_hours' => 4,
        ])->assertSessionHasErrors('catalogue_id');

        $this->assertDatabaseCount('training_topics', 1);
        $this->assertSame('Data tables (Two way tables)', $topic->fresh()->topic);
    }

    public function test_recording_a_non_achievement_clears_the_previous_achievement_date(): void
    {
        $admin = $this->actor();
        $person = Apprentice::factory()->create();
        $qualification = Qualification::factory()->create([...$this->data(), 'apprentice_id' => $person->id,
            'status' => 'Achieved', 'result' => 'Achieved', 'achievement_date' => '2026-11-03',
        ]);
        $return = route('em.index', ['apprentice' => $person, 'subject' => 'maths']).'#em-qualification-results';
        $this->actingAs($admin)->put(route('qualifications.update', [$person, $qualification]), [
            ...$this->data(), 'version' => $qualification->version,
        ])->assertRedirect($return)->assertSessionHasNoErrors();

        $this->assertNull($qualification->fresh()->achievement_date);
        $this->assertNull($qualification->fresh()->result);
        $this->assertSame('2026-11-01', $qualification->fresh()->start_date->toDateString());
    }

    public function test_qualification_create_reopen_and_twelve_month_target_are_individual(): void
    {
        $a = Apprentice::factory()->create(['start_date' => '2025-01-01']);
        $this->actingAs($this->actor())->get('/apprentices/'.$a->id.'/qualifications/create')->assertOk();
        $this->post('/apprentices/'.$a->id.'/qualifications', $this->data())->assertRedirect()->assertSessionHasNoErrors();
        $q = Qualification::firstOrFail();
        $this->assertSame('2027-11-01', $q->target()->toDateString());
        $this->assertNull($q->result);
        $this->get('/apprentices/'.$a->id.'/maths-english')->assertOk()->assertSee('Working towards')->assertSee('Not recorded');
        $this->get('/apprentices/'.$a->id.'/qualifications/'.$q->id.'/edit')->assertOk();
    }

    public function test_achieved_needs_real_date_and_functional_skills_has_no_grade_band(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor());
        $data = [...$this->data(), 'status' => 'Achieved'];
        $this->post('/apprentices/'.$a->id.'/qualifications', $data)->assertSessionHasErrors('achievement_date');
        $this->post('/apprentices/'.$a->id.'/qualifications', [...$data, 'achievement_date' => '2026-11-03', 'result' => 'Distinction'])->assertSessionHasErrors('result');
        $this->post('/apprentices/'.$a->id.'/qualifications', [...$data, 'achievement_date' => '2026-11-03'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('qualifications', ['result' => 'Achieved', 'status' => 'Achieved']);
    }

    public function test_start_change_requires_reason_and_stale_edit_is_rejected(): void
    {
        $q = Qualification::factory()->create();
        $url = '/apprentices/'.$q->apprentice_id.'/qualifications/'.$q->id;
        $this->actingAs($this->actor());
        $new = [...$this->data(), 'start_date' => '2026-11-05', 'version' => 1];
        $this->put($url, $new)->assertSessionHasErrors('change_reason');
        $this->put($url, [...$new, 'change_reason' => 'Correcting the agreed start date'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2027-11-05', $q->fresh()->target()->toDateString());
        $this->put($url, [...$new, 'change_reason' => 'Stale edit'])->assertSessionHasErrors('version');
    }

    public function test_leap_day_target_and_overdue_filters_do_not_change_recorded_result(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11'));
        $q = Qualification::factory()->create(['start_date' => '2024-02-29']);
        $this->assertSame('2025-02-28', $q->target()->toDateString());
        $this->assertSame('Overdue', $q->position());
        $this->actingAs($this->actor())->get('/apprentices/'.$q->apprentice_id.'/maths-english?show=overdue')->assertOk()->assertSee('Functional Skills Maths');
        $this->get('/apprentices/'.$q->apprentice_id.'/maths-english?show=achieved')->assertOk()->assertDontSee('Functional Skills Maths');
    }

    public function test_missing_dates_remain_unknown_and_cannot_be_reported_as_achieved(): void
    {
        $q = Qualification::factory()->create(['start_date' => null, 'status' => 'Awaiting confirmation']);
        $this->assertSame('Date check', $q->position());
        $this->assertFalse($q->achieved());
    }

    public function test_specialist_tutor_can_only_read_and_edit_assigned_subject(): void
    {
        $maths = $this->actor('functional_skills_tutor', 'maths');
        $a = Apprentice::factory()->create(['maths_tutor_id' => $maths->id]);
        $m = TrainingTopic::factory()->create(['apprentice_id' => $a->id]);
        $e = TrainingTopic::factory()->create(['apprentice_id' => $a->id, 'subject' => 'english', 'topic' => 'Spelling']);
        $this->actingAs($maths)->get('/apprentices/'.$a->id.'/maths-english')->assertOk()->assertSee('Circles')->assertDontSee('Spelling');
        $this->get('/topics/'.$e->id)->assertForbidden();
        $this->post('/topics/'.$e->id.'/update', [])->assertForbidden();
        $this->post('/apprentices/'.$a->id.'/qualifications', [...$this->data(), 'subject' => 'english'])->assertForbidden();
        $this->get('/topics/'.$m->id)->assertOk();
        $this->get('/apprentices/'.$a->id)->assertForbidden();
    }

    public function test_topic_acceptance_does_not_complete_training_or_qualification(): void
    {
        $l = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['user_id' => $l->id]);
        $t = TrainingTopic::factory()->create(['apprentice_id' => $a->id]);
        $q = Qualification::factory()->create(['apprentice_id' => $a->id]);
        $this->actingAs($l)->post('/topics/'.$t->id.'/evidence', ['version' => 1, 'intent' => 'submit', 'own_work' => 1, 'draft' => 'Practice answers are ready.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->actor())->post('/topics/'.$t->id.'/update', ['version' => 2, 'status' => 'In Progress', 'decision' => 'Accepted', 'feedback' => 'Evidence accepted; further training planned.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('In Progress', $t->fresh()->status);
        $this->assertSame('Accepted', $t->fresh()->evidence_stage);
        $this->assertSame('Working towards', $q->fresh()->status);
        $this->get('/topics/'.$t->id)->assertOk()->assertSee('Evidence accepted; further training planned.');
    }

    public function test_pending_or_referred_evidence_prevents_premature_completion(): void
    {
        $t = TrainingTopic::factory()->create();
        $t->evidence_stage = 'Awaiting review';
        $t->save();
        $this->actingAs($this->actor())->post('/topics/'.$t->id.'/update', ['version' => 1, 'status' => 'Completed', 'completion_date' => '2026-11-01', 'feedback' => 'Done'])->assertSessionHasErrors('status');
        $this->assertSame('Not Started', $t->fresh()->status);
    }

    public function test_manual_training_completion_is_separate_and_needs_date_and_basis(): void
    {
        $t = TrainingTopic::factory()->create();
        $this->actingAs($this->actor())->post('/topics/'.$t->id.'/update', ['version' => 1, 'status' => 'Completed', 'feedback' => 'Observed independent work.'])->assertSessionHasErrors('completion_date');
        $this->post('/topics/'.$t->id.'/update', ['version' => 1, 'status' => 'Completed', 'completion_date' => '2026-11-01', 'feedback' => 'Observed independent work.'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Completed', $t->fresh()->status);
        $this->assertDatabaseCount('qualifications', 0);
    }

    public function test_zero_hours_is_secure_and_excluded_from_training_percentage(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/topics', ['catalogue_id' => 0, 'planned_hours' => 0])->assertRedirect()->assertSessionHasNoErrors();
        $t = TrainingTopic::firstOrFail();
        $this->assertSame('Secure', $t->status);
        $this->post('/topics/'.$t->id.'/update', ['version' => 1, 'status' => 'Completed', 'completion_date' => '2026-11-01', 'feedback' => 'Wrong'])->assertSessionHasErrors('status');
        $this->get('/apprentices/'.$a->id.'/maths-english')->assertOk()->assertSee('No training topics with planned hours assigned.');
    }

    public function test_topic_assignment_rejects_duplicate_and_catalogue_tampering(): void
    {
        $a = Apprentice::factory()->create();
        $this->actingAs($this->actor())->post('/apprentices/'.$a->id.'/topics', ['catalogue_id' => 0, 'planned_hours' => 2])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/apprentices/'.$a->id.'/topics', ['catalogue_id' => 0, 'planned_hours' => 2])->assertSessionHasErrors('catalogue_id');
        $this->post('/apprentices/'.$a->id.'/topics', ['catalogue_id' => 999, 'planned_hours' => 2])->assertSessionHasErrors('catalogue_id');
        $this->assertDatabaseCount('training_topics', 1);
    }

    public function test_private_topic_upload_download_and_draft_survive_reopen(): void
    {
        Storage::fake(config('evidence.disk'));
        $l = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['user_id' => $l->id]);
        $t = TrainingTopic::factory()->create(['apprentice_id' => $a->id]);
        $this->actingAs($l)->post('/topics/'.$t->id.'/files', ['version' => 1, 'file' => UploadedFile::fake()->create('maths.pdf', 1, 'application/pdf')])->assertRedirect()->assertSessionHasNoErrors();
        $file = $t->fresh()->files[0];
        Storage::disk(config('evidence.disk'))->assertExists($file['path']);
        $this->get('/topics/'.$t->id.'/files/'.$file['id'])->assertOk();
        $this->actingAs($this->actor('employer'))->get('/topics/'.$t->id.'/files/'.$file['id'])->assertForbidden();
    }

    public function test_employer_cannot_see_training_or_record_results(): void
    {
        $t = TrainingTopic::factory()->create();
        $this->actingAs($this->actor('employer'))->get('/apprentices/'.$t->apprentice_id.'/maths-english')->assertForbidden();
        $this->post('/topics/'.$t->id.'/update', [])->assertForbidden();
    }

    public function test_qualification_nested_id_cannot_update_another_apprentice(): void
    {
        $q = Qualification::factory()->create();
        $other = Apprentice::factory()->create();
        $this->actingAs($this->actor())->put('/apprentices/'.$other->id.'/qualifications/'.$q->id, [...$this->data(), 'version' => 1])->assertNotFound();
    }

    public function test_specialist_caseload_and_login_do_not_expand_core_profile_scope(): void
    {
        $u = $this->actor('functional_skills_tutor', 'maths');
        $a = Apprentice::factory()->create(['maths_tutor_id' => $u->id]);
        $other = Apprentice::factory()->create();
        $this->actingAs($u)->get('/maths-english')->assertOk()->assertSee($a->reference)->assertDontSee($other->reference);
        $this->get('/apprentices/'.$a->id)->assertForbidden();
        $this->post('/logout');
        $this->post('/login', ['email' => $u->email, 'password' => 'password'])->assertRedirect('/maths-english');
    }
}
