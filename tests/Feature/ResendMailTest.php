<?php

namespace Tests\Feature;

use App\Support\AccountAccess;
use App\Support\AccountOnboarding;
use App\Support\ApprenticeAlertTransport;
use App\Support\ApprenticeNotificationDelivery;
use App\Support\RecoveryDelivery;
use App\Support\ReminderDelivery;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;

class ResendMailTest extends TestCase
{
    use ConfiguresStaging, LazilyRefreshDatabase;

    private function configureResend(string $key = 're_fictional_test_key_123456'): void
    {
        $this->configureStaging();
        config([
            'mail.default' => 'resend',
            'mail.mailers.resend' => ['transport' => 'resend'],
            'services.resend.key' => $key,
            'mail.from.address' => 'portal@example.test',
            'account_access.staging_recovery_enabled' => true,
            'account_access.staging_recipients' => ['pilot@example.test'],
            'onboarding.enabled' => true,
            'onboarding.recipients' => ['pilot@example.test'],
            'reminder_delivery.enabled' => true,
            'reminder_delivery.recipients' => ['pilot@example.test'],
            'apprentice_notifications.enabled' => true,
            'apprentice_notifications.email_enabled' => true,
            'apprentice_notifications.emails' => ['pilot@example.test'],
        ]);
    }

    public function test_resend_mailer_and_api_key_satisfy_recovery_onboarding_reminder_and_alert_checks(): void
    {
        $this->configureResend();

        $this->assertSame([], RecoveryDelivery::errors());
        $this->assertSame([], AccountOnboarding::errors());
        $this->assertSame([], ReminderDelivery::errors());
        $this->assertSame([], ApprenticeNotificationDelivery::errors('email'));
        $this->assertTrue(AccountAccess::recoveryAvailable());

        $this->artisan('gta:recovery-check')->assertExitCode(0);
        $this->artisan('gta:onboarding-check')->assertExitCode(0);
        $this->artisan('gta:reminder-mail-check')->assertExitCode(0);

        $json = json_encode(RecoveryDelivery::inspect(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('re_fictional_test_key_123456', $json);
    }

    public function test_missing_invalid_or_misconfigured_resend_credentials_block_delivery(): void
    {
        $this->configureResend();

        foreach ([null, '', 'short', 're_bad key with spaces'] as $badKey) {
            config(['services.resend.key' => $badKey]);
            $this->assertContains('resend_api_key_required', RecoveryDelivery::errors());
            $this->assertFalse(AccountAccess::recoveryAvailable());
        }

        config([
            'services.resend.key' => 're_fictional_test_key_123456',
            'mail.mailers.resend.transport' => 'log',
        ]);
        $this->assertContains('direct_smtp_mailer_required', RecoveryDelivery::errors());
    }

    public function test_apprentice_alert_transport_routes_email_through_resend_mailer_and_rotates_config_hash(): void
    {
        $this->configureResend();
        $firstHash = ApprenticeNotificationDelivery::configHash();

        config(['services.resend.key' => 're_fictional_rotated_key_7890']);
        $this->assertNotSame($firstHash, ApprenticeNotificationDelivery::configHash());

        $sent = new SentMessage(
            new \Symfony\Component\Mailer\SentMessage(
                (new Email)->from('portal@example.test')->to('pilot@example.test')->text('Test'),
                Envelope::create((new Email)->from('portal@example.test')->to('pilot@example.test'))
            )
        );
        $mailer = Mockery::mock();
        $mailer->shouldReceive('raw')->once()->andReturn($sent);
        Mail::shouldReceive('purge')->once()->with('resend')->andReturnNull();
        Mail::shouldReceive('mailer')->once()->with('resend')->andReturn($mailer);

        $outcome = ApprenticeAlertTransport::send('email', 'pilot@example.test', 'delivery-68');
        $this->assertSame('Accepted', $outcome['state']);
        $this->assertSame('resend_accepted_inbox_unconfirmed', $outcome['reason']);
        $this->assertNotNull($outcome['provider_id']);
    }

    public function test_laravel_resolves_the_installed_resend_mail_transport(): void
    {
        $this->configureResend();
        $this->assertTrue(class_exists(\Resend\Laravel\ResendServiceProvider::class));
        Mail::purge('resend');
        $transport = Mail::mailer('resend')->getSymfonyTransport();
        $this->assertInstanceOf(TransportInterface::class, $transport);
    }
}
