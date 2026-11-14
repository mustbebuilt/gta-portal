<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\ApprenticeAlertDelivery as Delivery;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ApprenticeNotificationDelivery
{
    public const STATES = ['Prepared', 'Dispatching', 'Accepted', 'Rejected', 'Uncertain', 'Cancelled', 'Stale', 'Suppressed'];

    public static function control(bool $lock = false): array
    {
        $row = DB::table('apprentice_alert_controls')->where('id', 1)->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $row) {
            throw new \RuntimeException('Notification control row needs checking.');
        }

        return ['paused' => (bool) $row->paused, 'version' => (int) $row->version, 'history' => json_decode($row->history, true, 512, JSON_THROW_ON_ERROR)];
    }

    public static function pause(User $actor, int $authVersion, int $version, bool $paused, string $note): void
    {
        DB::transaction(function () use ($actor, $authVersion, $version, $paused, $note): void {
            $control = self::control(true);
            self::actor($actor, $authVersion);
            if ($control['version'] !== $version) {
                throw ValidationException::withMessages(['alert' => 'Sending control changed. Reload first.']);
            }$history = [...$control['history'], ['paused' => $paused, 'by' => $actor->id, 'at' => now()->toIso8601String(), 'note' => $note]];
            DB::table('apprentice_alert_controls')->where('id', 1)->update(['paused' => $paused, 'version' => $version + 1, 'updated_by' => $actor->id, 'history' => json_encode($history, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            DB::table('account_access_events')->insert(['user_id' => $actor->id, 'actor_id' => $actor->id, 'event' => $paused ? 'apprentice_alerts_paused' : 'apprentice_alerts_resumed', 'origin' => 'apprentice_reminder_operations', 'created_at' => now()]);
        });
    }

    private static function expire(?User $actor, ?int $authVersion): int
    {
        $count = 0;
        foreach (Delivery::where('state', 'Prepared')->whereNull('reserved_at')->whereDate('cycle_date', '<', today())->orderBy('cycle_date')->limit(1000)->get(['id']) as $old) {
            DB::transaction(function () use ($old, $actor, $authVersion, &$count): void {
                self::actor($actor, $authVersion);
                $d = Delivery::whereKey($old->id)->lockForUpdate()->firstOrFail();
                if ($d->state === 'Prepared' && ! $d->reserved_at) {
                    $d->state = 'Stale';
                    $d->reason = 'cycle_expired';
                    self::event($d, 'expired_before_any_attempt', $actor?->id);
                    $d->save();
                    $count++;
                }
            });
        }

        return $count;
    }

    public static function digest(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    public static function configHash(): string
    {
        return self::digest(json_encode(['settings' => config('apprentice_notifications'), 'origin' => config('app.url'), 'smtp' => config('mail.mailers.smtp'), 'mailer' => config('mail.default'), 'sender' => config('mail.from')], JSON_THROW_ON_ERROR));
    }

    public static function errors(?string $channel = null): array
    {
        $e = StagingAccess::errors();
        if (! is_int(config('apprentice_notifications.attempt_limit')) || config('apprentice_notifications.attempt_limit') < 1 || config('apprentice_notifications.attempt_limit') > 500) {
            $e[] = 'attempt_budget_between_1_and_500_required';
        }if (config('apprentice_notifications.enabled') !== true) {
            $e[] = 'apprentice_alerts_disabled';
        }
        $email = config('apprentice_notifications.email_enabled') === true;
        $sms = config('apprentice_notifications.sms_enabled') === true;
        if (! $email && ! $sms) {
            $e[] = 'no_channel_enabled';
        }
        if ($channel === 'email' && ! $email) {
            $e[] = 'email_channel_disabled';
        }if ($channel === 'sms' && ! $sms) {
            $e[] = 'sms_channel_disabled';
        }
        if (($channel === null && $email) || $channel === 'email') {
            $list = config('apprentice_notifications.emails', []);
            $valid = is_array($list) && count($list) >= 1 && count($list) <= 250 && collect($list)->every(fn ($mail) => is_string($mail) && filter_var($mail, FILTER_VALIDATE_EMAIL)) && count(array_unique(array_map('strtolower', $list))) === count($list);
            if (! $valid) {
                $e[] = 'one_to_250_unique_approved_emails_required';
            }$e = [...$e, ...RecoveryDelivery::configurationErrors(true, is_array($list) ? array_slice($list, 0, 10) : [], 'email_disabled')];
            if ((float) config('mail.mailers.smtp.timeout', 0) > 10) {
                $e[] = 'smtp_timeout_must_not_exceed_10_seconds';
            }
        }
        if (($channel === null && $sms) || $channel === 'sms') {
            $list = config('apprentice_notifications.phones');
            if (! is_array($list) || ! $list || count($list) > 250 || count($list) !== count(array_unique($list)) || collect($list)->contains(fn ($n) => ! is_string($n) || ! preg_match('/^\+[1-9][0-9]{7,14}$/D', $n))) {
                $e[] = 'one_to_250_unique_e164_recipients_required';
            }
            if (! is_string(config('apprentice_notifications.key')) || ! preg_match('/^[A-Za-z0-9]{8}$/D', config('apprentice_notifications.key'))) {
                $e[] = 'vonage_key_required';
            }
            $secret = config('apprentice_notifications.secret');
            if (! is_string($secret) || strlen($secret) < 8 || strlen($secret) > 128) {
                $e[] = 'vonage_secret_required';
            }
            if (! is_string(config('apprentice_notifications.from')) || ! preg_match('/^(?:\+?[1-9][0-9]{7,14}|[A-Za-z][A-Za-z0-9 ]{0,10})$/D', config('apprentice_notifications.from'))) {
                $e[] = 'vonage_sender_required';
            }
            if (! preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?/?$#D', (string) config('app.url')) || strlen(ApprenticeAlertTransport::smsText()) > 160) {
                $e[] = 'ascii_origin_and_single_short_sms_required';
            }
        }

        return array_values(array_unique($e));
    }

    public static function inspect(): array
    {
        return ['mode' => 'staging_apprentice_reminder_delivery', 'attempt_budget_24h' => config('apprentice_notifications.attempt_limit'), 'approved_email_count' => is_array(config('apprentice_notifications.emails')) ? count(config('apprentice_notifications.emails')) : 0, 'approved_mobile_count' => is_array(config('apprentice_notifications.phones')) ? count(config('apprentice_notifications.phones')) : 0, 'blockers' => self::errors(), 'email_blockers' => self::errors('email'), 'sms_blockers' => self::errors('sms'), 'scheduled' => config('apprentice_notifications.scheduled') === true, 'limits' => ['maximum_50_dispatches_per_run', 'one_reserved_attempt_per_channel_recipient_and_contact_per_uk_calendar_day', 'configured_global_rolling_24_hour_budget_maximum_500_default_50', 'maximum_100_source_items_per_digest', 'no_automatic_retry_after_reservation'], 'sends_performed' => false, 'writes_performed' => false];
    }

    public static function admin(User $u): bool
    {
        return $u->active && $u->email_verified_at && LearningAccess::manager($u);
    }

    private static function actor(?User $actor, ?int $version): void
    {
        if ($actor) {
            $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::admin($u) && $u->auth_version === $version, 403);
        }
    }

    public static function eligible(Apprentice $a, string $channel, bool $configuration = true): array
    {
        if ($configuration && self::errors($channel)) {
            return ['ok' => false, 'reason' => 'configuration_blocked'];
        }
        $u = $a->user_id ? User::find($a->user_id) : null;
        if (! ApprenticeAlerts::wants($a, $channel)) {
            return ['ok' => false, 'reason' => 'preferences_off_or_other_channel'];
        }
        if (! $u?->active || $u->role !== 'apprentice' || ! $u->email_verified_at || Apprentice::where('user_id', $u->id)->count() !== 1) {
            return ['ok' => false, 'reason' => 'account_link_or_mailbox_check'];
        }
        try {
            $destination = $channel === 'email' ? trim($u->email) : $a->alert_mobile;
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'encrypted_contact_needs_check'];
        }
        if ($channel === 'email') {
            $list = config('apprentice_notifications.emails', []);
            if (! is_array($list) || ! in_array(strtolower($destination), array_map('strtolower', $list), true) || ! filter_var($destination, FILTER_VALIDATE_EMAIL) || User::whereRaw('LOWER(email) = ?', [strtolower($destination)])->count() !== 1) {
                return ['ok' => false, 'reason' => 'email_not_verified_unique_or_allowlisted'];
            }
        } else {
            if (! $a->alert_mobile_verified_at || ! is_string($destination) || ! preg_match('/^\+[1-9][0-9]{7,14}$/D', $destination) || ! in_array($destination, config('apprentice_notifications.phones', []), true)) {
                return ['ok' => false, 'reason' => 'mobile_not_verified_or_allowlisted'];
            }
        }

        return ['ok' => true, 'user' => $u, 'destination' => $destination, 'recipient_hash' => self::digest($channel.':'.($channel === 'email' ? strtolower($destination) : $destination))];
    }

    public static function preview(): array
    {
        $rows = [];
        $counts = ['profiles' => 0, 'with_due_items' => 0, 'eligible_email' => 0, 'eligible_sms' => 0, 'source_checks' => 0];
        foreach (Apprentice::orderBy('id')->get() as $a) {
            $counts['profiles']++;
            try {
                $p = ApprenticeReminderSources::build($a);
            } catch (\Throwable) {
                $p = ['source' => null, 'check' => 'source_needs_check'];
            }$counts['source_checks'] += (int) (($p['check'] ?? null) && ($p['check'] ?? null) !== 'no_due_items');
            $counts['source_checks'] += (int) ($p['checks'] ?? 0);
            if (! $p['source']) {
                continue;
            }$counts['with_due_items']++;
            $email = self::eligible($a, 'email');
            $sms = self::eligible($a, 'sms');
            $counts['eligible_email'] += (int) $email['ok'];
            $counts['eligible_sms'] += (int) $sms['ok'];
            $rows[] = ['apprentice' => $a->only('id', 'reference', 'first_name', 'last_name'), 'items' => count($p['source']['items']), 'email' => $email['ok'] ? 'eligible' : $email['reason'], 'sms' => $sms['ok'] ? 'eligible' : $sms['reason']];
        }

        return ['counts' => $counts, 'rows' => $rows, 'configuration' => self::inspect(), 'writes_performed' => false, 'sends_performed' => false];
    }

    private static function event(Delivery $d, string $event, ?int $by = null, ?string $reason = null): void
    {
        $d->history = [...($d->history ?? []), ['event' => $event, 'by' => $by, 'reason' => $reason, 'at' => now()->toIso8601String()]];
    }

    private static function audit(Delivery $d, string $event, ?int $by = null): void
    {
        DB::table('audit_events')->insert(['user_id' => $by ?? $d->recipient_id, 'apprentice_id' => $d->apprentice_id, 'event' => $event, 'created_at' => now()]);
    }

    public static function prepare(int $limit = 20, ?User $actor = null, ?int $authVersion = null): array
    {
        $counts = ['expired_unreserved' => self::expire($actor, $authVersion), 'profiles_checked' => 0, 'prepared' => 0, 'refreshed_unsent' => 0, 'already_recorded' => 0, 'held' => 0];
        $preparedProfiles = 0;
        foreach (Apprentice::orderBy('id')->get(['id']) as $profile) {
            $counts['profiles_checked']++;
            try {
                $result = DB::transaction(function () use ($profile, $actor, $authVersion): array {
                    $a = Apprentice::whereKey($profile->id)->lockForUpdate()->firstOrFail();
                    self::actor($actor, $authVersion);
                    $p = ApprenticeReminderSources::build($a, true);
                    $result = ['prepared' => 0, 'refreshed_unsent' => 0, 'already_recorded' => 0, 'held' => 0];
                    if (! $p['source']) {
                        $result['held']++;

                        return $result;
                    }
                    foreach (['email', 'sms'] as $channel) {
                        $e = self::eligible($a, $channel);
                        if (! $e['ok']) {
                            $result['held']++;

                            continue;
                        }$u = $e['user'];
                        $d = Delivery::where('apprentice_id', $a->id)->where('recipient_id', $u->id)->where('channel', $channel)->whereDate('cycle_date', today())->lockForUpdate()->first();
                        if ($d && ($d->reserved_at || in_array($d->state, ['Cancelled', 'Accepted', 'Rejected', 'Uncertain', 'Dispatching'], true))) {
                            $result['already_recorded']++;

                            continue;
                        }
                        $hash = ReviewedImports::hash($p['source']);
                        if ($d && $d->state === 'Prepared' && $d->source_hash === $hash && $d->preference_revision === $a->alert_revision && $d->auth_version === $u->auth_version && hash_equals($d->recipient_hash, $e['recipient_hash']) && hash_equals($d->config_hash, self::configHash())) {
                            $result['already_recorded']++;

                            continue;
                        }
                        $new = ! $d;
                        $d ??= new Delivery(['id' => (string) Str::uuid(), 'apprentice_id' => $a->id, 'recipient_id' => $u->id, 'channel' => $channel, 'cycle_date' => today()]);
                        $d->source = $p['source'];
                        $d->source_hash = $hash;
                        $d->recipient_hash = $e['recipient_hash'];
                        $d->preference_revision = $a->alert_revision;
                        $d->auth_version = $u->auth_version;
                        $d->config_hash = self::configHash();
                        $d->state = 'Prepared';
                        $d->reason = null;
                        self::event($d, $new ? 'prepared' : 'refreshed_before_any_attempt', $actor?->id);
                        $d->save();
                        self::audit($d, 'apprentice_alert_prepared', $actor?->id);
                        $result[$new ? 'prepared' : 'refreshed_unsent']++;
                    }

                    return $result;
                });
                foreach ($result as $key => $value) {
                    $counts[$key] += $value;
                }if (($result['prepared'] + $result['refreshed_unsent']) > 0) {
                    $preparedProfiles++;
                }if ($preparedProfiles >= max(1, min($limit, 250))) {
                    break;
                }
            } catch (HttpException $e) {
                throw $e;
            } catch (\Throwable) {
                $counts['held']++;
            }
        }

        return $counts;
    }

    private static function check(Delivery $d, Apprentice $a, bool $lock = true): array
    {
        if (! $d->cycle_date->isToday()) {
            return ['ok' => false, 'state' => 'Stale', 'reason' => 'cycle_expired'];
        }
        if ($a->user_id !== $d->recipient_id || ($d->source['apprentice_id'] ?? null) !== $a->id || ($d->source['recipient_id'] ?? null) !== $d->recipient_id || ReviewedImports::hash($d->source) !== $d->source_hash) {
            return ['ok' => false, 'state' => 'Stale', 'reason' => 'identity_or_integrity_changed'];
        }
        $e = self::eligible($a, $d->channel);
        if (! $e['ok']) {
            return ['ok' => false, 'state' => 'Suppressed', 'reason' => $e['reason']];
        }
        if ($d->preference_revision !== $a->alert_revision || $d->auth_version !== $e['user']->auth_version || ! hash_equals($d->recipient_hash, $e['recipient_hash']) || ! hash_equals($d->config_hash, self::configHash())) {
            return ['ok' => false, 'state' => 'Stale', 'reason' => 'account_preferences_contact_or_configuration_changed'];
        }
        $p = ApprenticeReminderSources::build($a, $lock);
        if (! $p['source'] || ReviewedImports::hash($p['source']) !== $d->source_hash) {
            return ['ok' => false, 'state' => 'Stale', 'reason' => 'source_changed_or_resolved'];
        }

        return $e;
    }

    private static function hold(Delivery $d, array $check): array
    {
        $d->state = $check['state'];
        $d->reason = $check['reason'];
        self::event($d, $d->state, null, $d->reason);
        $d->save();
        self::audit($d, 'apprentice_alert_held');

        return ['state' => $d->state, 'reason' => $d->reason];
    }

    public static function dispatch(Delivery $delivery, ?User $actor = null, ?int $authVersion = null): array
    {
        if (DB::transactionLevel() !== 0) {
            return ['state' => 'Blocked', 'reason' => 'outer_transaction_not_allowed'];
        }
        try {
            $reserved = Cache::lock('gta-apprentice-alert-attempt-reservation', 30)->block(2, function () use ($delivery, $actor, $authVersion): array {
                return DB::transaction(function () use ($delivery, $actor, $authVersion): array {
                    $control = self::control(true);
                    if ($control['paused']) {
                        return ['state' => 'Blocked', 'reason' => 'operations_paused'];
                    }
                    $a = Apprentice::whereKey($delivery->apprentice_id)->lockForUpdate()->firstOrFail();
                    self::actor($actor, $authVersion);
                    if ($a->user_id) {
                        User::whereKey($a->user_id)->lockForUpdate()->first();
                    }$d = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                    if ($d->state !== 'Prepared' || $d->reserved_at) {
                        return ['state' => 'Blocked', 'reason' => 'not_prepared_or_already_reserved'];
                    }$check = self::check($d, $a);
                    if (! $check['ok']) {
                        return self::hold($d, $check);
                    }
                    $since = now()->subHours(24);
                    $attempts = Delivery::where('reserved_at', '>=', $since);
                    if ((clone $attempts)->count() >= config('apprentice_notifications.attempt_limit')) {
                        return ['state' => 'Blocked', 'reason' => 'global_reminder_budget_reached'];
                    }
                    if (Delivery::whereNotNull('reserved_at')->whereDate('cycle_date', today())->where('channel', $d->channel)->where(fn ($q) => $q->where('recipient_id', $d->recipient_id)->orWhere('recipient_hash', $d->recipient_hash))->exists()) {
                        return ['state' => 'Blocked', 'reason' => 'daily_contact_limit'];
                    }
                    $d->state = 'Dispatching';
                    $d->reserved_at = now();
                    self::event($d, 'attempt_reserved', $actor?->id);
                    $d->save();
                    self::audit($d, 'apprentice_alert_attempt_reserved', $actor?->id);

                    return ['state' => 'Dispatching', 'id' => $d->id];
                });
            });
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            return ['state' => 'Blocked', 'reason' => 'reservation_needs_check'];
        }
        if (($reserved['state'] ?? null) !== 'Dispatching') {
            return $reserved;
        }
        // Attempt is committed before external I/O. A later crash must never requeue this row.
        try {
            return DB::transaction(function () use ($delivery, $actor, $authVersion): array {
                $control = self::control(true);
                $a = Apprentice::whereKey($delivery->apprentice_id)->lockForUpdate()->firstOrFail();
                self::actor($actor, $authVersion);
                if ($a->user_id) {
                    User::whereKey($a->user_id)->lockForUpdate()->first();
                }$d = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                if ($d->state !== 'Dispatching' || ! $d->reserved_at) {
                    return ['state' => 'Blocked', 'reason' => 'reservation_changed'];
                }
                if ($control['paused']) {
                    return self::hold($d, ['state' => 'Suppressed', 'reason' => 'operations_paused_after_reservation']);
                }
                $check = self::check($d, $a);
                if (! $check['ok']) {
                    return self::hold($d, $check);
                }$outcome = ApprenticeAlertTransport::send($d->channel, $check['destination'], $d->id);
                $d->state = $outcome['state'];
                $d->reason = $outcome['reason'];
                $d->provider_id = $outcome['provider_id'];
                $d->completed_at = now();
                self::event($d, $d->state, $actor?->id, $d->reason);
                $d->save();
                self::audit($d, 'apprentice_alert_attempt_finished', $actor?->id);

                return ['state' => $d->state, 'reason' => $d->reason];
            });
        } catch (\Throwable) {
            return ['state' => 'Dispatching', 'reason' => 'outcome_needs_reconciliation_no_retry'];
        }
    }

    public static function sendPrepared(int $limit = 20, ?User $actor = null, ?int $authVersion = null): array
    {
        $counts = [];
        $attempted = 0;
        // Scan past held contacts instead of allowing an early held row to starve later eligible rows.
        foreach (Delivery::where('state', 'Prepared')->whereDate('cycle_date', today())->orderBy('created_at')->orderBy('id')->limit(500)->get(['id', 'apprentice_id']) as $d) {
            $result = self::dispatch($d, $actor, $authVersion);
            $counts[$result['state']] = ($counts[$result['state']] ?? 0) + 1;
            if (Delivery::whereKey($d->id)->value('reserved_at')) {
                $attempted++;
            }
            if ($attempted >= max(1, min($limit, 50)) || in_array($result['reason'] ?? null, ['global_reminder_budget_reached', 'operations_paused', 'reservation_needs_check'], true)) {
                break;
            }
        }

return $counts;
    }

    public static function cancel(User $actor, int $authVersion, Delivery $delivery, string $note): void
    {
        DB::transaction(function () use ($actor, $authVersion, $delivery, $note): void {
            self::actor($actor, $authVersion);
            $d = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($d->state !== 'Prepared' || $d->reserved_at) {
                throw ValidationException::withMessages(['alert' => 'Only an unreserved prepared reminder can be cancelled.']);
            }$d->state = 'Cancelled';
            self::event($d, 'cancelled', $actor->id, $note);
            $d->save();
            self::audit($d, 'apprentice_alert_cancelled', $actor->id);
        });
    }

    public static function reconcile(User $actor, int $authVersion, Delivery $delivery, string $note): void
    {
        DB::transaction(function () use ($actor, $authVersion, $delivery, $note): void {
            self::actor($actor, $authVersion);
            $d = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if (! $d->reserved_at || ! in_array($d->state, ['Dispatching', 'Accepted', 'Rejected', 'Uncertain', 'Stale', 'Suppressed'], true)) {
                throw ValidationException::withMessages(['alert' => 'Use this for reserved attempts only.']);
            }$d->reconciliation = [...($d->reconciliation ?? []), ['by' => $actor->id, 'at' => now()->toIso8601String(), 'note' => $note]];
            self::event($d, 'reconciled_without_retry', $actor->id);
            $d->save();
            self::audit($d, 'apprentice_alert_reconciled', $actor->id);
        });
    }
}
