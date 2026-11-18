<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AccountAccess;
use App\Support\AccountOnboarding;
use App\Support\RecoveryDelivery;
use App\Support\ReminderDelivery;
use App\Support\StagingAccess;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;

class ProductionAccessTest extends TestCase
{
    use ConfiguresStaging, LazilyRefreshDatabase;

    private function configureProduction(bool $enablePortal = true, bool $enableEmail = true): void
    {
        $this->configureStaging();
        $this->app['env'] = 'production';
        config([
            'deployment.staging_enabled' => false,
            'deployment.production_enabled' => $enablePortal,
            'mail.default' => 'resend',
            'mail.mailers.resend' => ['transport' => 'resend'],
            'services.resend.key' => 're_fictional_prod_key_12345',
            'mail.from.address' => 'portal@example.test',
            'account_access.staging_recovery_enabled' => false,
            'account_access.staging_recipients' => [],
            'account_access.production_recovery_enabled' => $enableEmail,
            'onboarding.enabled' => false,
            'onboarding.recipients' => [],
            'onboarding.production_enabled' => $enableEmail,
            'reminder_delivery.enabled' => false,
            'reminder_delivery.recipients' => [],
            'reminder_delivery.production_enabled' => $enableEmail,
        ]);
    }

    public function test_production_access_remains_blocked_until_explicitly_enabled(): void
    {
        $this->configureProduction(false, false);

        $this->assertContains('production_access_disabled', StagingAccess::errors());
        $this->assertFalse(StagingAccess::requestAllowed(Request::create('https://staging.example.test/login')));
        $this->get('https://staging.example.test/login')->assertStatus(503);
    }

    public function test_production_access_and_email_services_work_when_explicitly_enabled_with_resend(): void
    {
        $this->configureProduction(true, true);

        $this->assertSame([], StagingAccess::errors());
        $this->assertTrue(StagingAccess::requestAllowed(Request::create('https://staging.example.test/login')));

        $this->get('https://staging.example.test/login')
            ->assertOk()
            ->assertDontSee('Staging portal')
            ->assertDontSee('Local development');

        $this->get('https://staging.example.test/forgot-password')
            ->assertOk()
            ->assertSee('Enter your verified account email address to receive password reset instructions.')
            ->assertDontSee('Staging recovery pilot')
            ->assertDontSee('Local recovery test');

        $this->assertTrue(AccountAccess::recoveryAvailable());
        $this->assertTrue(AccountOnboarding::available());
        $this->assertTrue(ReminderDelivery::available());

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@example.test']);
        $staff = User::factory()->create(['role' => 'tutor', 'email' => 'newtutor@example.test', 'email_verified_at' => null]);
        $tutor = User::factory()->create(['role' => 'tutor', 'email' => 'tutor@example.test']);

        $this->assertNotNull(AccountAccess::eligible($admin->email));
        $this->assertTrue(AccountOnboarding::eligible($staff, 'invite'));
        $this->assertTrue(ReminderDelivery::eligible($tutor));
    }

    public function test_production_email_services_fail_closed_when_production_flags_are_off(): void
    {
        $this->configureProduction(true, false);

        $this->assertContains('production_recovery_disabled', RecoveryDelivery::errors());
        $this->assertContains('production_onboarding_disabled', AccountOnboarding::errors());
        $this->assertContains('production_reminder_mail_disabled', ReminderDelivery::errors());
        $this->assertFalse(AccountAccess::recoveryAvailable());
        $this->assertFalse(AccountOnboarding::available());
        $this->assertFalse(ReminderDelivery::available());
    }
}
