<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ProgrammeOutcome;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RetentionEntryRequirementsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_subject_requirements_flow_from_the_apprentice_page_to_retention(): void
    {
        $apprentice = Apprentice::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('em.index', $apprentice))->assertOk()
            ->assertSee('Maths required at entry?')->assertSee('English required at entry?');
        $save = route('em.requirements.save', $apprentice);
        $this->post($save, ['version' => 0, 'maths' => 'Yes', 'english' => 'No'])
            ->assertRedirect(route('em.index', $apprentice))->assertSessionHasNoErrors();

        foreach (['maths' => 'Yes', 'english' => 'No'] as $subject => $answer) {
            $this->get(route('retention.report', ['factor' => $subject]))->assertOk()
                ->assertViewHas('groups', fn ($groups) => $groups->has($answer));
        }
        $this->post($save, ['version' => 0, 'maths' => 'No', 'english' => 'Yes'])->assertSessionHasErrors('version');
        $outcome = ProgrammeOutcome::where('apprentice_id', $apprentice->id)->firstOrFail();
        $this->post(route('retention.save', $apprentice), [
            'version' => $outcome->version,
            'context' => ['status' => 'Active', 'sourceRef' => 'Recorded enrolment'],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Yes', $outcome->fresh()->context['maths']);
        $this->assertSame('No', $outcome->fresh()->context['english']);
        $this->actingAs(User::factory()->create(['role' => 'tutor']))->post($save, [
            'version' => $outcome->fresh()->version, 'maths' => 'No', 'english' => 'Yes',
        ])->assertForbidden();
    }

    #[TestWith(['maths', 'english'])]
    #[TestWith(['english', 'maths'])]
    public function test_assigned_subject_tutor_can_add_training_after_programme_start(string $subject, string $other): void
    {
        $this->travelTo(now()->setDate(2026, 11, 21));
        $tutor = User::factory()->create(['role' => 'functional_skills_tutor', 'subject_scope' => $subject]);
        $apprentice = Apprentice::factory()->create([
            'start_date' => '2026-09-01',
            $subject.'_tutor_id' => $tutor->id,
        ]);
        $outcome = ProgrammeOutcome::create([
            'apprentice_id' => $apprentice->id,
            'context' => [$other => 'No'],
        ]);
        $this->actingAs($tutor)->get(route('em.caseload'))->assertOk()->assertSee($apprentice->reference);
        $this->get(route('em.index', $apprentice))->assertOk()
            ->assertSee('Add '.ucfirst($subject).' training')
            ->assertSee(ucfirst($subject).' required at entry?')
            ->assertDontSee('Add '.ucfirst($other).' training')
            ->assertDontSee(ucfirst($other).' required at entry?');
        $this->get(route('qualifications.create', [$apprentice, 'subject' => $subject]))
            ->assertOk()->assertSee('Subject training start date')
            ->assertViewHas('qualification', fn ($record) =>
                $record->subject === $subject && $record->title === 'Functional Skills '.ucfirst($subject)
                && $record->status === 'Working towards' && $record->start_date === null);
        $this->get(route('qualifications.create', [$apprentice, 'subject' => $other]))->assertForbidden();

        $this->post(route('qualifications.store', $apprentice), [
            'subject' => $subject, 'type' => 'Functional Skills', 'title' => 'Functional Skills '.ucfirst($subject),
            'level' => 'Level 2', 'status' => 'Working towards', 'start_date' => '2026-11-03',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $qualification = Qualification::where('apprentice_id', $apprentice->id)->firstOrFail();
        $this->assertSame('2026-11-03', $qualification->start_date->toDateString());
        $this->assertSame('2027-11-03', $qualification->target()->toDateString());
        $this->assertSame('2026-09-01', $apprentice->fresh()->start_date->toDateString());
        $this->assertArrayNotHasKey($subject, $outcome->fresh()->context);

        $save = route('em.requirements.save', $apprentice);
        $this->post($save, ['version' => $outcome->version, $other => 'Yes'])->assertForbidden();
        $this->post($save, ['version' => $outcome->version, $subject => 'Yes'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Yes', $outcome->fresh()->context[$subject]);
        $this->assertSame('No', $outcome->fresh()->context[$other]);
    }


    public function test_retention_tracks_subject_starts_and_excludes_training_after_withdrawal(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 21));
        $learner = fn () => Apprentice::factory()->create([
            'start_date' => '2026-09-01', 'planned_end_date' => '2027-09-01',
        ]);
        $ongoing = $learner();
        $withdrawn = $learner();
        $unknown = $learner();
        foreach ([$ongoing, $withdrawn] as $apprentice) {
            $context = ['maths' => 'Yes', 'english' => 'Yes', 'status' => 'Active', 'sourceRef' => 'Test enrolment'];
            if ($apprentice->is($withdrawn)) {
                $context = array_replace($context, ['status' => 'Withdrawn', 'endDate' => '2026-10-15']);
            }
            ProgrammeOutcome::create(['apprentice_id' => $apprentice->id, 'context' => $context]);
        }

        $training = fn ($apprentice, $subject, $start, array $extra = []) => Qualification::factory()->create($extra + [
            'apprentice_id' => $apprentice->id, 'subject' => $subject, 'start_date' => $start,
        ]);
        $training($ongoing, 'maths', '2026-08-15');
        $training($ongoing, 'maths', '2026-09-01', ['status' => 'Already held']);
        $training($ongoing, 'maths', '2026-10-01', ['status' => 'Achieved']);
        $training($ongoing, 'maths', '2026-11-01');
        $training($ongoing, 'english', '2026-09-01');
        $training($withdrawn, 'maths', '2026-10-16');
        $training($withdrawn, 'english', '2026-10-15');
        $training($unknown, 'maths', '2026-09-01');

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('retention.report'))->assertOk()
            ->assertSee('Maths and English training starts')->assertSee('30 days from apprenticeship start');
        $summary = $response->viewData('skillsStarts');
        $this->assertEquals([
            'required' => 2, 'started' => 1, 'no_start' => 1, 'median_days' => 30,
            'timed_starts' => 1, 'early_leavers_without_start' => 1,
        ], $summary['maths']);
        $this->assertEquals([
            'required' => 2, 'started' => 2, 'no_start' => 0, 'median_days' => 22,
            'timed_starts' => 2, 'early_leavers_without_start' => 0,
        ], $summary['english']);
        $rows = $response->viewData('rows')->getCollection();
        $this->assertSame('2026-10-01', $rows->firstWhere('apprentice.id', $ongoing->id)['functional_skills']['maths']['start']);
        $this->assertNull($rows->firstWhere('apprentice.id', $withdrawn->id)['functional_skills']['maths']['start']);
    }

}
