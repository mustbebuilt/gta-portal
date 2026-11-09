<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\ApprenticeTask;
use App\Models\ApprenticeTaskUpload;
use App\Models\Block;
use App\Models\Standard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $tutor;
    protected User $apprenticeUser;
    protected Apprentice $apprentice;
    protected ApprenticeTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->create(['role' => UserRole::Trainer]);
        $this->apprenticeUser = User::factory()->create(['role' => UserRole::Apprentice]);

        $standard = Standard::create([
            'standard_code' => 'ST0482',
            'title' => 'Autocare Technician',
            'level' => 2,
        ]);

        Block::create([
            'standard_id' => $standard->standard_id,
            'block_num' => 1,
            'title' => 'Motor Vehicle Foundation Skills',
            'nominal_hours' => 30,
        ]);

        $this->apprentice = Apprentice::create([
            'user_id' => $this->apprenticeUser->id,
            'trainer_id' => $this->tutor->id,
            'standard_id' => $standard->standard_id,
            'start_date' => now()->subMonths(2)->toDateString(),
        ]);

        $this->task = ApprenticeTask::create([
            'apprentice_id' => $this->apprentice->apprentice_id,
            'ablk_id' => 1,
            'task_num' => 2,
            'task_type' => 'Task 2: Job Card from Employer',
            'status' => 'Not Started',
        ]);
    }

    public function test_apprentice_can_view_curriculum_and_task(): void
    {
        $response = $this->actingAs($this->apprenticeUser)->get(route('tasks.index'));
        $response->assertOk();
        $response->assertSee('Block plan');
        $response->assertSee('Block calendar and task records');
        $response->assertSee('bp-work-table', false);
        $response->assertSee('Gateway readiness &bull; Blocks 11&ndash;12', false);
        $response->assertSee('Motor Vehicle Foundation Skills');
        $response->assertSee('Task 2: Job Card from Employer');

        $taskResponse = $this->actingAs($this->apprenticeUser)->get(route('tasks.show', $this->task->at_id));
        $taskResponse->assertOk();
        $taskResponse->assertSee('Task Evidence & Submissions');
    }

    public function test_user_can_submit_block_change_request_and_admin_can_decide(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        // 1. Submit request
        $response = $this->actingAs($this->tutor)
            ->post(route('tasks.block-request', $this->apprentice->apprentice_id), [
                'block_num' => 1,
                'request_type' => 'early',
                'reason' => 'Apprentice accelerated workplace tasks ahead of scheduled calendar.',
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('block_change_requests', [
            'apprentice_id' => $this->apprentice->apprentice_id,
            'block_num' => 1,
            'request_type' => 'early',
            'status' => 'Requested',
        ]);

        $reqId = \Illuminate\Support\Facades\DB::table('block_change_requests')->where('apprentice_id', $this->apprentice->apprentice_id)->value('id');

        // 2. Admin approves request
        $decideResponse = $this->actingAs($admin)
            ->post(route('tasks.block-decide', $reqId), [
                'decision' => 'Approve',
                'decision_reason' => 'Reviewed against KSB allocation and approved.',
            ]);

        $decideResponse->assertRedirect();
        $this->assertDatabaseHas('block_change_requests', [
            'id' => $reqId,
            'status' => 'Approved',
            'decision_reason' => 'Reviewed against KSB allocation and approved.',
        ]);
    }

    public function test_apprentice_can_upload_evidence_and_submit_task(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('signed_job_card.pdf', 250, 'application/pdf');

        // 1. Upload evidence
        $uploadResponse = $this->actingAs($this->apprenticeUser)
            ->post(route('tasks.upload', $this->task->at_id), [
                'evidence_file' => $file,
            ]);

        $uploadResponse->assertRedirect();
        $this->assertDatabaseHas('apprentice_task_uploads', [
            'at_id' => $this->task->at_id,
            'original_filename' => 'signed_job_card.pdf',
        ]);

        // Task should have moved to In Progress
        $this->assertEquals('In Progress', $this->task->fresh()->status);

        // 2. Submit task for GTA tutor marking
        $submitResponse = $this->actingAs($this->apprenticeUser)
            ->post(route('tasks.submit', $this->task->at_id));

        $submitResponse->assertRedirect();
        $freshTask = $this->task->fresh();
        $this->assertEquals('Submitted', $freshTask->status);
        $this->assertNotNull($freshTask->submitted_at);
        $this->assertEquals(50.00, $freshTask->progress);
    }

    public function test_tutor_can_approve_submitted_task(): void
    {
        // Set task as submitted
        $this->task->update([
            'status' => 'Submitted',
            'submitted_at' => now()->subDays(2),
        ]);

        // Tutor marks as approved
        $approveResponse = $this->actingAs($this->tutor)
            ->post(route('marking.approve', $this->task->at_id), [
                'feedback' => 'Workplace job card verified and reflection meets assessment standards.',
            ]);

        $approveResponse->assertRedirect();
        $freshTask = $this->task->fresh();
        $this->assertEquals('Completed', $freshTask->status);
        $this->assertEquals(100.00, $freshTask->progress);
        $this->assertEquals($this->tutor->id, $freshTask->feedback_trainer_id);
    }

    public function test_foreign_apprentice_cannot_upload_or_submit_others_task(): void
    {
        $otherApprenticeUser = User::factory()->create(['role' => UserRole::Apprentice]);

        $response = $this->actingAs($otherApprenticeUser)
            ->post(route('tasks.submit', $this->task->at_id));

        $response->assertForbidden();
    }
}

