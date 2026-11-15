<?php
namespace App\Support;
use App\Models\{Apprentice,User,LearningTask};

/** Record completeness prompts, never qualification, Gateway or funding eligibility decisions. */
class ApprenticeRecordChecks
{
    public const FILTERS=['all'=>'All records','programme'=>'No linked programme','tutor'=>'No main tutor','employer'=>'No linked employer','group'=>'No linked tutor group','dates'=>'Missing start or planned end date','account'=>'No linked apprentice account'];
    public static function allowed(User $u,?Apprentice $a=null):bool
    {
        return LearningAccess::manager($u)||($u->active&&$u->role==='tutor'&&(!$a||$a->tutor_id===$u->id));
    }
    public static function rows(User $u,Apprentice $a):array
    {
        abort_unless(self::allowed($u,$a),403);
        $a->load(['programmeRecord','employerRecord','groupRecord','tutor','mentor','careersAdviser','mathsTutor','englishTutor']);
        $rows=[];
        $add=function($key,$label,$position,$text,$url=null)use(&$rows){$rows[$key]=compact('key','label','position','text','url');};
        $edit=LearningAccess::manager($u)?route('apprentices.edit',$a):null;
        $p=$a->programmeRecord;
        $add('programme','Linked programme',$p&&$p->active?'recorded':'check',$p?($p->active?'An active programme record is linked.':'The linked programme is inactive; check the intended programme/version.'):'No programme record is linked. A typed programme name alone is not a checked link.',$edit);
        $e=$a->employerRecord;
        $add('employer','Linked employer',$e&&$e->active?'recorded':'check',$e?($e->active?'An active employer record is linked.':'The linked employer is inactive; check the current employment position.'):'No employer record is linked. Check the current employment position before making a change.',$edit);
        $g=$a->groupRecord;
        $groupOK=$a->programme_id&&$a->tutor_id&&$g&&$g->active&&$g->programme_id===$a->programme_id&&$g->tutor_id===$a->tutor_id;
        $add('group','Tutor group',$groupOK?'recorded':'check',$groupOK?'The active group matches the saved programme and main tutor.':'The linked group is missing, inactive or differs from the saved programme/tutor. Check the original records.',$edit);
        $dates=$a->start_date&&$a->planned_end_date&&$a->planned_end_date->gte($a->start_date);
        $add('dates','Learning dates',$dates?'recorded':'check',$dates?'Start and planned end dates are recorded in chronological order. This does not verify the planned duration.':'Record and check both dates; the planned end must not precede the start.',$edit);
        foreach(['tutor'=>'Main tutor','mentor'=>'Learning and Progress Mentor','careers_adviser'=>'Careers adviser','maths_tutor'=>'Maths tutor','english_tutor'=>'English tutor']as$key=>$label){
            $id=$a->{$key.'_id'};$person=$id?User::find($id):null;
            $eligible=$person&&$person->active&&!AccountLifecycle::blocked($person)&&($key==='tutor'?$person->role==='tutor':StaffDuties::has($person,$key));
            $optional=in_array($key,['maths_tutor','english_tutor'],true)&&!$id;
            $add($key,$label,$eligible?'recorded':($optional?'check-if-needed':'check'),$eligible?'A current active account with the matching role or duty is linked.':($optional?'No subject tutor is linked. Check the assessed training requirement; do not infer an exemption.':'The assignment is missing or the linked account/role/duty needs checking.'),$edit);
        }
        $account=$a->user_id?User::find($a->user_id):null;
        $ok=$account&&$account->active&&$account->role==='apprentice'&&!AccountLifecycle::blocked($account);
        $add('account','Apprentice portal account',$ok?'recorded':'check',$ok?'An active apprentice account is linked. Successful sign-in and verified recovery still need their normal checks.':'No current active apprentice account is established. Use the normal verified onboarding process.',LearningAccess::manager($u)?route('apprentice-onboarding.index',['search'=>$a->reference]):null);
        // Aggregate metadata only; no task answers, private evidence or review/support bodies.
        $total=LearningTask::where('apprentice_id',$a->id)->count();
        $pending=LearningTask::where('apprentice_id',$a->id)->where('template->kind','source-pending')->count();
        $add('tasks','Allocated training tasks',$total>0&&$pending===0?'recorded':'check',$total===0?'No task records are allocated. Check the current programme task source.':($pending?'Some allocated tasks still need a checked source. Open the task list.':'Task records are allocated. Allocation does not mean tasks are approved or the programme is complete.'),route('learning.index',$a));
        $plan=DeliveryPlan::approved($a);
        $add('delivery','Provider-approved delivery plan',$plan?'recorded':'check',$plan?'A current provider-approved plan is available. Participant signatures and funding decisions remain separate.':'A current provider-approved plan is not established. Open the plan to check the draft, source and approval.',route('delivery.show',$a));
        return $rows;
    }
}
