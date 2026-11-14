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
        if ($this->purpose === 'learner_invite') { return (new MailMessage)->subject('GTA staging apprentice invitation')->line('Controlled staging invitation. Set your own apprentice password using this link within 60 minutes. Opening it does not change your account.')->action('Set my apprentice password', $this->link)->line('If unexpected, ignore this invitation and contact GTA. Never share your password.'); }
        if($this->purpose==='employer_invite'){return (new MailMessage)->subject('GTA staging employer invitation')->line('Controlled staging invitation. Set your own password for your employer login. This link expires after 60 minutes; workplace authority is checked separately by GTA.')->action('Set employer password',$this->link)->line('If unexpected, ignore this invitation and contact GTA.');}
        return (new MailMessage)->subject($invite ? 'GTA staging staff invitation' : 'GTA staging email verification')
            ->line('This is a controlled GTA staging account test. The link expires after 60 minutes.')
            ->line($invite ? 'Set your own password for the existing staff account. Your assigned role will stay the same.' : 'Sign in to your existing account, then confirm this email address.')
            ->action($invite ? 'Set up staff password' : 'Confirm email address', $this->link)
            ->line('Opening the link does not change your account. If you did not expect it, ignore it and contact your GTA administrator.');
    }
}
