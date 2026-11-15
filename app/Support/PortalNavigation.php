<?php
namespace App\Support;
use App\Models\{Apprentice,User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PortalNavigation
{
    public static function build(User $u, Request $request): array
    {
        if(!$u->active) {return ['primary'=>[],'groups'=>[],'help'=>null];}
        $item=fn(string $label,string $route,array $parameters=[],array $patterns=[],?bool $current=null)=>[
            'label'=>$label,'href'=>route($route,$parameters),'current'=>$current??$request->routeIs(...($patterns?:[$route]))];
        $primary=[];$groups=[];
        $primary[]=$item('Quick actions','quick-actions.index',[],['quick-actions.*']);
        if($u->role==='employer') {$primary[]=$item('Getting started','employer-start.index',[],['employer-start.*']);$primary[]=$item('My employer actions','employer-actions.index',[],['employer-actions.index','employer-actions.show','employer-actions.respond']);}
        if(StaffAttention::allowed($u)) {$primary[]=$item('Needs attention','attention.index');}
        if($u->role==='apprentice') {$primary[]=$item('My next step','experience.home');$primary[]=$item('Getting started','getting-started.index',[],['getting-started.*']);}
        $group=function(string $id,string $label,array $links)use(&$groups):void {
            if(!$links) {return;}
            $groups[]=['id'=>$id,'label'=>$label,'links'=>$links,'open'=>collect($links)->contains(fn($l)=>$l['current'])];
        };
        if(StaffHandover::recipientAllowed($u)) {$primary[]=$item('Staff handovers','incoming-handovers.index',[],['incoming-handovers.*']);}
        if(MyResponsibilities::allowed($u)) {$primary[]=$item('My responsibilities','responsibilities.index',[],['responsibilities.*']);}
        if(in_array($u->role,['admin','ceo','tutor','apprentice'],true)) {$primary[]=$item('Dashboard','dashboard.index',[],['dashboard.*']);}
        $links=[];
        if(Gate::forUser($u)->allows('viewAny',Apprentice::class)) {
            $links[]=$item('Apprentices','apprentices.index',[],['apprentices.*','learning.*','tasks.*','blocks.*','plans.*','delivery.*','actions.*','otj.*','extra.*','scorecards.*']);
        }
        if(TaskLibrary::allowed($u)) {$links[]=$item('Task library','task-library.index',[],['task-library.*']);}
        if(in_array($u->role,['admin','ceo','tutor','apprentice','functional_skills_tutor'],true)||StaffDuties::has($u,'maths_tutor')||StaffDuties::has($u,'english_tutor')) {
            $links[]=$item('Maths and English','em.caseload',[],['em.*','topics.*','qualifications.*']);
        }
        if(in_array($u->role,['admin','ceo','tutor'],true)) {
            $links[]=$item('Groups and sessions','attendance.index',[],['attendance.*']);$links[]=$item('Session operations','session-operations.index',[],['session-operations.*','session-changes.*','register-actions.*']);$links[]=$item('Marking worklist','marking.index',[],['marking.*']);
        }
        if(in_array($u->role,['admin','ceo','tutor'],true)){$links[]=$item('Gateway preparation','gateway.index',[],['gateway.*']);}
        if(CentrePlanning::staff($u)){$links[]=$item(CentrePlanning::allowed($u)?'Centre timetable':'Timetable','centre-planning.index',[],['centre-planning.*','work-moves.*','timetable-duties.*','timetable-governance.*','timetable-management.*']);}
        if(LearningAccess::manager($u)){$links[]=$item('Apprentice progress','cohort-insights.index',[],['cohort-insights.*']);}
        if(\App\Support\ApprenticeRecordChecks::allowed($u)){$links[]=$item('Record checks','record-checks.index',[],['record-checks.*']);}
        $group('training','Apprentices & training',$links);
        $links=[];
        if(ReviewReminders::allowed($u)) {$links[]=$item('Reviews','reviews.index',[],['reviews.*']);}
        if(in_array($u->role,['admin','ceo','apprentice'],true)||StaffDuties::has($u,'careers_adviser')) {$links[]=$item('Careers','careers.worklist',[],['careers.*']);}
        if(in_array($u->role,['admin','ceo','tutor','apprentice','employer'],true)||StaffDuties::has($u,'mentor')){$links[]=$item('Review appointments','review-appointments.index',[],['review-appointments.*']);$links[]=$item('Review signing','electronic-reviews.index',[],['electronic-reviews.*','review-authority.*']);}
        if(LeaverDestinations::allowed($u)) {$links[]=$item('Leaver follow-up','destinations.index',[],['destinations.*']);}
        $group('reviews-careers','Reviews & Careers',$links);
        $links=[];
        if(SupportAccess::team($u)) {
            $links[]=$item('Support and retention','support.worklist',[],[], $request->routeIs('support.*')&&!$request->routeIs('support.permissions'));
            $links[]=$item('Support review reminders','support-review-reminders.index',[],['support-review-reminders.*']);
        }
        if(QuestionnaireLinks::canView($u)) {$links[]=$item('Questionnaires','questionnaires.index',[],['questionnaires.index','portal-questionnaires.*']);}
        if($u->role==='apprentice'||ApprenticeExperience::queueAllowed($u)) {$links[]=$item($u->role==='apprentice'?'My help & achievements':'Help & achievement responses','experience.index',[],['experience.index','experience.create','experience.respond']);}
        if(in_array($u->role,['apprentice','employer'],true)||SupportAccess::team($u)) {$links[]=$item('Workplace support sharing','workplace-support.index',[],['workplace-support.*']);}
        $group('support','Support',$links);
        $manage=Gate::forUser($u)->allows('manage-directories');$links=[];
        if(!in_array($u->role,['apprentice','employer'],true)) {$links[]=$item('Staff development','staff.index',[],['staff.*']);}
        if($u->role!=='apprentice') {$links[]=$item('Case studies','cases.index',[],['cases.*']);}
        if($manage) {$links[]=$item('Curriculum source checks','curriculum-sources.index',[],['curriculum-sources.*']);}
        if(StaffAttention::allowed($u)){$links[]=$item('Staff follow-up','operations-desk.index',[],['operations-desk.*']);}
        if(SavedPortalWork::allowed($u)){$links[]=$item('Saved work','saved-work.index',[],['saved-work.*']);}
        $group('staff-quality','Staff & quality',$links);
        $links=[];
        if($manage) {
            $links[]=$item('Portal setup','setup.index',[],['setup.*','assignment-checks.*']);
            foreach(['staff'=>'Staff','employers'=>'Employers','groups'=>'Tutor groups','programmes'=>'Programmes']as$kind=>$label) {
                $links[]=$item($label,'directories.index',[$kind],[], $request->routeIs('directories.*')&&$request->route('kind')===$kind);
            }
            $links[]=$item('Employer accounts','employer-accounts.index',[],['employer-accounts.*','employer-grants.*','employer-setup.*']);
            $links[]=$item('Apprentice intake','intakes.index',[],['intakes.*','cohort-setup.*','setup-cases.*']);
            $links[]=$item('Apprentice onboarding','apprentice-onboarding.index',[],['apprentice-onboarding.*']);
            $links[]=$item('Staff onboarding','onboarding.index',[],['onboarding.*']);
            $links[]=$item('Questionnaire link setup','questionnaires.configuration');
        }
        if(in_array($u->role,['admin','ceo'],true)) {$links[]=$item('Support permissions','support.permissions');}
        if(LearningAccess::manager($u)){$links[]=$item('Account access','account-operations.index',[],['account-operations.*']);}
        $group('administration','Administration',$links);
        $links=[];
        if($manage) {
            $links[]=$item('Evidence recovery rehearsals','evidence-restore.index',[],['evidence-restore.*']);
            $links[]=$item('Staff caseload handovers','staff-handovers.index',[],['staff-handovers.*']);
            $links[]=$item('Staging acceptance','acceptance.index',[],['acceptance.*']);
            $links[]=$item('Reminder delivery outcomes','receipt-operations.index',[],['receipt-operations.*']);
            $links[]=$item('Apprentice reminder delivery','apprentice-notifications.index',[],['apprentice-notifications.*']);
            $links[]=$item('Reminder mail pilot','reminder-mail.index',[],['reminder-mail.*']);
        }
        if($u->role==='ceo') {$links[]=$item('CEO emergency access','executive.index',[],['executive.*']);}
        $group('system','System tools',$links);
        $links=[];
        if(SecondFactor::required($u)||SecondFactor::mayEnrol($u)) {$links[]=$item('Two-step sign-in','second-factor.settings',[],['second-factor.settings','second-factor.change']);}
        if($u->role==='apprentice') {$links[]=$item('My reminders','apprentice-reminders.index',[],['apprentice-reminders.*']);}
        if($u->role==='apprentice') {$links[]=$item('Alert preferences','alerts.index',[],['alerts.*']);}
        $links[]=$item('Confirm email','account.email',[],['account.email*']);$links[]=$item('Change password','account.password',[],['account.password*']);
        $group('account','My account',$links);
        return ['primary'=>$primary,'groups'=>$groups,'help'=>$item('Help and how to','help.index',[],['help.*'])];
    }
}
