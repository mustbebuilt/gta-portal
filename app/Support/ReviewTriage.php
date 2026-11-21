<?php

namespace App\Support;

use App\Models\ProgressReviewDraft;
use App\Models\ReviewAppointment;
use App\Models\SignedReviewRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ReviewTriage
{
    public const VIEWS = ['meeting-overdue', 'meeting-soon', 'awaiting-signoff'];
    public const UPCOMING_DAYS = 14;

    public static function for(Collection $rows, array $positions): Collection
    {
        $ids = $rows->pluck('apprentice.id');
        $drafts = ProgressReviewDraft::whereIn('apprentice_id', $ids)->where('state', 'Ready for signatures')
            ->get(['id', 'apprentice_id', 'state', 'version', 'fields','updated_at'])->groupBy('apprentice_id');
        $appointments = ReviewAppointment::whereIn('apprentice_id', $ids)->whereIn('state', ['Reported', 'Published'])
            ->orderByDesc('starts_at')->get()->groupBy('apprentice_id');
        $pendingCopies = SignedReviewRecord::whereIn('apprentice_id', $ids)
            ->whereNull('checked_at')->whereNull('withdrawn_at')->get()->groupBy('progress_review_draft_id');

        return $rows->map(function (array $row) use ($positions, $drafts, $appointments, $pendingCopies): array {
            $a = $row['apprentice'];
            $position = $positions[$a->id];
            $last = $position['cycle']['last_meeting'];
            $available = $appointments->get($a->id, collect());
            $reported = $available->first(fn (ReviewAppointment $p): bool =>
                $p->state === 'Reported' && $p->ends_at->lte(now()) && ReviewMeetingFollowup::reported($p)
                && $p->outcome['kind'] === 'Held' && self::validMeeting($p->starts_at->copy()->timezone(config('app.timezone'))->toDateString(), $last, $a->start_date?->toDateString()));
            $ready = $drafts->get($a->id, collect())
                ->filter(fn (ProgressReviewDraft $r): bool => self::validMeeting($r->fields['reviewDate'] ?? null, $last, $a->start_date?->toDateString()))
                ->sortByDesc(fn (ProgressReviewDraft $r): array => [$r->fields['reviewDate'], $r->id])->first();

            if ($ready && $reported && $ready->fields['reviewDate'] < $reported->starts_at->copy()->timezone(config('app.timezone'))->toDateString()) {
                $ready = null;
            }
            $held = $ready?->fields['reviewDate'] ?? $reported?->starts_at->copy()->timezone(config('app.timezone'))->toDateString();
            $unchecked = $ready && $pendingCopies->get($ready->id, collect())
                ->contains(fn (SignedReviewRecord $copy): bool => $copy->review_version === $ready->version && SignedReviews::intact($copy));
            $due = $row['due'];
            if ($held !== null && $due !== null) {
                $due = CarbonImmutable::parse($held)->addDays(70)->toDateString();
            }
            $meeting = !$position['programme_check'] && !$position['paused'] && $due ? match (true) {
                $due < today()->toDateString() => 'meeting-overdue',
                $due <= today()->addDays(self::UPCOMING_DAYS)->toDateString() => 'meeting-soon',
                default => null,
            } : null;
            $booking = $meeting ? $available->first(fn (ReviewAppointment $p): bool =>
                $p->state === 'Published' && $p->starts_at->gt(now()) && ReviewAppointments::published($p) && ReviewAppointments::current($p)) : null;

            return $row + ['triage' => [
                'meeting' => $meeting, 'due' => $due, 'date_provisional' => $held !== null && $row['due'] !== null,
                'held' => $held, 'ready_id' => $ready?->id, 'unchecked' => $unchecked,
                'held_appointment_id' => $reported?->id,
                'booking_id' => $booking?->id, 'booking_at' => $booking?->starts_at,
            ]];
        });
    }

    private static function validMeeting(mixed $date, ?string $last, ?string $start): bool
    {
        return $date !== null && CohortInsights::validDate($date) && $date <= today()->toDateString()
            && ($last === null || $date > $last) && ($start === null || $date >= $start);
    }

    public static function filter(Collection $rows, string $view): Collection
    {
        if ($view === 'awaiting-signoff') {
            return $rows->whereNotNull('triage.held')->sortBy('triage.held')->values();
        }

        return $rows->where('triage.meeting', $view)->sortBy('triage.due')->values();
    }
}
