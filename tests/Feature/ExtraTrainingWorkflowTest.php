<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ExtraTraining;
use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExtraTrainingWorkflowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role = 'tutor'): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function fixture(array $extra = []): array
    {
        $t = $this->actor();
        $l = $this->actor('apprentice');
        $g = TutorGroup::factory()->create(['tutor_id' => $t->id]);
        $a = Apprentice::factory()->create(['tutor_id' => $t->id, 'user_id' => $l->id, 'tutor_group_id' => $g->id, 'start_date' => '2026-01-05']);
        $this->actingAs($t)->post('/apprentices/'.$a->id.'/extra-training', $this->data($extra))->assertRedirect()->assertSessionHasNoErrors();

        return [ExtraTraining::where('apprentice_id', $a->id)->firstOrFail(), $a, $t, $l];
    }

    private function data(array $extra = []): array
    {
        return [...['training_date' => today()->subDay()->toDateString(), 'start_time' => '17:00', 'end_time' => '19:00', 'excluded_minutes' => 0, 'topic' => 'Fictional new diagnostic learning', 'provider' => 'Fictional GTA training', 'learning' => 'New occupational diagnostic process', 'evidence' => 'Fictional learning reference', 'working' => 'outside'], ...$extra];
    }

    private function postVersion(User $u, ExtraTraining $e, string $action, array $v = []): void
    {
        $this->actingAs($u)->post('/extra-training/'.$e->id.'/'.$action, ['version' => $e->fresh()->version, ...$v])->assertRedirect()->assertSessionHasNoErrors();
        $e->refresh();
    }

    private function compensation(array $v = []): array
    {
        return [...['method' => 'toil', 'date' => today()->toDateString(), 'covered' => 120, 'employer_name' => 'Fictional workplace supervisor', 'source' => 'Employer email', 'reference' => 'Fictional employer confirmation reference'], ...$v];
    }

    private function register(TrainingSession $s, int $id): array
    {
        return ['version' => $s->fresh()->version, 'delivered_learning' => 'Fictional new inspection learning', 'marks' => [$id => ['code' => 'O', 'arrival' => '09:00', 'departure' => '16:00', 'otj_confirmed' => 1]]];
    }

    public function test_outside_hours_need_own_agreement_sufficient_actual_compensation_and_tutor_approval(): void
    {
        [$e,$a,$t,$l] = $this->fixture();
        $this->assertSame('Awaiting apprentice agreement', $e->position());
        $this->actingAs($t)->post('/extra-training/'.$e->id.'/approve', ['version' => 1, 'eligible' => 1])->assertSessionHasErrors('eligible');
        $this->postVersion($l, $e, 'agreement', ['confirmed' => 1]);
        $this->assertSame('Awaiting employer confirmation', $e->position());
        $this->postVersion($t, $e, 'compensation', $this->compensation(['covered' => 60]));
        $this->assertSame('Awaiting employer confirmation', $e->position());
        $this->postVersion($t, $e, 'compensation', $this->compensation());
        $this->assertSame(120, $e->compensation['covered'], json_encode(['actual_compensation' => $e->compensation, 'minutes' => $e->minutes, 'agreement' => $e->agreement, 'version' => $e->version]));
        $this->assertSame('Ready for tutor check', $e->position());
        $this->postVersion($t, $e, 'approve', ['eligible' => 1]);
        $this->assertSame('Approved OTJ', $e->position());
        $this->actingAs($l)->get('/apprentices/'.$a->id.'/attendance')->assertOk()->assertSee('2.00')->assertSee('Combined loaded-record subtotal');
        $this->get('/apprentices/'.$a->id.'/extra-training')->assertOk()->assertSee('Approved OTJ')->assertDontSee('Fictional employer confirmation reference');
    }

    public function test_staff_agreement_needs_actual_apprentice_confirmation_reference(): void
    {
        [$e,$a,$t] = $this->fixture();
        $this->actingAs($t)->post('/extra-training/'.$e->id.'/agreement', ['version' => 1, 'confirmed' => 1])->assertSessionHasErrors(['source', 'reference']);
        $this->postVersion($t, $e, 'agreement', ['confirmed' => 1, 'source' => 'Recorded discussion with apprentice', 'reference' => 'Fictional discussion record']);
        $this->assertTrue($e->agreement['reported']);
    }

    public function test_other_apprentice_employer_unassigned_tutor_and_subject_tutor_cannot_record_or_approve(): void
    {
        [$e,$a,$t,$l] = $this->fixture();
        foreach (['apprentice', 'employer', 'tutor', 'functional_skills_tutor'] as $role) {
            $this->actingAs($this->actor($role))->get('/apprentices/'.$a->id.'/extra-training')->assertForbidden();
            $this->post('/extra-training/'.$e->id.'/agreement', ['version' => 1, 'confirmed' => 1])->assertForbidden();
            $this->post('/extra-training/'.$e->id.'/approve', ['version' => 1, 'eligible' => 1])->assertForbidden();
        }
        $this->actingAs($l)->post('/extra-training/'.$e->id.'/compensation', $this->compensation(['version' => 1]))->assertForbidden();
        $this->post('/extra-training/'.$e->id.'/approve', ['version' => 1, 'eligible' => 1])->assertForbidden();
    }

    public function test_normal_paid_hours_still_need_explicit_tutor_eligibility_check(): void
    {
        [$e,$a,$t] = $this->fixture(['working' => 'normal', 'excluded_minutes' => 15]);
        $this->assertSame(105, $e->minutes);
        $this->actingAs($t)->post('/extra-training/'.$e->id.'/approve', ['version' => 1])->assertSessionHasErrors('eligible');
        $this->postVersion($t, $e, 'approve', ['eligible' => 1]);
        $this->assertSame('Approved OTJ', $e->position());
        $this->get('/apprentices/'.$a->id.'/extra-training')->assertOk()->assertSee('1.75 hrs');
    }

    public function test_future_training_zero_time_or_missing_group_cannot_be_recorded(): void
    {
        $t = $this->actor();
        $a = Apprentice::factory()->create(['tutor_id' => $t->id]);
        $this->actingAs($t);
        $url = '/apprentices/'.$a->id.'/extra-training';
        $this->post($url, $this->data(['training_date' => today()->addDay()->toDateString()]))->assertSessionHasErrors('training_date');
        $this->post($url, $this->data(['excluded_minutes' => 120]))->assertSessionHasErrors('excluded_minutes');
        $this->post($url, $this->data())->assertSessionHasErrors('topic');
        $this->assertDatabaseCount('extra_trainings', 0);
    }

    public function test_future_compensation_invalid_source_and_stale_confirmation_stay_pending(): void
    {
        [$e,$a,$t,$l] = $this->fixture();
        $this->postVersion($l, $e, 'agreement', ['confirmed' => 1]);
        $this->actingAs($t);
        $this->post('/extra-training/'.$e->id.'/compensation', ['version' => 2, ...$this->compensation(['date' => today()->addDay()->toDateString()])])->assertSessionHasErrors('date');
        $this->post('/extra-training/'.$e->id.'/compensation', ['version' => 2, ...$this->compensation(['source' => 'Apprentice guess'])])->assertSessionHasErrors('source');
        $this->post('/extra-training/'.$e->id.'/compensation', ['version' => 1, ...$this->compensation()])->assertSessionHasErrors('version');
        $this->assertNull($e->fresh()->compensation);
    }

    public function test_overlap_with_confirmed_normal_register_blocks_extra_credit(): void
    {
        [$e,$a,$t] = $this->fixture(['working' => 'normal', 'start_time' => '15:00', 'end_time' => '17:00']);
        $s = TrainingSession::factory()->create(['tutor_group_id' => $a->tutor_group_id, 'session_date' => $e->training_date, 'members' => [$a->id]]);
        $this->actingAs($t)->post('/sessions/'.$s->id, $this->register($s, $a->id))->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/extra-training/'.$e->id.'/approve', ['version' => 1, 'eligible' => 1])->assertSessionHasErrors('eligible');
        $this->assertNull($e->fresh()->approval);
    }

    public function test_overlap_guard_also_blocks_register_credit_after_extra_approval(): void
    {
        [$e,$a,$t] = $this->fixture(['working' => 'normal', 'start_time' => '10:00', 'end_time' => '11:00']);
        $this->postVersion($t, $e, 'approve', ['eligible' => 1]);
        $s = TrainingSession::factory()->create(['tutor_group_id' => $a->tutor_group_id, 'session_date' => $e->training_date, 'members' => [$a->id]]);
        $this->post('/sessions/'.$s->id, $this->register($s, $a->id))->assertSessionHasErrors('marks');
        $this->postVersion($t, $e, 'withdraw', ['reason' => 'Remove duplicated training source']);
        $this->post('/sessions/'.$s->id, $this->register($s, $a->id))->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_overlapping_extra_records_cannot_both_count_and_adjoining_times_can(): void
    {
        [$e,$a,$t] = $this->fixture(['working' => 'normal']);
        $this->postVersion($t, $e, 'approve', ['eligible' => 1]);
        $url = '/apprentices/'.$a->id.'/extra-training';
        $this->post($url, $this->data(['working' => 'normal', 'start_time' => '18:00', 'end_time' => '20:00']))->assertRedirect()->assertSessionHasNoErrors();
        $second = ExtraTraining::where('id', '!=', $e->id)->firstOrFail();
        $this->post('/extra-training/'.$second->id.'/approve', ['version' => 1, 'eligible' => 1])->assertSessionHasErrors('eligible');
        $this->post($url, $this->data(['working' => 'normal', 'start_time' => '19:00', 'end_time' => '20:00']))->assertRedirect()->assertSessionHasNoErrors();
        $third = ExtraTraining::latest('id')->firstOrFail();
        $this->postVersion($t, $third, 'approve', ['eligible' => 1]);
        $this->get('/apprentices/'.$a->id.'/attendance')->assertOk()->assertSee('3.00');
    }

    public function test_withdrawal_removes_subtotal_and_retains_approval_history(): void
    {
        [$e,$a,$t] = $this->fixture(['working' => 'normal']);
        $this->postVersion($t, $e, 'approve', ['eligible' => 1]);
        $this->post('/extra-training/'.$e->id.'/withdraw', ['version' => $e->version])->assertSessionHasErrors('reason');
        $this->postVersion($t, $e, 'withdraw', ['reason' => 'Fictional correction to avoid duplicate credit']);
        $this->assertNotNull($e->approval);
        $this->assertSame('Withdrawn', $e->position());
        $this->get('/apprentices/'.$a->id.'/extra-training')->assertOk()->assertSee('0.00 hrs')->assertSee('Fictional correction');
    }

    public function test_optional_training_file_is_private_and_cannot_set_approval(): void
    {
        Storage::fake('local');
        [$e,$a,$t,$l] = $this->fixture(['file' => UploadedFile::fake()->createWithContent('extra-proof.txt', 'Fictional training evidence')]);
        Storage::disk('local')->assertExists($e->file['path']);
        $this->assertNull($e->approval);
        $this->actingAs($l)->get('/extra-training/'.$e->id.'/file')->assertOk()->assertDownload();
        $this->actingAs($this->actor('employer'))->get('/extra-training/'.$e->id.'/file')->assertForbidden();
    }

    public function test_audit_failure_rolls_back_pending_training_and_cleans_uploaded_file(): void
    {
        Storage::fake('local');
        $t = $this->actor();
        $a = Apprentice::factory()->create(['tutor_id' => $t->id, 'tutor_group_id' => TutorGroup::factory()->create()->id]);
        DB::listen(function (QueryExecuted $event): void {
            if (str_starts_with(strtolower($event->sql), 'insert') && str_contains($event->sql, 'learning_events')) {
                throw new \RuntimeException('Deliberate extra training audit failure');
            }
        });
        $this->actingAs($t)->post('/apprentices/'.$a->id.'/extra-training', $this->data(['file' => UploadedFile::fake()->createWithContent('proof.txt', 'Fictional only')]))->assertStatus(500);
        $this->assertDatabaseCount('extra_trainings', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
