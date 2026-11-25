<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ProgrammeOutcome;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ApprenticeCloseDownTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_leaving_is_recorded_once_from_the_programme_outcome_screen(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 21));
        $person = Apprentice::factory()->create([
            'start_date' => '2025-09-01', 'planned_end_date' => '2027-09-01',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $outcome = ProgrammeOutcome::create(['apprentice_id' => $person->id, 'context' => [
            'status' => 'Active', 'age' => '19–24', 'maths' => 'Yes',
        ]]);
        $page = route('apprentices.close-down', $person);
        $save = route('retention.save', $person);
        $input = ['workflow' => 'exit', 'version' => $outcome->version, 'context' => [
            'status' => 'Withdrawn', 'endDate' => '2026-11-20', 'sourceRef' => 'Fictional case record',
            'exitEmployment' => 'Unknown', 'reason' => 'Employment ended', 'reasonBasis' => 'Staff record',
        ]];

        $this->actingAs($tutor)->get($page)->assertForbidden();
        $this->post($save, $input)->assertForbidden();
        $this->actingAs($admin)->get($page)->assertOk()->assertSee('Record a withdrawal or transfer')
            ->assertDontSee('id="leaving-end"', false)->assertDontSee('id="epa-assessment"', false);
        $leaving = route('apprentices.close-down', ['apprentice'=>$person, 'entry'=>'leaving']);
        $this->get($leaving)->assertOk()->assertSee('id="leaving-end"', false)->assertDontSee('id="epa-assessment"', false);
        $this->from($leaving)->post($save, [...$input, 'context'=>[...$input['context'], 'sourceRef'=>'']])
            ->assertRedirect($leaving)->assertSessionHasErrors('context.sourceRef');
        $this->get($leaving)->assertOk()->assertSee('id="leaving-end"', false);
        $this->from($page)->post($save, $input)->assertRedirect($page)->assertSessionHasNoErrors();
        $this->get($page)->assertOk()->assertSee('Withdrawn')->assertSee('2026-11-20');
        $this->get(route('achievers-leavers.index', ['from' => '2026-11-01', 'to' => '2026-11-21']))
            ->assertOk()->assertSee($person->reference);
        $this->assertSame('19–24', $outcome->fresh()->context['age']);
        $this->assertSame('Yes', $outcome->fresh()->context['maths']);
        $this->assertDatabaseCount('destination_milestones', 3);
        $this->from($page)->post($save, $input)->assertSessionHasErrors('version');
        $this->assertCount(1, $outcome->fresh()->history);
    }

    #[TestWith(['Pass', 1])]
    #[TestWith(['Merit', 2])]
    #[TestWith(['Distinction', 3])]
    public function test_confirmed_grade_archives_the_programme_and_feeds_achievers(string $grade, int $number): void
    {
        $this->travelTo(now()->setDate(2026, 11, 21));
        Storage::fake(config('evidence.disk'));
        $apprentice = Apprentice::factory()->create(['start_date' => '2025-09-01']);
        $admin = User::factory()->create(['role' => 'admin']);
        $url = route('apprentices.close-down', $apprentice);
        $this->actingAs($admin)->get(route('apprentices.show', $apprentice))->assertOk()->assertSee('Programme outcome');
        $epa = route('apprentices.close-down', ['apprentice'=>$apprentice, 'entry'=>'epa']);
        $this->get($epa)->assertOk()->assertSee('Record EPA attempt 1')->assertDontSee('id="leaving-end"', false);
        $this->post($url, [
            'version' => 0, 'result' => 'Awaiting result', 'assessment_date' => '2026-11-01', 'action' => 'record',
        ])->assertRedirect($url)->assertSessionHasNoErrors();
        $outcome = ProgrammeOutcome::where('apprentice_id', $apprentice->id)->firstOrFail();
        $this->assertArrayNotHasKey('status', $outcome->context);
        $this->get($epa)->assertOk()->assertSee('Update EPA attempt 1')->assertSee('History · EPA attempts (1)');
        $this->get(route('apprentices.archive'))->assertOk()->assertDontSee($apprentice->reference);
        $this->get(route('apprentices.index'))->assertOk()->assertSee($apprentice->reference);
        $report = route('achievers-leavers.index', ['from' => '2026-11-01', 'to' => '2026-11-21']);
        $this->get($report)->assertOk()->assertDontSee($apprentice->reference);

        for ($attempt = 1; $attempt < $number; $attempt++) {
            $this->post($url, [
                'version' => $outcome->fresh()->version, 'result' => 'Unsuccessful',
                'assessment_date' => "2026-11-0{$attempt}", 'received_date' => "2026-11-0{$attempt}",
                'action' => 'record',
            ])->assertRedirect($url)->assertSessionHasNoErrors();
        }
        $this->assertArrayNotHasKey('status', $outcome->fresh()->context);
        $this->get($report)->assertOk()->assertDontSee($apprentice->reference);

        $success = [
            'version' => $outcome->fresh()->version, 'result' => $grade, 'action' => 'archive', 'exit_employment' => 'Employed',
            'assessment_date' => '2026-11-05', 'received_date' => '2026-11-15',
            'achievement_date' => '2026-11-08', 'end_date' => '2026-11-06',
        ];
        $this->post($url, array_replace(array_diff_key($success, ['exit_employment' => true]), ['action' => 'record']))->assertSessionHasErrors('result');
        $this->post($url, $success)->assertSessionHasErrors('completion_pack');
        $this->post($url, $success + [
            'completion_pack' => UploadedFile::fake()->createWithContent('completion.pdf', 'This is not a PDF'),
        ])->assertSessionHasErrors('completion_pack');
        $this->assertArrayNotHasKey('epa_closure', $outcome->fresh()->context);
        $success['completion_pack'] = UploadedFile::fake()->createWithContent('completion.pdf', "%PDF-1.4\nFictional completion pack");
        $this->post($url, array_diff_key($success, ['exit_employment' => true]))->assertSessionHasErrors('exit_employment');
        $this->post($url, $success)->assertRedirect($url)->assertSessionHasNoErrors();
        $context = $outcome->fresh()->context;
        $this->assertSame('Completed', $context['status']);
        $this->assertSame('Achieved', $context['achievement']);
        $this->assertSame('Employed', $context['exitEmployment']);
        $this->assertSame('2026-11-08', $context['achievementDate']);
        $this->assertSame($number, $context['epa_closure']['attempt']);
        $entry = $context['epa_closure']['completion_pack'];
        Storage::disk($entry['disk'])->assertExists($entry['path']);
        $download = route('apprentices.close-down.completion-pack', $apprentice);
        $this->get($download)->assertOk()->assertDownload('completion.pdf');
        $this->assertCount($number, $context['epa_attempts']);
        $this->assertSame('2026-11-15', $context['epa_attempts'][$number - 1]['received_date']);
        $this->assertDatabaseCount('destination_milestones', 3);
        $this->get($url)->assertOk()->assertSee('Completed and archived')->assertSee("on EPA attempt {$number}")->assertSee('Download completion pack (PDF)')->assertDontSee('EPA evidence reference');
        $this->get(route('apprentices.index'))->assertOk()->assertDontSee($apprentice->reference);
        $this->get(route('apprentices.archive'))->assertOk()->assertSee($apprentice->reference);
        $this->get($report)->assertOk()->assertViewHas('achievers', fn ($rows) =>
            $rows->count() === 1 && $rows->first()['grade'] === $grade && $rows->first()['attempt'] === $number && $rows->first()['exit_employment'] === 'Employed');
        $retention = $this->get(route('retention.report'))->assertOk()->assertSee('View archived completion');
        $row = $retention->viewData('rows')->getCollection()->firstWhere('apprentice.id', $apprentice->id);
        $this->assertSame('2026-11-08', $row['achievement_date']);
        $this->assertSame($grade, $row['epa_grade']);
        $this->assertSame($number, $row['epa_attempt']);
        $this->assertSame('Employed', $row['exit_employment']);
        $this->get(route('achievers-leavers.index', ['from' => '2026-11-15', 'to' => '2026-11-21']))
            ->assertOk()->assertDontSee($apprentice->reference);
        $this->post($url, $success)->assertSessionHasErrors('version');
        $this->post($url, array_replace($success, ['version' => $outcome->fresh()->version]))->assertSessionHasErrors('result');

        $generic = route('retention.save', $apprentice);
        $this->post($generic, [
            'version' => $outcome->fresh()->version, 'context' => ['status' => 'Active', 'sourceRef' => 'New entry'],
        ])->assertSessionHasErrors('context.status');
        $this->post($generic, [
            'version' => $outcome->fresh()->version, 'context' => [
                'status' => 'Completed', 'achievement' => 'Achieved', 'endDate' => '2026-11-06',
                'achievementDate' => '2026-11-08', 'sourceRef' => 'Completion pack · completion.pdf', 'voice' => 'Recorded',
            ],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Employed', $outcome->fresh()->context['exitEmployment']);
        $this->assertSame($context['epa_attempts'], $outcome->fresh()->context['epa_attempts']);
        $this->assertSame($context['epa_closure'], $outcome->fresh()->context['epa_closure']);
    }

    public function test_close_down_requires_authority_and_dated_evidence(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 21));
        $apprentice = Apprentice::factory()->create(['start_date' => '2025-09-01']);
        $url = route('apprentices.close-down', $apprentice);
        $tutor = User::factory()->create(['role' => 'tutor']);
        $this->actingAs($tutor)->get($url)->assertForbidden();
        $this->get(route('apprentices.archive'))->assertForbidden();
        $this->post($url)->assertForbidden();
        $this->get(route('apprentices.close-down.completion-pack', $apprentice))->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'ceo']));
        $badResult = [
            'version' => 0, 'result' => 'Pass', 'action' => 'archive',
            'assessment_date' => '2026-11-10', 'received_date' => '2026-11-12',
            'achievement_date' => '2026-11-13', 'end_date' => '2026-11-10',
        ];
        $this->post($url, $badResult)->assertSessionHasErrors(['achievement_date', 'completion_pack']);
        $this->assertDatabaseCount('programme_outcomes', 0);
        $generic = route('retention.save', $apprentice);
        $this->post($generic, [
            'version' => 0, 'context' => ['status' => 'Completed', 'endDate' => '2026-11-10', 'sourceRef' => 'Fictional source'],
        ])->assertSessionHasErrors('context.status');

        $outcome = ProgrammeOutcome::create(['apprentice_id' => $apprentice->id, 'context' => [
            'status' => 'Withdrawn', 'endDate' => '2026-11-08', 'sourceRef' => 'Fictional exit',
        ]]);
        $this->post($url, [
            'version' => $outcome->version, 'result' => 'Awaiting result', 'assessment_date' => '2026-11-01', 'action' => 'record',
        ])->assertSessionHasErrors('result');
        $this->assertSame('Withdrawn', $outcome->fresh()->context['status']);
    }
}
