<?php

namespace App\Support;

class RecoveryDelivery
{
    public static function recipients(): array
    {
        $values = config('account_access.staging_recipients', []);
        if (! is_array($values)) { return []; }
        return array_values(array_unique(array_map(fn ($value) => strtolower(trim($value)), array_filter($values, fn ($value) => is_string($value) && trim($value) !== ''))));
    }

    public static function recipientAllowed(string $email): bool
    {
        return ! app()->environment('staging') || in_array(strtolower(trim($email)), self::recipients(), true);
    }

    public static function errors(): array
    {
        return self::configurationErrors(config('account_access.staging_recovery_enabled') === true, self::recipients(), 'staging_recovery_disabled');
    }

    public static function configurationErrors(bool $enabled, array $recipients, string $disabledCode): array
    {
        $errors = StagingAccess::errors();
        if (! $enabled) { $errors[] = $disabledCode; }
        if (! $recipients || count($recipients) > 10 || count(array_filter($recipients, fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))) !== count($recipients)) { $errors[] = 'one_to_ten_valid_pilot_recipients_required'; }
        if (config('mail.driver')) { $errors[] = 'legacy_mail_driver_override_not_allowed'; }
        $mail = config('mail.mailers.smtp', []);
        if (config('mail.default') !== 'smtp' || ! is_array($mail) || ($mail['transport'] ?? null) !== 'smtp') { $errors[] = 'direct_smtp_mailer_required'; $mail = []; }
        if (isset($mail['url'])) { $errors[] = 'smtp_url_override_not_allowed'; }
        $host = $mail['host'] ?? null; $port = $mail['port'] ?? null;
        if (! is_string($host) || trim($host) === '' || filter_var($port, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false) { $errors[] = 'smtp_host_and_port_required'; }
        if (! in_array($mail['scheme'] ?? null, [null, '', 'smtp', 'smtps'], true)) { $errors[] = 'smtp_scheme_invalid'; }
        $loopback = in_array($host, ['127.0.0.1', '::1'], true);
        if (! $loopback && ($mail['scheme'] ?? null) !== 'smtps') { $errors[] = 'remote_smtp_requires_smtps'; }
        if (array_key_exists('verify_peer', $mail) && filter_var($mail['verify_peer'], FILTER_VALIDATE_BOOL) !== true) { $errors[] = 'smtp_peer_verification_required'; }
        if (! is_numeric($mail['timeout'] ?? null) || (float) $mail['timeout'] <= 0 || (float) $mail['timeout'] > 30) { $errors[] = 'bounded_smtp_timeout_required'; }
        if (! filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL)) { $errors[] = 'valid_sender_address_required'; }
        return array_values(array_unique($errors));
    }

    public static function inspect(): array
    {
        $errors = self::errors();
        return ['mode' => 'controlled_staging_recovery_pilot', 'result' => $errors ? 'blocked' : 'configuration_checks_only', 'blockers' => $errors, 'pilot_recipient_count' => count(self::recipients()), 'sends_performed' => false, 'writes_performed' => false, 'limits' => ['No mail connection or test email is attempted.', 'Configuration checks do not verify recipient ownership, provider delivery, DNS, actual TLS, database schema or deployment.', 'Only existing active, verified, unambiguous accounts on the staging pilot list can request or use recovery. Production remains disabled.']];
    }
}
