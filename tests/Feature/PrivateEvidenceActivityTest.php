<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Qualification;
use App\Models\SupportConcern;
use App\Models\SupportPlan;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrivateEvidenceActivityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(string $role): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->save();

        return $u;
    }

    private function plan(bool $active = true): array
    {
        $u = $this->actor('inclusion_lead');
        $a = Apprentice::factory()->create();
        $c = SupportConcern::factory()->create(['apprentice_id' => $a->id]);
        $this->actingAs($u)->post('/concerns/'.$c->id.'/classify', ['version' => 1, 'code' => 'IM36', 'owner_id' => $u->id, 'next_action' => 'Agree support', 'action_due' => today()->addDay()->toDateString(), 'next_review' => today()->addDays(7)->toDateString()])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/apprentices/'.$a->id.'/support-plans', ['concern_ids' => [$c->id], 'fields' => ['type' => 'full', 'referral' => today()->toDateString(), 'barriers' => 'Fictional travel barrier', 'strengths' => 'Practical engagement', 'view' => 'Wants reliable attendance', 'support' => 'Discuss transport choices', 'review' => today()->addDays(28)->toDateString(), 'funding' => 'not_funded']])->assertRedirect()->assertSessionHasNoErrors();
        $p = SupportPlan::firstOrFail();
        if ($active) {
            $this->post('/support-plans/'.$p->id.'/approve', ['version' => 1, 'confirmed' => 1, 'reason' => 'Checked source plan'])->assertRedirect()->assertSessionHasNoErrors();
        }

        return [$p->fresh(), $a, $u];
    }

    private function activity(SupportPlan $p, User $u, array $extra = []): array
    {
        return [...['version' => $p->version, 'approved_at' => $p->active_plan['approved_at'] ?? 'not-active', 'request_id' => (string) Str::uuid(), 'date' => today()->toDateString(), 'type' => 'Specialist 1:1 Coaching', 'who' => (string) $u->id, 'delivered' => 'Practised the agreed planning sequence'], ...$extra];
    }

    public function test_qualification_certificate_is_private_and_does_not_set_achievement(): void
    {
        Storage::fake('local');
        $admin = $this->actor('admin');
        $l = $this->actor('apprentice');
        $a = Apprentice::factory()->create(['user_id' => $l->id]);
        $q = Qualification::factory()->create(['apprentice_id' => $a->id]);
        $this->actingAs($admin)->post('/qualifications/'.$q->id.'/files', ['version' => 1, 'purpose' => 'Fictional certificate supplied for checking', 'file' => UploadedFile::fake()->create('fictional-certificate.pdf', 4, 'application/pdf')])->assertRedirect()->assertSessionHasNoErrors();
        $q = $q->fresh();
        $file = $q->files[0];
        $this->assertSame('Working towards', $q->status);
        $this->assertNull($q->achievement_date);
        Storage::disk('local')->assertExists($file['path']);
        $this->actingAs($l)->get('/apprentices/'.$a->id.'/maths-english')->assertOk()->assertSee('fictional-certificate.pdf')->assertDontSee($file['path']);
        $this->get('/qualifications/'.$q->id.'/files/'.$file['id'])->assertOk()->assertDownload();
        $this->actingAs($this->actor('employer'))->get('/qualifications/'.$q->id.'/files/'.$file['id'])->assertForbidden();
    }

    public function test_qualification_upload_retains_subject_scope_and_rejects_stale_or_executable(): void
    {
        Storage::fake('local');
        $u = $this->actor('functional_skills_tutor');
        $u->subject_scope = 'maths';
        $u->save();
        $a = Apprentice::factory()->create(['maths_tutor_id' => $u->id, 'english_tutor_id' => $u->id]);
        $q = Qualification::factory()->create(['apprentice_id' => $a->id, 'subject' => 'english']);
        $this->actingAs($u)->post('/qualifications/'.$q->id.'/files', [])->assertForbidden();
        $this->actingAs($this->actor('admin'))->post('/qualifications/'.$q->id.'/files', ['version' => 0, 'purpose' => 'Certificate', 'file' => UploadedFile::fake()->create('proof.pdf', 4, 'application/pdf')])->assertSessionHasErrors('version');
        $this->post('/qualifications/'.$q->id.'/files', ['version' => 1, 'purpose' => 'Certificate', 'file' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo "bad";')])->assertSessionHasErrors('file');
        $this->assertNull($q->fresh()->files);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_support_activity_records_unknown_duration_and_actual_approved_source_without_otj(): void
    {
        [$p,$a,$u] = $this->plan();
        $this->actingAs($u)->post('/support-plans/'.$p->id.'/activities', $this->activity($p, $u))->assertRedirect()->assertSessionHasNoErrors();
        $p = $p->fresh();
        $this->assertNull($p->activities[0]['minutes'] ?? null);
        $this->assertSame($p->active_plan['approved_at'], $p->activities[0]['approved_at']);
        $this->assertSame([], $p->reviews ?? []);
        $this->get('/apprentices/'.$a->id.'/support-plans/'.$p->id)->assertOk()->assertSee('Duration not recorded')->assertSee('Practised the agreed planning sequence');
        $this->assertDatabaseCount('attendance_marks', 0);
        $this->assertDatabaseCount('learning_tasks', 0);
    }

    public function test_support_activity_needs_active_current_plan_actual_date_and_positive_duration(): void
    {
        [$p,$a,$u] = $this->plan(false);
        $url = '/support-plans/'.$p->id.'/activities';
        $this->actingAs($u)->post($url, $this->activity($p, $u))->assertSessionHasErrors('approved_at');
        $this->post('/support-plans/'.$p->id.'/approve', ['version' => 1, 'confirmed' => 1, 'reason' => 'Activate'])->assertRedirect()->assertSessionHasNoErrors();
        $p = $p->fresh();
        $this->post($url, $this->activity($p, $u, ['minutes' => 0]))->assertSessionHasErrors('minutes');
        $this->post($url, $this->activity($p, $u, ['date' => today()->subDay()->toDateString()]))->assertSessionHasErrors('date');
        $this->post($url, $this->activity($p, $u, ['approved_at' => 'superseded']))->assertSessionHasErrors('approved_at');
        $this->post($url, $this->activity($p, $u, ['who' => 'other']))->assertSessionHasErrors('other_name');
    }

    public function test_support_activity_duplicate_form_and_tutor_cannot_create_private_records(): void
    {
        [$p,$a,$u] = $this->plan();
        $data = $this->activity($p, $u);
        $url = '/support-plans/'.$p->id.'/activities';
        $this->actingAs($u)->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, [...$data, 'version' => 3])->assertSessionHasErrors('request_id');
        $t = $this->actor('tutor');
        $a->tutor_id = $t->id;
        $a->save();
        $this->actingAs($t)->post($url, $this->activity($p->fresh(), $u))->assertForbidden();
        $this->assertCount(1, $p->fresh()->activities);
    }

    public function test_support_evidence_upload_and_download_stay_restricted_and_bound_to_original_plan(): void
    {
        Storage::fake('local');
        [$p,$a,$u] = $this->plan();
        $this->actingAs($u)->post('/support-plans/'.$p->id.'/files', ['version' => 2, 'purpose' => 'Fictional coaching record', 'file' => UploadedFile::fake()->createWithContent('support.txt', 'Fictional support record only')])->assertRedirect()->assertSessionHasNoErrors();
        $file = $p->fresh()->files[0];
        $this->get('/support-plans/'.$p->id.'/files/'.$file['id'])->assertOk()->assertDownload();
        $this->get('/apprentices/'.$a->id.'/support-plans/'.$p->id)->assertOk()->assertSee('support.txt')->assertDontSee($file['path']);
        $other = SupportPlan::create(['apprentice_id' => Apprentice::factory()->create()->id, 'draft' => [], 'concern_ids' => []]);
        $this->get('/support-plans/'.$other->id.'/files/'.$file['id'])->assertNotFound();
        foreach (['tutor', 'employer', 'apprentice', 'careers_adviser'] as $role) {
            $this->actingAs($this->actor($role))->get('/support-plans/'.$p->id.'/files/'.$file['id'])->assertForbidden();
        }
    }

    public function test_closed_support_records_reject_new_files_and_activities_but_retain_history(): void
    {
        Storage::fake('local');
        [$p,$a,$u] = $this->plan();
        $this->actingAs($u)->post('/support-plans/'.$p->id.'/close', ['version' => 2, 'confirmed' => 1, 'outcome' => 'Support complete with arrangements retained'])->assertRedirect()->assertSessionHasNoErrors();
        $p = $p->fresh();
        $this->post('/support-plans/'.$p->id.'/files', ['version' => $p->version, 'purpose' => 'Late file', 'file' => UploadedFile::fake()->createWithContent('late.txt', 'Fictional')])->assertSessionHasErrors('file');
        $this->post('/support-plans/'.$p->id.'/activities', $this->activity($p, $u))->assertSessionHasErrors('approved_at');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_private_file_audit_failure_rolls_back_database_and_cleans_new_file(): void
    {
        Storage::fake('local');
        $q = Qualification::factory()->create();
        $u = $this->actor('admin');
        DB::listen(function (QueryExecuted $event): void {
            if (str_starts_with(strtolower($event->sql), 'insert') && str_contains($event->sql, 'learning_events')) {
                throw new \RuntimeException('Deliberate evidence audit failure');
            }
        });
        $this->actingAs($u)->post('/qualifications/'.$q->id.'/files', ['version' => 1, 'purpose' => 'Fictional', 'file' => UploadedFile::fake()->create('proof.pdf', 4, 'application/pdf')])->assertStatus(500);
        $this->assertNull($q->fresh()->files);
        $this->assertSame(1, $q->fresh()->version);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
