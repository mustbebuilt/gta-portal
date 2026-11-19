<?php
namespace App\Support;

use App\Models\{Apprentice,User,LearningTask,TrainingTopic,Qualification,ProgrammeOutcome,SignedReviewRecord,ApprenticeExperienceEntry};
use Illuminate\Support\Facades\DB;

/** Safe read-only journey projection. Never serialise narrative fields or raw source objects. */
class ApprenticeRoadmap67
{
    public const TYPES = [
        'start'=>['Programme','Programme started'],
        'plan_confirmed'=>['Programme','Training plan confirmed'],
        'employer'=>['Employment','Employer changed'],
        'employer_record'=>['Employment','Employer link updated'],
        'tutor'=>['Training','Main tutor changed'],
        'group'=>['Training','Training group changed'],
        'block'=>['Training','Training block tasks approved'],
        'english'=>['Qualifications','English topic completed'],
        'maths'=>['Qualifications','Maths topic completed'],
        'english_result'=>['Qualifications','English qualification achieved'],
        'maths_result'=>['Qualifications','Maths qualification achieved'],
        'qualification'=>['Qualifications','Qualification achieved'],
        'review'=>['Reviews','Progress review completed'],
        'review_booking'=>['Reviews','Upcoming progress review'],
        'gateway'=>['Programme','Gateway decision recorded'],
        'extra'=>['Training','Additional training completed'],
        'achievement'=>['Achievements','Workplace achievement confirmed'],
        'completion'=>['Programme','Programme achievement recorded'],
        'practical_end'=>['Programme','Planned practical end'],
        'planned_end'=>['Programme','Planned programme end'],
    ];

    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) return null;
        [$y,$m,$d]=array_map('intval',explode('-',$value));
        return checkdate($m,$d,$y)?$value:null;
    }

    /** Closed schema: even a recognised event cannot smuggle notes, reasons or URLs through. */
    public static function project(array $events,string $asOf): array
    {
        $out=[];
        foreach ($events as $e) {
            $type=$e['type']??null;$date=self::date($e['date']??null);
            if (!is_string($type) || !isset(self::TYPES[$type]) || !$date) continue;
            $planned=in_array($type,['practical_end','planned_end','review_booking'],true);
            if (!$planned && $date>$asOf) continue;
            [$category,$title]=self::TYPES[$type];
            $block=filter_var($e['block']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10]]);
            if ($type==='block') {if ($block===false) continue;$title='Block '.$block.' training tasks approved';}
            $out[]=['date'=>$date,'type'=>$type,'category'=>$category,'title'=>$title,'status'=>$planned?'Planned':'Recorded','block'=>$type==='block'?$block:null];
        }
        // Deduplicate equivalent summaries. No raw title/detail/owner/reason is returned.
        $unique=[];foreach($out as $e)$unique[$e['date'].'|'.$e['type'].'|'.($e['block']??'')]=$e;
        $out=array_values($unique);usort($out,fn($a,$b)=>[$a['date'],$a['type'],$a['block']??0]<=>[$b['date'],$b['type'],$b['block']??0]);
        return $out;
    }

    /** Marker describes the next incomplete task block, never elapsed time or overall achievement. */
    public static function compose(array $blocks,array $events,string $asOf,bool $achieved=false):array
    {
        $stops=[];$current=null;$hasAllocation=false;$allTraining=true;$trainingCount=0;
        foreach($blocks as $b){
            $n=(int)($b['number']??$b['block']??0);if($n<1||$n>12)continue;
            $complete=($b['complete']??false)===true;
            if($n<=10){$trainingCount++;$allocated=$b['allocated']??null;$hasAllocation=$hasAllocation||($allocated>0);$allTraining=$allTraining&&$complete;
                if(!$achieved&&$current===null&&!$complete&&$allocated>0)$current=$n;
            }
            $stops[]=['number'=>$n,'title'=>$n<=10?'Training block '.$n:($n===11?'Gateway':'EPA / achievement'),'complete'=>$complete,'status'=>$n<=10?($complete?'Training tasks approved':(($b['allocated']??0)>0?'Training tasks remain':'Allocation not recorded')):($complete?'Recorded milestone':'Separate record required')];
        }
        if(!$achieved&&$current===null&&$hasAllocation&&$allTraining&&$trainingCount===10)$current=11;
        if($achieved)$current=12;
        foreach($stops as &$stop){$stop['current']=$stop['number']===$current;$stop['pulse']=$stop['current']&&!$achieved;
            if($achieved&&$stop['number']===12){$stop['complete']=true;$stop['status']='Programme achievement recorded';}
        }unset($stop);
        return ['stops'=>$stops,'current'=>$current,'position'=>$achieved?'Programme achievement recorded':($current===null?'Current position not recorded':($current<=10?'Next incomplete training block: '.$current:'Training tasks approved — Gateway preparation')),'basis'=>'Your marker shows the next training block with tasks still to be approved. Gateway and EPA need their own confirmation.','events'=>self::project($events,$asOf),'coverage'=>'Your journey shows events recorded by GTA. Some earlier events or dates may be missing.'];
    }

    public static function live(User $u,Apprentice $a,array $blocks,array $otjRows=[]):array
    {
        abort_unless(LearningAccess::training($u,$a),403);
        if($u->role==='apprentice')abort_unless(ApprenticeExperience::profile($u)?->id===$a->id,403);
        $today=today()->toDateString();$events=[];
        $setup=\App\Models\ApprenticeSetupDraft68::where('apprentice_id',$a->id)->first();
        foreach($setup?->history??[] as $e)if(($e['event']??null)==='setup_confirmed')$events[]=['type'=>'plan_confirmed','date'=>substr($e['at'],0,10)];
        if($a->start_date)$events[]=['type'=>'start','date'=>$a->start_date->toDateString()];
        $practicalEnd = DeliveryPlan::approved($a)['details']['practical_end_date'] ?? null;
        if ($practicalEnd) $events[] = ['type'=>'practical_end','date'=>$practicalEnd];
        if($a->planned_end_date)$events[]=['type'=>'planned_end','date'=>$a->planned_end_date->toDateString()];
        // Dated employment changes are authoritative for their effective date. Do not expose their encrypted payload/reasons.
        $datedEmployment=false;
        if(class_exists(\App\Models\EntryContextEvent::class)){
            $prior=\App\Models\ApprenticeEntryContext::where('apprentice_id',$a->id)->value('entry_employer_id');
            foreach(\App\Models\EntryContextEvent::where('apprentice_id',$a->id)->where('kind','Employment')->whereNotNull('effective_on')->orderBy('effective_on')->orderBy('id')->get() as $e){
                $next=$e->payload['after']['entry_employer_id']??null;
                if($next&&$prior&&(int)$next!==(int)$prior){$events[]=['type'=>'employer','date'=>$e->effective_on->toDateString()];$datedEmployment=true;}
                if($next)$prior=$next;
            }
        }
        // A saved profile edit date is labelled separately; it is not an employment effective date.
        foreach(DB::table('assignment_change_events')->where('apprentice_id',$a->id)->orderBy('id')->get(['before_links','after_links','created_at']) as $e){
            $before=json_decode($e->before_links??'null',true);$after=json_decode($e->after_links??'null',true);
            if(!is_array($before)||!is_array($after))continue;
            foreach(['employer_id'=>'employer_record','tutor_id'=>'tutor','tutor_group_id'=>'group'] as $key=>$type){
                if($type==='employer_record'&&$datedEmployment)continue;
                if(($before[$key]??null)&&($after[$key]??null)&&(string)$before[$key]!== (string)$after[$key])$events[]=['type'=>$type,'date'=>substr((string)$e->created_at,0,10)];
            }
        }
        $tasks=LearningTask::where('apprentice_id',$a->id)->get();
        foreach($blocks as $b){$n=(int)($b['number']??$b['block']??0);if($n>10||!($b['complete']??false))continue;$dates=[];
            foreach($tasks->where('block',$n) as $t){$last=collect($t->submissions??[])->last();$date=self::date(substr((string)($last['decision']['date']??''),0,10));if($date&&$date<=$today)$dates[]=$date;}
            if(count($dates)===5)$events[]=['type'=>'block','block'=>$n,'date'=>max($dates)];
        }
        foreach(TrainingTopic::where('apprentice_id',$a->id)->whereIn('subject',['english','maths'])->where('status','Completed')->whereNotNull('completion_date')->get(['subject','completion_date']) as $t)$events[]=['type'=>$t->subject,'date'=>$t->completion_date->toDateString()];
        foreach(Qualification::where('apprentice_id',$a->id)->where('status','Achieved')->whereNotNull('achievement_date')->get(['subject','achievement_date']) as $q)$events[]=['type'=>in_array($q->subject,['english','maths'],true)?$q->subject.'_result':'qualification','date'=>$q->achievement_date->toDateString()];
        foreach(SignedReviewRecord::where('apprentice_id',$a->id)->whereNotNull('checked_at')->whereNull('withdrawn_at')->get() as $r)if(SignedReviews::intact($r))$events[]=['type'=>'review','date'=>$r->meeting_date->toDateString()];
        $appointment=class_exists(ReviewNextStep::class)?ReviewNextStep::for($u,$a)['appointment']:\App\Models\ReviewAppointment::where('apprentice_id',$a->id)->where('state','Published')->where('ends_at','>',now()->utc())->orderBy('starts_at')->limit(50)->get()->first(fn($meeting)=>ReviewAppointments::published($meeting)&&ReviewAppointments::current($meeting)&&ReviewAppointments::visible($u,$meeting));if($appointment&&$appointment->starts_at->gte(now()))$events[]=['type'=>'review_booking','date'=>$appointment->starts_at->timezone('Europe/London')->toDateString()];
        foreach($otjRows as $o)if(($o['kind']??null)==='extra'&&($o['state']??null)==='Approved extra training'&&($o['minutes']??0)>0)$events[]=['type'=>'extra','date'=>$o['date']];
        foreach(ApprenticeExperienceEntry::where('apprentice_id',$a->id)->where('kind','achievement')->where('state','Approved')->whereNotNull('happened_on')->get(['happened_on']) as $e)$events[]=['type'=>'achievement','date'=>$e->happened_on->toDateString()];
        $c=ProgrammeOutcome::where('apprentice_id',$a->id)->first()?->context??[];
        $achievement=self::date($c['achievementDate']??null);$achieved=($c['status']??null)==='Completed'&&($c['achievement']??null)==='Achieved'&&filled($c['sourceRef']??null)&&$achievement&&$achievement<=$today;
        if($achieved)$events[]=['type'=>'completion','date'=>$achievement];
        return self::compose($blocks,$events,$today,(bool)$achieved);
    }
}
