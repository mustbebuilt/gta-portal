<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\MonthlyScorecard;
use App\Models\ProgrammeOutcome;
use App\Models\Qualification;
use App\Models\SupportConcern;
use App\Support\AssignmentHistory;
use App\Support\AttendanceLedger;
use App\Support\ConcernDuration;
use App\Support\LearningAccess as Access;
use App\Support\CohortInsights;
use App\Support\RetentionAnalysis;
use App\Support\RetentionEarlyWarning;
use App\Support\RetentionMarkerAnalysis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RetentionReportController extends Controller
{
    private function choices(): array
    {
        return json_decode(file_get_contents(resource_path('data/retention-choices.json')), true);
    }

    public function edit(Request $r, Apprentice $apprentice): View
    {
        abort_unless(Access::manager($r->user()), 403);

        return view('retention.edit', ['apprentice' => $apprentice, 'outcome' => ProgrammeOutcome::where('apprentice_id', $apprentice->id)->first(), 'choices' => $this->choices(), 'employmentChoices' => RetentionAnalysis::EXIT_EMPLOYMENT, 'employers' => Employer::orderBy('name')->get(), 'followupOwners'=>\App\Models\User::where('active',true)->whereIn('role',['admin','ceo'])->orderBy('name')->get(['id','name'])]);
    }

    public function save(Request $r, Apprentice $apprentice): RedirectResponse
    {
        abort_unless(Access::manager($r->user()), 403);
        $choices = $this->choices();
        $rules = ['version' => ['required', 'integer'],'followup_owner_id'=>['nullable','integer',Rule::exists('users','id')->where('active',true)->whereIn('role',['admin','ceo'])]];
        foreach (['status', 'achievement', 'reason', 'reasonBasis', 'voice', 'destination', 'destinationBasis', 'age', 'size', 'maths', 'english', 'additional', 'ehcp'] as $key) {
            $list = in_array($key, ['maths', 'english', 'additional', 'ehcp'], true) ? $choices['flag'] : $choices[$key];
            $rules['context.'.$key] = ['sometimes', 'required', Rule::in($list)];
        }foreach (['endDate', 'achievementDate', 'destinationDate', 'sizeDate'] as $key) {
            $rules['context.'.$key] = ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'];
        }foreach (['sourceRef', 'contextRef', 'destinationRef'] as $key) {
            $rules['context.'.$key] = ['nullable', 'string', 'max:1000'];
        }$rules['context.sizeEmployerId'] = ['nullable', 'integer', Rule::exists('employers', 'id')];
        $rules['context.exitEmployment'] = ['sometimes', 'required', Rule::in(RetentionAnalysis::EXIT_EMPLOYMENT)];
        $data = $r->validate($rules);
        $v = $data['context'] ?? [];
        $v = array_merge(['status' => 'Unknown', 'achievement' => 'Unknown', 'reason' => 'Not established', 'reasonBasis' => 'Not confirmed', 'voice' => 'Unknown', 'destination' => 'Unknown', 'destinationBasis' => 'Unknown', 'age' => 'Unknown', 'size' => 'Unknown', 'additional' => 'Unknown', 'ehcp' => 'Unknown'], $v);
        foreach ($v as $key => $value) {
            if ($value === null && ! in_array($key, ['endDate', 'achievementDate', 'destinationDate', 'sizeDate', 'sizeEmployerId', 'sourceRef', 'contextRef', 'destinationRef'], true)) {
                $v[$key] = 'Unknown';
            }
        }
        $ended = in_array($v['status'], ['Completed', 'Withdrawn', 'Transferred'], true);
        if (! $ended && ($v['exitEmployment'] ?? 'Unknown') !== 'Unknown') {
            throw ValidationException::withMessages(['context.exitEmployment' => 'Employment at exit is recorded when the programme ends.']);
        }
        if ($ended && (! filled($v['endDate'] ?? null) || ! $apprentice->start_date || $v['endDate'] < $apprentice->start_date->toDateString())) {
            throw ValidationException::withMessages(['context.endDate' => 'Record a valid end date on or after the known first learning day.']);
        }
        if (! $ended && filled($v['endDate'] ?? null)) {
            throw ValidationException::withMessages(['context.endDate' => 'An active programme or break has no programme end date.']);
        }
        if ($v['status'] !== 'Unknown' && ! filled($v['sourceRef'] ?? null)) {
            throw ValidationException::withMessages(['context.sourceRef' => 'Record the source for the programme status.']);
        }
        if ($v['status'] !== 'Completed' && ($v['achievement'] !== 'Unknown' || filled($v['achievementDate'] ?? null))) {
            throw ValidationException::withMessages(['context.achievement' => 'Programme completion and confirmed achievement must be recorded separately.']);
        }
        if ($v['achievement'] === 'Achieved' && (! filled($v['achievementDate'] ?? null) || $v['achievementDate'] < ($v['endDate'] ?? ''))) {
            throw ValidationException::withMessages(['context.achievementDate' => 'Record confirmed achievement on or after the programme end date.']);
        }
        if ($v['achievement'] !== 'Achieved' && filled($v['achievementDate'] ?? null)) {
            throw ValidationException::withMessages(['context.achievementDate' => 'Only confirmed achievement has an achievement date.']);
        }
        if ($v['status'] !== 'Withdrawn' && ($v['reason'] !== 'Not established' || $v['reasonBasis'] !== 'Not confirmed')) {
            throw ValidationException::withMessages(['context.reason' => 'Leaving reasons belong to a withdrawal record.']);
        }
        if ($v['reason'] !== 'Not established' && $v['reasonBasis'] === 'Not confirmed') {
            throw ValidationException::withMessages(['context.reasonBasis' => 'Record whose account supports the reason; do not treat assumptions as confirmed.']);
        }
        if ($v['ehcp'] === 'Yes' && $v['additional'] === 'No') {
            throw ValidationException::withMessages(['context.additional' => 'Check this context: an EHC plan cannot be recorded with no additional needs.']);
        }
        if (count(array_filter(['age', 'maths', 'english', 'additional', 'ehcp'], fn ($k) => ($v[$k] ?? 'Unknown') !== 'Unknown')) && ! filled($v['contextRef'] ?? null)) {
            throw ValidationException::withMessages(['context.contextRef' => 'Record the existing source for this reporting context.']);
        }
        if ($v['size'] !== 'Unknown' && (! filled($v['sizeDate'] ?? null) || ! filled($v['sizeEmployerId'] ?? null) || ! filled($v['contextRef'] ?? null))) {
            throw ValidationException::withMessages(['context.size' => 'Headcount band needs its reference date, employer and source.']);
        }
        if ($v['size'] === 'Unknown' && (filled($v['sizeDate'] ?? null) || filled($v['sizeEmployerId'] ?? null))) {
            throw ValidationException::withMessages(['context.size' => 'Clear employer size details when the band is unknown.']);
        }
        if ($v['destination'] !== 'Unknown' && (! $ended || ! filled($v['destinationDate'] ?? null) || $v['destinationDate'] < $v['endDate'] || ! filled($v['destinationRef'] ?? null) || $v['destinationBasis'] === 'Unknown')) {
            throw ValidationException::withMessages(['context.destination' => 'A destination needs a dated source after the programme ended and a reported/verified basis.']);
        }
        if ($v['destination'] === 'Unknown' && (filled($v['destinationDate'] ?? null) || filled($v['destinationRef'] ?? null) || $v['destinationBasis'] !== 'Unknown')) {
            throw ValidationException::withMessages(['context.destination' => 'Clear destination details when it is unknown.']);
        }
        DB::transaction(function () use ($r, $apprentice, $v, $ended): void {
            Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            $row = ProgrammeOutcome::where('apprentice_id', $apprentice->id)->lockForUpdate()->first();
            if ($r->integer('version') !== ($row?->version ?? 0)) {
                throw ValidationException::withMessages(['version' => 'This outcome/context record changed. Reopen before saving.']);
            }
            $existing = $row?->context ?? [];
            $checksCompletion = $v['status'] === 'Completed' || isset($existing['epa_closure']);
            if ($checksCompletion) {
                foreach (['status', 'achievement', 'endDate', 'achievementDate', 'sourceRef'] as $key) {
                    if (($existing[$key] ?? ($key === 'achievement' ? 'Unknown' : null)) !== ($v[$key] ?? null)) {
                        throw ValidationException::withMessages(['context.status' => 'Use Close down and archive to record a confirmed EPA achievement.']);
                    }
                }
            }
            $row ??= new ProgrammeOutcome(['apprentice_id' => $apprentice->id]);
            $h = $row->history ?? [];
            $h[] = ['before' => $row->context, 'by' => $r->user()->id, 'at' => now()->toIso8601String()];
            $retainedKeys = ['epa_attempts', 'epa_closure', 'maths', 'english'];
            if ($ended && ($existing['endDate'] ?? null) === $v['endDate']) {
                $retainedKeys[] = 'exitEmployment';
            }
            $row->context = array_replace(array_intersect_key($existing, array_flip($retainedKeys)), $v);
            $row->history = $h;
            if ($row->exists) {
                $row->version++;
            }$row->save();
            DB::table('learning_events')->insert(['user_id' => $r->user()->id, 'apprentice_id' => $apprentice->id, 'kind' => 'outcome_context', 'record_id' => $row->id, 'event' => 'context_recorded', 'created_at' => now()]);
        });

        \App\Support\DestinationMilestones::sync($apprentice);
        if($r->filled('followup_owner_id')){foreach(\App\Models\DestinationMilestone::where('apprentice_id',$apprentice->id)->where('state','!=','Superseded')->get() as $task){\App\Support\DestinationMilestones::assign($r->user(),$task,$task->version,(int)$r->input('followup_owner_id'));}}

        return back()->with('status', 'Reporting context saved. The live ILR, qualification results and training plan were not changed.');
    }


    public function report(Request $r)
    {
        abort_unless(Access::manager($r->user()),403);
        $entryFactors=array_keys(\App\Support\EntryContext::choices());
        $employerFactors = ['entry_employer', 'linked_employer'];
        $factors=array_merge($employerFactors,['programme','age','size','maths','english','additional','ehcp','reason'],$entryFactors);
        $d=$r->validate(['from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d','after_or_equal:from'],'factor'=>['nullable',Rule::in($factors)],'programme_id'=>['nullable','integer'],'entry_employer_id'=>['nullable','integer'],'linked_employer_id'=>['nullable','integer'],'age'=>['nullable',Rule::in($this->choices()['age'])],'prior'=>['nullable',Rule::in(['Yes','No','Unknown','Prefer not to say'])],'page'=>['nullable','integer','min:1'],'outcome'=>['nullable','in:achievers,early-leavers,withdrawals,active,checks'],'export'=>['nullable','in:csv']]);
        $query=Apprentice::query();$all=$query->count();
        if(!empty($d['from'])){$query->whereDate('start_date','>=',$d['from']);}if(!empty($d['to'])){$query->whereDate('start_date','<=',$d['to']);}if(!empty($d['programme_id'])){$query->where('programme_id',$d['programme_id']);}
        if(!empty($d['entry_employer_id'])){$query->whereIn('id',\App\Models\ApprenticeEntryContext::where('entry_employer_id',$d['entry_employer_id'])->select('apprentice_id'));}
        if (!empty($d['linked_employer_id'])) {
            $query->where('employer_id', $d['linked_employer_id']);
        }
        $employerOptions = Employer::orderBy('name')->orderBy('reference')->get(['id', 'name', 'reference']);
        $employerLabels = $employerOptions->mapWithKeys(fn ($employer) => [
            $employer->id => $employer->name.' · '.$employer->reference,
        ]);
        if(!empty($d['prior'])){$query->where(function($q)use($d){$q->whereIn('id',\App\Models\ApprenticeEntryContext::where('already_employed',$d['prior'])->select('apprentice_id'));if($d['prior']==='Unknown'){$q->orWhereNotExists(function($s){$s->selectRaw('1')->from('apprentice_entry_contexts')->whereColumn('apprentice_entry_contexts.apprentice_id','apprentices.id')->whereNotNull('already_employed');});}});}
        if(!empty($d['age'])){$query->where(function($q)use($d){$q->whereIn('id',ProgrammeOutcome::where('context->age',$d['age'])->select('apprentice_id'));if($d['age']==='Unknown'){$q->orWhereIn('id',ProgrammeOutcome::whereNull('context->age')->select('apprentice_id'))->orWhereNotExists(function($s){$s->selectRaw('1')->from('programme_outcomes')->whereColumn('programme_outcomes.apprentice_id','apprentices.id');});}});}
        $apprentices=$query->with(['programmeRecord','employerRecord'])->orderBy('last_name')->orderBy('id')->get();$ids=$apprentices->pluck('id')->all();$excluded=$all-count($ids);
        $contexts=ProgrammeOutcome::whereIn('apprentice_id',$ids)->get()->keyBy('apprentice_id');
        $markerLabels = json_decode(file_get_contents(resource_path('data/inclusion-markers.json')), true, 512, JSON_THROW_ON_ERROR);
        $markers = DB::table('apprentice_markers')->whereIn('apprentice_id', $ids)
            ->get(['apprentice_id', 'code', 'created_at'])->groupBy('apprentice_id');
        $markerHistory = DB::table('marker_change_events')->whereIn('apprentice_id', $ids)
            ->orderBy('created_at')->orderBy('id')
            ->get(['apprentice_id', 'before_codes', 'after_codes', 'created_at'])->groupBy('apprentice_id');
        $checkIns = MonthlyScorecard::whereIn('apprentice_id', $ids)->whereNotNull('development')
            ->orderByDesc('month')->get(['apprentice_id', 'month', 'development', 'history'])->groupBy('apprentice_id');
        $subjectTraining = Qualification::whereIn('apprentice_id', $ids)
            ->where('type', 'Functional Skills')->whereIn('subject', ['maths', 'english'])
            ->whereIn('status', ['Working towards', 'Achieved'])->whereNotNull('start_date')
            ->orderBy('start_date')->get(['apprentice_id', 'subject', 'start_date'])->groupBy('apprentice_id');
        $entries=\App\Models\ApprenticeEntryContext::whereIn('apprentice_id',$ids)->get()->keyBy('apprentice_id');
        $attendance=AttendanceLedger::forIds($ids,false);$factor=$d['factor']??'programme';
        $byEmployer = in_array($factor, $employerFactors, true);
        $cases=SupportConcern::whereIn('apprentice_id',$ids)->get(['id','apprentice_id','code','status','acknowledged_at','closed_at','created_at'])->groupBy('apprentice_id');
        $assignmentEvents=DB::table('assignment_change_events')->whereIn('apprentice_id',$ids)->get()->groupBy('apprentice_id');
        $plans=\App\Models\SupportPlan::whereIn('apprentice_id',$ids)->get(['id','apprentice_id','status','active_plan','activities','reviews','history','closed_at'])->groupBy('apprentice_id');
        $tasks=DB::table('learning_tasks')->whereIn('apprentice_id',$ids)->selectRaw("apprentice_id, COUNT(*) as assigned, SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved")->groupBy('apprentice_id')->get()->keyBy('apprentice_id');
        $peopleById=$apprentices->keyBy('id');
        $milestones=\App\Models\DestinationMilestone::whereIn('apprentice_id',$ids)->where('state','!=','Superseded')->get()->filter(fn($t)=>$t->exit_on->toDateString()===(\App\Support\LeaverDestinations::anchor($peopleById->get($t->apprentice_id),$contexts->get($t->apprentice_id))['end_date']??null));
        $latest=DB::table('destination_milestone_checks')->whereIn('milestone_id',$milestones->pluck('id'))->selectRaw('MAX(id) as id')->groupBy('milestone_id');
        $checks=\App\Models\DestinationMilestoneCheck::whereIn('id',$latest)->get()->keyBy('milestone_id');
        $milestonesByPerson=$milestones->groupBy('apprentice_id');
        $rows=[];
        foreach($apprentices as $a){
            $c=$contexts->get($a->id)?->context??[];$e=$entries->get($a->id);$status=$c['status']??'Unknown';$history=AssignmentHistory::summary($a,$assignmentEvents->get($a->id,collect()));
            $days=null;$end=\App\Support\CareersFollowup::date($c['endDate']??null);if($end&&$a->start_date&&$end>=$a->start_date->toDateString()){$days=(int)$a->start_date->diffInDays(\Carbon\Carbon::parse($end));}
            $outcome = CohortInsights::outcome($a, $c);
            $achievementDate = $outcome['achievement'] === 'Achieved' ? $c['achievementDate'] : null;
            $ended = in_array($status, ['Completed', 'Withdrawn', 'Transferred'], true);
            $cutoff = $ended ? $end : null;
            $attendanceThrough = $ended ? $outcome['end_date'] : today()->toDateString();
            $registerSummary = RetentionAnalysis::registerAttendance(
                $attendance[$a->id]['rows'], $a->start_date?->toDateString(), $attendanceThrough,
            );
            $cs=$cases->get($a->id,collect())->filter(fn($c)=>!$cutoff||!$c->created_at||$c->created_at->toDateString()<=$cutoff);$ps=$plans->get($a->id,collect());$t=$tasks->get($a->id);
            $codes=$cs->pluck('code')->map(fn($x)=>$x?:'Unclassified')->unique()->sort()->implode(' + ')?:'No recorded concerns';
            $entryEmployer = $employerLabels->get($e?->entry_employer_id);
            $linkedEmployer = $employerLabels->get($a->employer_id);
            $factorLabel = match ($factor) {
                'entry_employer' => $entryEmployer ?? 'Entry employer not recorded',
                'linked_employer' => $linkedEmployer ?? 'Linked employer not recorded',
                'programme' => $a->programmeLabel() ?? 'Unknown',
                default => in_array($factor, $entryFactors, true) ? ($e?->$factor ?? 'Not recorded') : ($c[$factor] ?? 'Unknown'),
            };
            $row=['apprentice'=>$a,'status'=>$status,'achievement'=>$c['achievement']??'Unknown','factor'=>$factorLabel,'reason'=>$status==='Withdrawn'?($c['reason']??'Not established'):'Not applicable','reason_basis'=>$c['reasonBasis']??'Not confirmed','employers'=>$history['employers']===null?'Unknown':$history['employers'].' distinct recorded linked employers; earlier history unknown','tutors'=>$history['tutors']===null?'Unknown':$history['tutors'].' distinct recorded main tutors; earlier history unknown','recorded_attendance'=>$registerSummary,'concern_cases'=>$cs->count(),'concern_duration'=>ConcernDuration::summarise($cs,$cutoff),'concern_codes'=>$codes,'days_before_leaving'=>$days,'exit_destination'=>\App\Support\RetentionAnalysis::exitDestination($c),'entry_gaps'=>\App\Support\EntryContext::gaps($e,$a),'support_plans'=>$ps->count(),'support_approved'=>$ps->filter(fn($p)=>filled($p->active_plan['approved_at']??null))->count(),'support_activities'=>$ps->sum(fn($p)=>count($p->activities??[])),'support_exposure'=>\App\Support\SupportExposure::summary($ps,$cutoff),'assigned_tasks'=>(int)($t->assigned??0),'approved_tasks'=>(int)($t->approved??0)];
            $markerSummary = RetentionMarkerAnalysis::summarise(
                $markers->get($a->id, collect()),
                $markerHistory->get($a->id, collect()),
                $outcome['end_date'] ?? today()->toDateString(),
            );
            $rows[] = $row + [
                'end_date' => $outcome['end_date'],
                'sourced_status' => $outcome['status'],
                'sourced_achievement' => $outcome['achievement'],
                'entry_employer' => $entryEmployer,
                'linked_employer' => $linkedEmployer,
                'maths_requirement' => $c['maths'] ?? 'Unknown',
                'english_requirement' => $c['english'] ?? 'Unknown',
                'functional_skills' => RetentionAnalysis::functionalSkills(
                    $a, $c, $subjectTraining->get($a->id, collect()), $outcome['end_date'] ?? today()->toDateString(),
                ),
                'marker_summary' => $markerSummary,
                'early_warning' => RetentionEarlyWarning::evidence(
                    $checkIns->get($a->id, collect()), $attendance[$a->id]['rows'], $markerSummary,
                    $a->start_date?->toDateString(), $attendanceThrough, $outcome['status'] === 'Active',
                ),
                'exit_employment' => $outcome['end_date'] ? RetentionAnalysis::employmentAtExit($c) : null,
                'planned_end' => RetentionAnalysis::plannedEnd($a),
                'withdrawal_timing' => RetentionAnalysis::withdrawalTiming($a, $c),
                'achievement_date' => $achievementDate,
                'epa_grade' => $achievementDate ? ($c['epa_closure']['grade'] ?? null) : null,
                'epa_attempt' => $achievementDate ? ($c['epa_closure']['attempt'] ?? null) : null,
            ];
        }
        $comparisonRows = $byEmployer ? array_map(fn ($row) => array_replace($row, [
            'status' => $row['sourced_status'], 'achievement' => $row['sourced_achievement'],
        ]), $rows) : $rows;
        $groups=RetentionAnalysis::groups($comparisonRows);
        $unscheduled=$apprentices->filter(fn($a)=>\App\Support\LeaverDestinations::anchor($a,$contexts->get($a->id))&&!$milestonesByPerson->has($a->id))->count();
        $milestoneGroups=[];foreach(collect($rows)->groupBy('factor') as $label=>$group){$milestoneGroups[$label]=\App\Support\DestinationMilestones::summary($milestones->whereIn('apprentice_id',$group->pluck('apprentice.id')),$checks);}
        if(($d['export']??null)==='csv'){
            DB::table('retention_report_exports')->insert(['actor_id'=>$r->user()->id,'cohort_count'=>count($rows),'filters'=>json_encode(collect($d)->except(['page','export'])->all()),'created_at'=>now()]);
            return response()->streamDownload(function()use($groups,$byEmployer){$f=fopen('php://output','w');fputcsv($f,['Factor','Included','Completed','Withdrawn','Completed or withdrawn denominator','Withdrawal rate %','Achieved','Known completed results denominator','Achievement rate %','Missing completed results','Unknown status','Median recorded leaving days','Leaving dates denominator','Exploratory below 10','Early leavers','Completed without achievement'],',','"','');foreach($groups as $label=>$g){fputcsv($f,array_map([\App\Support\LeaverDestinations::class,'csvCell'],[$label,$g['total'],$g['completed'],$g['withdrawn'],$g['terminal'],$g['withdrawal_rate'],$g['achieved'],$g['known_results'],$g['achievement_rate'],$g['missing_results'],$g['unknown'],$g['median_leaving_days'],$g['leaving_dates_known'],($g['small'] || ($byEmployer && $g['known_results'] < 10))?'Yes':'No',$g['early_leavers'],$g['not_achieved']]),',','"','');}fclose($f);},'GTA-Retention-Comparison.csv',['Content-Type'=>'text/csv','Cache-Control'=>'no-store, private']);
        }
        $sourceRows = collect($rows);
        $headlines = [
            'achievers' => $sourceRows->where('sourced_achievement', 'Achieved'),
            'early-leavers' => $sourceRows->where('withdrawal_timing', 'Early leaver'),
            'withdrawals' => $sourceRows->where('sourced_status', 'Withdrawn'),
            'active' => $sourceRows->where('sourced_status', 'Active'),
            'checks' => $sourceRows->filter(fn ($row) => $row['sourced_status'] === 'Unknown'
                || str_starts_with($row['withdrawal_timing'] ?? '', 'Check')),
        ];
        $detailRows = $headlines[$d['outcome'] ?? ''] ?? $sourceRows;
        $page = (int) ($d['page'] ?? 1);
        $rowPage = new \Illuminate\Pagination\LengthAwarePaginator($detailRows->forPage($page, 25)->values(),
            $detailRows->count(), 25, $page, ['path' => $r->url(), 'query' => $r->except('page')]);
        return response()->view('retention.report',['rows'=>$rowPage,'headlines'=>array_map(fn ($records) => $records->count(), $headlines),'selectedOutcome'=>$d['outcome']??null,'earlyWarning'=>RetentionEarlyWarning::forReport($rows),'attendanceByOutcome'=>RetentionAnalysis::attendanceByOutcome($rows),'skillsStarts'=>RetentionAnalysis::functionalSkillsStarts($rows),'markerLabels'=>$markerLabels,'markerTrends'=>RetentionMarkerAnalysis::trends($rows, $markerLabels),'unscheduled'=>$unscheduled,'cohortCount'=>count($rows),'groups'=>$groups,'factor'=>$factor,'excluded'=>$excluded,'milestoneGroups'=>$milestoneGroups,'entryFactors'=>$entryFactors,'programmes'=>\App\Models\Programme::orderBy('name')->get(['id','name']),'employerLabels'=>$employerLabels,'byEmployer'=>$byEmployer,'employerIdsByLabel'=>$employerLabels->flip(),'ageChoices'=>$this->choices()['age']])->header('Cache-Control','no-store, private');
    }
}
