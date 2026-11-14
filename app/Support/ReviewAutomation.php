<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\AttendanceMark;
use App\Models\EmployerApprenticeGrant;
use App\Models\ExtraTraining;
use App\Models\HistoricalOtjRecord;
use App\Models\IndividualDeliveryPlan;
use App\Models\Qualification;
use App\Models\ReviewSigningAuthority;
use App\Models\TrainingSession;
use App\Models\TrainingTopic;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

/** Factual preparation only. No private support/answers, guessed meetings or automatic judgement. */
class ReviewAutomation
{
    public static function capture(Apprentice $a, string $from, string $to, bool $lock = false): array
    {
        $topics = TrainingTopic::where('apprentice_id', $a->id)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->map(fn ($t) => ['id' => $t->id, 'version' => $t->version, 'subject' => $t->subject, 'topic' => $t->topic, 'planned_hours' => $t->planned_hours, 'status' => $t->status, 'completion_date' => $t->completion_date?->toDateString()])->all();
        $qualifications = Qualification::where('apprentice_id', $a->id)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->map(fn ($q) => ['id' => $q->id, 'version' => $q->version, 'subject' => $q->subject, 'type' => $q->type, 'title' => $q->title, 'level' => $q->level, 'status' => $q->status, 'result' => $q->result, 'target' => $q->target()?->toDateString(), 'achievement_date' => $q->achievement_date?->toDateString()])->all();
        if ($lock) {
            $ids = TrainingSession::where(fn ($q) => $q->whereJsonContains('members', $a->id)->orWhereJsonContains('members', (string) $a->id))->orderBy('id')->lockForUpdate()->pluck('id');
            AttendanceMark::where('apprentice_id', $a->id)->whereIn('training_session_id', $ids)->orderBy('id')->lockForUpdate()->get();
            ExtraTraining::where('apprentice_id', $a->id)->orderBy('id')->lockForUpdate()->get();
            HistoricalOtjRecord::where('apprentice_id', $a->id)->orderBy('id')->lockForUpdate()->get();
            IndividualDeliveryPlan::where('apprentice_id', $a->id)->lockForUpdate()->first();
        }
        $otj = OtjPosition::forApprentice($a);
        $summary = collect($otj['summary'])->except('captured_at')->all();
        $periodRows = collect($otj['rows'])->filter(fn ($row) => $row['date'] >= $from && $row['date'] <= $to)->map(fn ($row) => collect($row)->only('kind', 'id', 'date', 'minutes', 'state', 'code', 'source_version', 'data_check')->all())->values()->all();
        $snapshot = ReviewSnapshot::capture($a, ['reviewDate' => $to, 'trainingFrom' => $from]);
        $delivered = $snapshot['delivered'];
        $end = Carbon::parse($to)->addDays(70)->toDateString();
        $planned = TrainingSession::where(fn ($q) => $q->whereJsonContains('members', $a->id)->orWhereJsonContains('members', (string) $a->id))->where('state', 'Planned')->whereDate('session_date', '>', $to)->whereDate('session_date', '<=', $end)->orderBy('session_date')->orderBy('id')->get()->map(fn ($s) => ['id' => $s->id, 'version' => $s->version, 'date' => $s->session_date->toDateString(), 'name' => $s->name, 'subjects' => $s->planned_learning])->all();
        $cycle = SignedReviews::cycle($a);

        return ['version' => 1, 'topics' => $topics, 'qualifications' => $qualifications, 'otj' => $summary, 'period_otj_rows' => $periodRows, 'period_recorded_minutes' => array_sum(array_column($periodRows, 'minutes')), 'delivered' => $delivered, 'planned_sessions' => $planned, 'plan_blocks' => $snapshot['delivery_plan']['next_period_blocks'] ?? [], 'previous_review' => collect($cycle)->only('confirmed_count', 'integrity_checks', 'last_meeting', 'next_due')->all()];
    }

    public static function notes(array $facts): array
    {
        $learning = [];
        $progress = [];
        $summary = [];
        foreach (array_slice($facts['delivered'], 0, 20) as $row) {
            $learning[] = 'Attended saved session '.$row['date'].' — '.$row['name'].': '.Str::limit((string) ($row['subjects'] ?? 'Subjects not recorded'), 600);
        }
        $o = $facts['otj'];
        $summary[] = 'Recorded OTJ source subtotal in the chosen period: '.number_format($facts['period_recorded_minutes'] / 60, 2).' hours. This includes only currently valid loaded source records, not a reconstructed historic return.';
        $progress[] = 'Current recorded OTJ subtotal: '.number_format($o['recorded_minutes'] / 60, 2).' hours; approved-plan occupational target '.($o['target_hours'] === null ? 'not linked' : number_format($o['target_hours'], 2).' hours').'; pending sources '.$o['pending_sources'].'; source checks '.$o['invalid_sources'].'. Historical completeness is not verified.';
        if ($o['planned_due_hours'] !== null) {
            $progress[] = 'Current plan blocks already ended: '.number_format($o['planned_due_hours'], 2).' planned hours. Loaded-record gap against those ended blocks: '.number_format($o['loaded_record_gap_hours'], 2).' hours. No daily interpolation or automatic compliance judgement.';
        }
        foreach (collect($facts['topics'])->groupBy('subject') as $subject => $rows) {
            $line = ucfirst($subject).' assigned topics: '.$rows->where('status', 'Completed')->count().'/'.$rows->count().' completed; '.number_format($rows->sum('planned_hours'), 2).' planned training hours. Topic completion is separate from qualification achievement.';
            $progress[] = $line;
        }
        if (! $facts['topics']) {
            $progress[] = 'No assigned Maths/English topics loaded; check individual requirements and source records.';
        }
        foreach (array_slice($facts['qualifications'], 0, 20) as $q) {
            $progress[] = 'Recorded qualification: '.$q['title'].' — '.$q['status'].' — Result '.($q['result'] ?? 'not recorded').' — Target '.($q['target'] ?? 'not set').' — Achievement date '.($q['achievement_date'] ?? 'not recorded').'. Verify the source; no result is inferred.';
        }
        if (! $facts['qualifications']) {
            $progress[] = 'No qualification records loaded; required qualifications and missing results need checking.';
        }
        foreach (array_slice($facts['planned_sessions'], 0, 12) as $s) {
            $summary[] = 'Current saved planned session after period end: '.$s['date'].' — '.$s['name'].' — '.Str::limit((string) ($s['subjects'] ?? 'Subjects not recorded'), 500).'. Confirm the booking with the apprentice.';
        }
        foreach ($facts['plan_blocks'] as $b) {
            $summary[] = 'Approved plan block '.$b['number'].' overlaps the next ten weeks: '.$b['start'].' to '.$b['end'].'; '.$b['hours'].' hours for the whole block. Partial-block hours are not prorated.';
        }
        $review = $facts['previous_review'];
        $summary[] = $review['next_due'] ? 'Prior checked review reference: meeting '.$review['last_meeting'].'; next due '.$review['next_due'].'. Not a booked appointment.' : 'No reliable prior checked review reference; confirm history rather than assume a first review.';

        return ['learning' => $learning, 'progressDiscussion' => $progress, 'summary' => $summary];
    }

    public static function defaults(Apprentice $a): array
    {
        $mentor = $a->mentor_id ? User::find($a->mentor_id) : null;
        $tutor = $a->tutor_id ? User::find($a->tutor_id) : null;
        $names = [];
        foreach (ReviewSigningAuthority::where('apprentice_id', $a->id)->where('active', true)->get() as $authority) {
            if (! ElectronicReviews::authorityValid($authority, $a)) {
                continue;
            }$g = EmployerApprenticeGrant::find($authority->employer_apprentice_grant_id);
            $names[] = User::find($g->user_id)->name;
        }
        $cycle = SignedReviews::cycle($a);

        return ['mentor' => $mentor && StaffDuties::assigned($mentor, $a, 'mentor') ? $mentor->name : null, 'tutor' => $tutor && LearningAccess::tutor($tutor,$a) ? $tutor->name : null, 'employerRep' => count($names) === 1 ? $names[0] : null, 'trainingFrom' => $cycle['last_meeting'] ?? $a->start_date?->toDateString()];
    }
}
