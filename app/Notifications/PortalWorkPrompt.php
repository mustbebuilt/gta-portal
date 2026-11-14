<?php
namespace App\Notifications;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
class PortalWorkPrompt extends Notification
{
    public function __construct(public readonly string $origin) {}
    public function via(object $notifiable): array { return ['mail']; }
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('GTA staging portal reminder')->greeting('GTA portal reminder')
            ->line('This is a controlled staging test. Please sign in to the GTA portal to check your current worklists.')
            ->action('Sign in to GTA', rtrim($this->origin,'/').'/login')
            ->line('Work may have changed since this prompt was prepared. Check the portal for the current position.');
    }
}
