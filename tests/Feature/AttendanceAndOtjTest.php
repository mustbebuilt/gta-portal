<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Otj;
use App\Models\Standard;
use App\Models\User;
use App\Services\AttendanceRagService;
use App\Services\OtjCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceAndOtjTest extends TestCase
{
    use RefreshDatabase;

    protected User $tutor;
    protected User $apprenticeUser;
    protected Apprentice $apprentice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->create(['role' => UserRole::Trainer]);
        $this->apprenticeUser = User::factory()->create(['role' => UserRole::Apprentice]);

        $standard = Standard::create([
            'standard_code' => 'ST0482',
            'title' => 'Autocare Technician',
            'total_otj_target' => 663.00,
        ]);

        $this->apprentice = Apprentice::create([
            'user_id' => $this->apprenticeUser->id,
            'trainer_id' => $this->tutor->id,
            'standard_id' => $standard->standard_id,
            'start_date' => now()->subMonths(6)->toDateString(),
            'planned_end_date' => now()->addMonths(24)->toDateString(),
            'otj_target_hours' => 663.00,
        ]);
    }

    public function test_attendance_rag_thresholds_and_complete_denominator(): void
    {
        $ragService = app(AttendanceRagService::class);

        // 1. Zero sessions denominator must return UNKNOWN (never assume Green or 0%)
        $initialRag = $ragService->computeRag($this->apprentice);
        $this->assertEquals(AttendanceRagService::RAG_UNKNOWN, $initialRag['rating']);
        $this->assertNull($initialRag['percentage']);

        // Create a test session
        $session = AttendanceSession::create([
            'created_by_user_id' => $this->tutor->id,
            'session_datetime' => now(),
            'topic' => 'Workshop Diagnostics',
        ]);

        // 2. Add 20 attendance records: 19 Present (95%) -> Green
        for ($i = 0; $i < 19; $i++) {
            AttendanceRecord::create([
                'attendance_session_id' => $session->attendance_session_id,
                'apprentice_id' => $this->apprentice->apprentice_id,
                'status' => 'Present',
            ]);
        }
        AttendanceRecord::create([
            'attendance_session_id' => $session->attendance_session_id,
            'apprentice_id' => $this->apprentice->apprentice_id,
            'status' => 'Unauthorised Absence',
        ]);

        $rag95 = $ragService->computeRag($this->apprentice);
        $this->assertEquals(95.0, $rag95['percentage']);
        $this->assertEquals(AttendanceRagService::RAG_GREEN, $rag95['rating']);

        // 3. Add 1 more absence -> 19/21 = 90.5% -> Amber (>= 90% and < 95%)
        AttendanceRecord::create([
            'attendance_session_id' => $session->attendance_session_id,
            'apprentice_id' => $this->apprentice->apprentice_id,
            'status' => 'Unauthorised Absence',
        ]);

        $ragAmber = $ragService->computeRag($this->apprentice);
        $this->assertEquals(90.5, $ragAmber['percentage']);
        $this->assertEquals(AttendanceRagService::RAG_AMBER, $ragAmber['rating']);

        // 4. Add another absence -> 19/22 = 86.4% -> Red (< 90%)
        AttendanceRecord::create([
            'attendance_session_id' => $session->attendance_session_id,
            'apprentice_id' => $this->apprentice->apprentice_id,
            'status' => 'Unauthorised Absence',
        ]);

        $ragRed = $ragService->computeRag($this->apprentice);
        $this->assertEquals(86.4, $ragRed['percentage']);
        $this->assertEquals(AttendanceRagService::RAG_RED, $ragRed['rating']);
    }

    public function test_otj_chunk_conversion_and_tutor_verification(): void
    {
        // Apprentice logs 6.0 hours (GTA standard day = 24 time chunks)
        $response = $this->actingAs($this->apprenticeUser)
            ->post(route('otj.store', $this->apprentice->apprentice_id), [
                'date_of_otj' => now()->toDateString(),
                'hours' => 6.0,
                'otj_type' => 'Practical Training (Shadowing)',
                'otj_activity_description' => 'Shadowed senior technician on brake pad replacement and caliper calibration.',
            ]);

        $response->assertRedirect();

        // Verify stored as 24 chunks and pending sign-off
        $otj = Otj::where('uid', $this->apprenticeUser->id)->first();
        $this->assertNotNull($otj);
        $this->assertEquals(24, $otj->time_chunks);
        $this->assertEquals(6.0, $otj->hours);
        $this->assertFalse((bool) $otj->otj_trainer_sign_off);

        // Tutor verifies and signs off
        $verifyResponse = $this->actingAs($this->tutor)
            ->post(route('otj.verify', $otj->otj_id));

        $verifyResponse->assertRedirect();
        $this->assertTrue((bool) $otj->fresh()->otj_trainer_sign_off);
        $this->assertEquals(6.0, (float) $this->apprentice->fresh()->otj_hours_completed);
    }
}

