<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DemoSetupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_demo_setup_is_rejected_outside_local_environment(): void
    {
        $this->artisan('gta:demo')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_local_demo_links_two_profiles_without_overwriting_existing_records(): void
    {
        $this->app->instance('env', 'local');
        $this->artisan('gta:demo')->assertSuccessful();
        $this->assertDatabaseCount('apprentices', 2);
        $this->assertDatabaseCount('users', 10);
        $first = Apprentice::where('reference', 'DEMO-001')->firstOrFail();
        $this->assertSame('Demo Workshop', $first->employerRecord->name);
        $this->assertSame('ST0499', $first->programmeRecord->standard_code);
        $this->assertSame('1.3', $first->programmeRecord->version);
        $this->assertStringContainsString('fictional', $first->programmeRecord->name);
        $this->assertSame('maths', $first->mathsTutor->subject_scope);
        $this->assertSame('english', $first->englishTutor->subject_scope);
        $this->assertSame('mentor', $first->mentor->role);
        $this->assertSame('careers_adviser', $first->careersAdviser->role);
        $this->artisan('gta:demo')->assertFailed();
        $this->assertDatabaseCount('users', 10);
        $this->assertDatabaseCount('apprentices', 2);
    }

    public function test_directory_only_data_also_prevents_demo_overwrite(): void
    {
        $this->app->instance('env', 'local');
        Employer::factory()->create();
        $this->artisan('gta:demo')->assertFailed();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('employers', 1);
    }

    public function test_local_password_reset_is_blocked_outside_local_and_does_not_change_role(): void
    {
        $u = User::factory()->create();
        $old = $u->password;
        $this->artisan('gta:local-password', ['email' => $u->email])->assertFailed();
        $this->assertSame($old, $u->fresh()->password);
        $this->app->instance('env', 'local');
        $this->artisan('gta:local-password', ['email' => $u->email])->assertSuccessful();
        $this->assertNotSame($old, $u->fresh()->password);
        $this->assertSame($u->role, $u->fresh()->role);
    }
}
