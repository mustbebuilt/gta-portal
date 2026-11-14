<?php
namespace App\Notifications;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
class AccountOnboardingLink extends Notification
{
    public function __construct(public readonly string $purpose, public readonly string $link) {}
    public function via(object $notifiable): array { return ['mail']; }
    public function toMail(object $notifiable): MailMessage
    {
        $invite = $this->purpose === 'invite';
        return (new MailMessage)->subject($invite ? 'GTA staging staff invitation' : 'GTA staging email verification')
            ->line('This is a controlled GTA staging account test. The link expires after 60 minutes.')
            ->line($invite ? 'Set your own password for the existing staff account. Your assigned role will stay the same.' : 'Sign in to your existing account, then confirm this email address.')
            ->action($invite ? 'Set up staff password' : 'Confirm email address', $this->link)
            ->line('Opening the link does not change your account. If you did not expect it, ignore it and contact your GTA administrator.');
    }
}
