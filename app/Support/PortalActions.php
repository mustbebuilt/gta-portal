<?php
namespace App\Support;

use App\Models\{Apprentice,User,ProgressReviewDraft};
use Illuminate\Database\Eloquent\Builder;

/** Read-only navigation. Every destination retains its own authorisation and save checks. */
class PortalActions
{
    public const TASKS = [
        'support'=>['title'=>'Start or continue a support plan','area'=>'Support','body'=>'Find the apprentice, check markers and the support need, then create or continue the plan.','keywords'=>'inclusion retention ASP concern'],
        'markers'=>['title'=>'Add or change inclusion markers','area'=>'Support','body'=>'Open the authorised marker controls in the apprentice support record.','keywords'=>'IM disability circumstances support'],
        'concern'=>['title'=>'Raise a support concern','area'=>'Support','body'=>'Record a routine support need for the Inclusion Team to check.','keywords'=>'retention need help'],
        'careers'=>['title'=>'Record a careers discussion','area'=>'Reviews and careers','body'=>'Open the discussion form for an apprentice in your careers caseload.','keywords'=>'advice guidance progression employment'],
        'reviews'=>['title'=>'Prepare or continue a progress review','area'=>'Reviews and careers','body'=>'Continue a saved review or prepare a new draft. Signing remains a separate step.','keywords'=>'ten week tri party meeting draft'],
        'appointments'=>['title'=>'Book or manage a review appointment','area'=>'Reviews and careers','body'=>'Check existing appointments before booking a meeting.','keywords'=>'calendar meeting tri party'],
        'maths'=>['title'=>'Manage maths training and results','area'=>'Training','body'=>'Open qualifications, results and assigned maths topics.','keywords'=>'functional skills qualification assessment'],
        'english'=>['title'=>'Manage English training and results','area'=>'Training','body'=>'Open qualifications, results and assigned English topics.','keywords'=>'functional skills qualification assessment'],
        'training'=>['title'=>'Open training blocks and tasks','area'=>'Training','body'=>'Find allocated work, submissions and the normal marking controls.','keywords'=>'evidence photo upload marking block'],
        'attendance'=>['title'=>'View attendance and off-the-job training','area'=>'Training','body'=>'Open the apprentice attendance record and its training hours.','keywords'=>'OTJ register absence'],
        'delivery'=>['title'=>'Open the individual delivery plan','area'=>'Training','body'=>'Check the block calendar, current approval and task deadlines at source.','keywords'=>'programme planning dates'],
        'employer'=>['title'=>'Manage employer requests and replies','area'=>'Administration','body'=>'Open existing requests or use the normal source workflow to prepare one.','keywords'=>'workplace confirmation review target'],
        'checks'=>['title'=>'Check an apprentice record','area'=>'Administration','body'=>'See missing links and source checks, with routes to the original records.','keywords'=>'setup missing staff programme account dates']
    ];
    public static function actor(User $u): User
    {
        $fresh=User::findOrFail($u->id);
        abort_unless($fresh->active && !AccountLifecycle::blocked($fresh),403);
        return $fresh;
    }
    public static function allowed(User $u,string $task):bool
    {
        if(!$u->active||!isset(self::TASKS[$task])){return false;}
        $manager=LearningAccess::manager($u);
        return match($task){
            'support'=>SupportAccess::team($u),'markers'=>SupportAccess::lead($u),
            'concern','employer','checks'=>$manager||$u->role==='tutor',
            'careers'=>$manager||StaffDuties::has($u,'careers_adviser'),
            'reviews','appointments'=>ReviewReminders::allowed($u),
            'maths','english'=>$manager||StaffDuties::has($u,$task.'_tutor'),
            default=>$manager||in_array($u->role,['tutor','apprentice'],true)
        };
    }
    public static function people(User $u,string $task):Builder
    {
        abort_unless(self::allowed($u,$task),403);
        $q=Apprentice::query();
        if(LearningAccess::manager($u)){return $q;}
        if(in_array($task,['support','markers'],true)){
            $s=SupportAccess::settings($u);
            if($s['scope']==='all'){return $q;}
            return $q->where(function($q)use($u,$s){
                $q->whereIn('id',$s['apprentice_ids'])->orWhereIn('tutor_group_id',$s['group_ids']);
                foreach(['tutor_id','maths_tutor_id','english_tutor_id','mentor_id','careers_adviser_id']as$key){$q->orWhere($key,$u->id);}
                $q->orWhereHas('groupRecord',fn($g)=>$g->where('tutor_id',$u->id));
            });
        }
        if($task==='careers'){return $q->where('careers_adviser_id',$u->id);}
        if(in_array($task,['maths','english'],true)){return $q->where($task.'_tutor_id',$u->id);}
        if(in_array($task,['reviews','appointments'],true)){
            return $q->where(function($q)use($u){$q->whereRaw('1=0');if($u->role==='tutor'){$q->orWhere('tutor_id',$u->id);}if(StaffDuties::has($u,'mentor')){$q->orWhere('mentor_id',$u->id);}});
        }
        return $q->where($u->role==='apprentice'?'user_id':'tutor_id',$u->id);
    }
    public static function hasPerson(User $u,Apprentice $a):bool
    {
        foreach(array_keys(self::TASKS)as$key){if(self::allowed($u,$key)&&self::people($u,$key)->whereKey($a->id)->exists()){return true;}}
        return false;
    }
    public static function forPerson(User $u,Apprentice $a):array
    {
        $cards=[];
        foreach(self::TASKS as $key=>$row){
            if(self::allowed($u,$key)&&self::people($u,$key)->whereKey($a->id)->exists()){$cards[$key]=$row+['key'=>$key,'url'=>self::destination($u,$a,$key)];}
        }
        return $cards;
    }
    public static function destination(User $u,Apprentice $a,string $key):string
    {
        abort_unless(self::allowed($u,$key)&&self::people($u,$key)->whereKey($a->id)->exists(),403);
        return match($key){
            'support'=>route('support.index',$a).'#support-plan-list',
            'markers'=>route('support.index',$a).'#support-markers',
            'concern'=>route('support.index',$a).'#support-new-concern',
            'careers'=>route('careers.index',$a).'#careers-discussion-form',
            'reviews'=>self::reviewUrl($a),
            'appointments'=>route('review-appointments.index',['search'=>$a->reference]),
            'maths','english'=>route('em.index',[$a,'subject'=>$key]),
            'training'=>route('learning.index',$a),'attendance'=>route('attendance.apprentice',$a),
            'delivery'=>route('delivery.show',$a),'employer'=>route('employer-actions.staff',$a),
            'checks'=>route('record-checks.show',$a)
        };
    }
    private static function reviewUrl(Apprentice $a):string
    {
        // Existing draft state only: no fields, private answers or automatic review creation.
        $saved=ProgressReviewDraft::where('apprentice_id',$a->id)->where('state','Draft')->orderByDesc('updated_at')->orderByDesc('id')->first(['id']);
        return $saved?route('reviews.edit',[$a,$saved]):route('reviews.create',$a);
    }
    public static function globals(User $u):array
    {
        $rows=[];$add=function($key,$title,$route,$body,$params=[])use(&$rows){$rows[$key]=['title'=>$title,'body'=>$body,'url'=>route($route,$params),'area'=>'Your portal'];};
        if(LearningAccess::manager($u)){
            $add('new-apprentice','Add an apprentice','apprentices.create','Create the record, then check its staff, programme and account links.');
            $add('new-staff','Add a member of staff','directories.create','Create a staff record and assign the authorised role and duties.',['staff']);
            $add('new-employer','Add an employer','directories.create','Create the employer record before linking apprentices.',['employers']);
            $add('new-group','Add a tutor group','directories.create','Set up a group with the correct programme and tutor.',['groups']);
            $add('assignments','Check staff assignments','assignment-checks.index','Find missing, inactive or changed-role assignments.');
            $add('accounts','Manage account access','account-operations.index','Use the reviewed restriction, archive or restoration workflow.');
            $add('record-checks','Check apprentice records','record-checks.index','Find missing record links and open source checks.');
            $add('progress','View apprentice progress reports','cohort-insights.index','Check current cohort context, estimates and saved reports.');
        }
        if($u->role==='tutor'){$add('record-checks','Check apprentice records','record-checks.index','Check only apprentices currently assigned to you.');}
        if(SavedPortalWork::allowed($u)){$add('saved','Continue saved work','saved-work.index','Return to current review and support-plan drafts or delivery plans awaiting approval.');}
        if(StaffAttention::allowed($u)){$add('attention','See who needs attention','attention.index','Open the existing attention list and its reasons.');$add('followup','Manage staff follow-up','operations-desk.index','Continue owned follow-up without changing the underlying source.');}
        if($u->role==='apprentice'){
            $add('next','See my next step','experience.home','Open your next task, training and outstanding actions.');
            $add('help','Ask for help','experience.index','Use the existing private help request process.');
            $add('alerts','Change my alert preferences','alerts.index','Choose email, text or both using your current verified details.');
            $add('careers-view','View my careers advice','careers.worklist','Open the advice and actions recorded for you.');
        }
        if(in_array($u->role,['admin','ceo','tutor','apprentice'],true)){$add('report',$u->role==='apprentice'?'Open my report':'Open visual report','visual-reports.index','Charts, exact tables, current source context and a complete PDF.');}
        if($u->role==='employer'){$add('employer-actions','Respond to employer actions','employer-actions.index','See only requests currently shared with your employer account.');}
        if(in_array($u->role,['apprentice','employer'],true)){$add('signing','View review signing requests','electronic-reviews.index','Open only current requests available to your account.');}
        $add('guides','Find a how-to guide','help.index','Search the guides available to your role.');
        return $rows;
    }
}
