<?php

namespace App\Support;

use Illuminate\Mail\Message;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class ApprenticeAlertTransport
{
    public const TEMPLATE_VERSION = 'operational-reminder-1';

    public static function smsText(): string
    {
        return 'GTA reminder: '.rtrim((string) config('app.url'), '/').'/my-next-step - manage alerts in your account.';
    }

    public static function send(string $channel, string $destination, string $id): array
    {
        if (! in_array($channel, ['email', 'sms'], true)) {
            return ['state' => 'Uncertain', 'reason' => 'unsupported_channel', 'provider_id' => null];
        }
        try {
            if ($channel === 'email') {
                $text = "Please sign in to check your recorded training, tasks, agreed actions or review reference.\n\n".rtrim(config('app.url'), '/')."/my-next-step\n\nManage alert preferences in your portal account. This reminder does not confirm a booked review, task approval or programme achievement.";
                Mail::purge('smtp');
                $sent = Mail::mailer('smtp')->raw($text, function (Message $message) use ($destination, $id): void {
                    $message->to($destination)->subject('GTA portal reminder');
                    $message->getSymfonyMessage()->getHeaders()->addTextHeader('X-GTA-Delivery-ID', $id);
                });

                return $sent instanceof SentMessage ? ['state' => 'Accepted', 'reason' => 'smtp_accepted_inbox_unconfirmed', 'provider_id' => hash('sha256', (string) $sent->getMessageId())] : ['state' => 'Uncertain', 'reason' => 'smtp_no_acceptance_proof', 'provider_id' => null];
            }
            $r = Http::asForm()->withBasicAuth(config('apprentice_notifications.key'), config('apprentice_notifications.secret'))->withOptions(['verify' => true, 'allow_redirects' => false])->connectTimeout(5)->timeout(10)->post(MobileVerification::ENDPOINT, ['from' => config('apprentice_notifications.from'), 'to' => ltrim($destination, '+'), 'text' => self::smsText(), 'client-ref' => $id]);
            $body = $r->json();
            $m = $body['messages'][0] ?? null;
            if ($r->successful() && (string) ($body['message-count'] ?? '') === '1' && is_array($body['messages'] ?? null) && count($body['messages']) === 1 && is_array($m) && ltrim((string) ($m['to'] ?? ''), '+') === ltrim($destination, '+')) {
                if ((string) ($m['status'] ?? '') === '0' && is_string($m['message-id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,96}$/D', $m['message-id'])) {
                    return ['state' => 'Accepted', 'reason' => 'sms_accepted_delivery_unconfirmed', 'provider_id' => $m['message-id']];
                }
                if (preg_match('/^[1-9][0-9]*$/D', (string) ($m['status'] ?? ''))) {
                    return ['state' => 'Rejected', 'reason' => 'sms_explicit_rejection', 'provider_id' => null];
                }
            }

            return ['state' => 'Uncertain', 'reason' => 'sms_response_needs_check', 'provider_id' => null];
        } catch (\Throwable) {
            return ['state' => 'Uncertain', 'reason' => 'transport_exception_needs_check', 'provider_id' => null];
        }
    }
}
