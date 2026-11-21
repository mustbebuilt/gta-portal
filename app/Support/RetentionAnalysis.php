<?php
namespace App\Support;

use App\Models\Apprentice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class RetentionAnalysis {
 public const EXIT_EMPLOYMENT = ['Employed', 'Not employed', 'Unknown'];

 public static function employmentAtExit(array $context): string
 {
  return $context['exitEmployment'] ?? 'Unknown';
 }

 public static function exitDestination(array $context):string {
  $end=CareersFollowup::date($context['endDate']??null);
  if(!$end||($context['destinationDate']??null)!==$end||!in_array($context['destinationBasis']??null,['Reported','Verified'],true)||!filled($context['destinationRef']??null)){return 'Unknown at exit';}
  return $context['destination']??'Unknown at exit';
 }
 public static function plannedEnd(\App\Models\Apprentice $apprentice): ?string
 {
  return $apprentice->start_date && $apprentice->planned_end_date && $apprentice->planned_end_date->gte($apprentice->start_date)
   ? $apprentice->planned_end_date->toDateString() : null;
 }

 public static function withdrawalTiming(\App\Models\Apprentice $apprentice, array $context): ?string
 {
  if (($context['status'] ?? null) !== 'Withdrawn') {
   return null;
  }

  $record = CohortInsights::outcome($apprentice, $context);
  if ($record['needs_check']) {
   return 'Check withdrawal source or date';
  }

  $planned = self::plannedEnd($apprentice);
  if ($planned === null) {
   return 'Check planned end date';
  }

  return $record['end_date'] < $planned ? 'Early leaver' : 'Withdrawn on or after planned end';
 }

 public static function functionalSkills(Apprentice $apprentice, array $context, Collection $training, string $through): array
 {
    $subjects = [];
    $programmeStart = $apprentice->start_date?->toDateString();
    foreach (['maths', 'english'] as $subject) {
        $qualification = $training->first(fn ($record) => $record->subject === $subject
            && $record->start_date->toDateString() <= $through
            && ($programmeStart === null || $record->start_date->toDateString() >= $programmeStart));
        $subjectStart = $qualification?->start_date?->toDateString();
        $subjects[$subject] = [
            'required' => $context[$subject] ?? 'Unknown',
            'start' => $subjectStart,
            'days' => $programmeStart && $subjectStart
                ? (int) CarbonImmutable::parse($programmeStart)->diffInDays(CarbonImmutable::parse($subjectStart))
                : null,
        ];
    }

    return $subjects;
 }

 public static function functionalSkillsStarts(array $rows): array
 {
    $summary = [];
    foreach (['maths', 'english'] as $subject) {
        $required = collect($rows)->filter(fn ($row) => $row['functional_skills'][$subject]['required'] === 'Yes');
        $started = $required->filter(fn ($row) => $row['functional_skills'][$subject]['start'] !== null);
        $elapsed = $started->pluck('functional_skills.'.$subject.'.days')->filter(fn ($days) => $days !== null);
        $summary[$subject] = [
            'required' => $required->count(),
            'started' => $started->count(),
            'no_start' => $required->count() - $started->count(),
            'median_days' => $elapsed->median(),
            'timed_starts' => $elapsed->count(),
            'early_leavers_without_start' => $required->filter(fn ($row) => $row['withdrawal_timing'] === 'Early leaver'
                && $row['functional_skills'][$subject]['start'] === null)->count(),
        ];
    }

    return $summary;
 }

 public static function registerAttendance(array $rows, ?string $start, ?string $through): array
 {
    $included = $through === null ? [] : array_filter($rows, fn ($row) =>
        $row['date'] <= $through && ($start === null || $row['date'] >= $start));

    return AttendanceMath::report($included) + ['through' => $through];
 }

 public static function attendanceByOutcome(array $rows): Collection
 {
    return collect($rows)->groupBy(fn ($row) => match ($row['sourced_status']) {
        'Completed' => 'Completed',
        'Withdrawn' => match ($row['withdrawal_timing']) {
            'Early leaver' => 'Early leavers',
            'Withdrawn on or after planned end' => 'Other withdrawals',
            default => 'Withdrawals needing a planned end date',
        },
        'Transferred' => 'Transferred',
        'Active', 'Break in learning' => 'On programme',
        default => 'Programme status needs checking',
    })->map(function ($group) {
        $attendance = $group->pluck('recorded_attendance');
        $rates = $attendance->pluck('percent')->filter(fn ($rate) => $rate !== null);

        return [
            'apprentices' => $group->count(),
            'known' => $rates->count(),
            'mean' => $rates->isNotEmpty() ? round($rates->avg(), 1) : null,
            'attended' => $attendance->sum('attended'),
            'missed' => $attendance->sum('missed'),
            'unmarked' => $attendance->sum('unmarked'),
        ];
    });
 }

 public static function rate(int $n,int $d):?float {return $d?round(100*$n/$d,1):null;}
 public static function groups(array $rows):\Illuminate\Support\Collection {
  return collect($rows)->groupBy('factor')->map(function($g){
   $completed=$g->where('status','Completed')->count();$withdrawn=$g->where('status','Withdrawn')->count();
   $eligible=$completed+$withdrawn;$known=$g->where('status','Completed')->whereIn('achievement',['Achieved','Not achieved']);$achieved=$known->where('achievement','Achieved')->count();
   $durations=$g->where('status','Withdrawn')->pluck('days_before_leaving')->filter(fn($v)=>$v!==null)->sort()->values();$n=$durations->count();
   $median=$n?($durations[(int)floor(($n-1)/2)]+$durations[(int)floor($n/2)])/2:null;
   $att=$g->map(fn($r)=>$r['recorded_attendance']['percent']??null)->filter(fn($v)=>$v!==null);
   $taskRows=$g->filter(fn($r)=>($r['assigned_tasks']??0)>0);
   $supportDays=$g->map(fn($r)=>$r['support_exposure']['days']??null)->filter(fn($v)=>$v!==null);
   $reasons=$g->where('status','Withdrawn')->groupBy('reason')->map->count()->all();
   $reasonConcerns=$g->where('status','Withdrawn')->groupBy(fn($r)=>($r['reason']??'Not established').' / '.$r['concern_codes'])->map->count()->all();
   return ['attendance_known'=>$att->count(),'attendance_mean'=>$att->count()?round($att->avg(),1):null,'task_records'=>$taskRows->count(),'tasks_assigned'=>$taskRows->sum('assigned_tasks'),'tasks_approved'=>$taskRows->sum('approved_tasks'),'support_delivered_people'=>$g->filter(fn($r)=>($r['support_activities']??0)>0)->count(),'support_days_known'=>$supportDays->count(),'support_days_mean'=>$supportDays->count()?round($supportDays->avg(),1):null,'reasons'=>$reasons,'reason_concerns'=>$reasonConcerns,'total' =>$g->count(),'completed'=>$completed,'withdrawn'=>$withdrawn,'early_leavers'=>$g->where('withdrawal_timing','Early leaver')->count(),'achieved'=>$achieved,'not_achieved'=>$known->count()-$achieved,'unknown'=>$g->where('status','Unknown')->count(),'terminal'=>$eligible,'known_results'=>$known->count(),'transferred'=>$g->where('status','Transferred')->count(),'withdrawal_rate'=>self::rate($withdrawn,$eligible),'achievement_rate'=>self::rate($achieved,$known->count()),'missing_results'=>$completed-$known->count(),'median_leaving_days'=>$median,'leaving_dates_known'=>$n,'small'=>$g->count()<10,'concern_combinations'=>$g->groupBy('concern_codes')->map->count()->all(),'exit_destinations'=>$g->groupBy('exit_destination')->map->count()->all()];
  });
 }
}
