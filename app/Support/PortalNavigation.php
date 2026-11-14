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
        $group=function(string $id,string $label,array $links)use(&$groups):void {
            if(!$links) {return;}
            $groups[]=['id'=>$id,'label'=>$label,'links'=>$links,'open'=>collect($links)->contains(fn($l)=>$l['current'])];
        };
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
            $links[]=$item('Groups and sessions','attendance.index',[],['attendance.*']);$links[]=$item('Marking worklist','marking.index',[],['marking.*']);
        }
        $group('training','Apprentices & training',$links);
        $links=[];
        if(ReviewReminders::allowed($u)) {$links[]=$item('Reviews','reviews.index',[],['reviews.*']);}
        if(in_array($u->role,['admin','ceo','apprentice'],true)||StaffDuties::has($u,'careers_adviser')) {$links[]=$item('Careers','careers.worklist',[],['careers.*']);}
        $group('reviews-careers','Reviews & Careers',$links);
        $links=[];
        if(SupportAccess::team($u)) {
            $links[]=$item('Support and retention','support.worklist',[],[], $request->routeIs('support.*')&&!$request->routeIs('support.permissions'));
            $links[]=$item('Support review reminders','support-review-reminders.index',[],['support-review-reminders.*']);
        }
        if(QuestionnaireLinks::canView($u)) {$links[]=$item('Questionnaires','questionnaires.index');}
        $group('support','Support',$links);
        $manage=Gate::forUser($u)->allows('manage-directories');$links=[];
        if(!in_array($u->role,['apprentice','employer'],true)) {$links[]=$item('Staff development','staff.index',[],['staff.*']);}
        if($u->role!=='apprentice') {$links[]=$item('Case studies','cases.index',[],['cases.*']);}
        if($manage) {$links[]=$item('Curriculum source checks','curriculum-sources.index',[],['curriculum-sources.*']);}
        $group('staff-quality','Staff & quality',$links);
        $links=[];
        if($manage) {
            $links[]=$item('Portal setup','setup.index',[],['setup.*','assignment-checks.*']);
            foreach(['staff'=>'Staff','employers'=>'Employers','groups'=>'Tutor groups','programmes'=>'Programmes']as$kind=>$label) {
                $links[]=$item($label,'directories.index',[$kind],[], $request->routeIs('directories.*')&&$request->route('kind')===$kind);
            }
            $links[]=$item('Staff onboarding','onboarding.index',[],['onboarding.*']);
            $links[]=$item('Questionnaire link setup','questionnaires.configuration');
        }
        if(in_array($u->role,['admin','ceo'],true)) {$links[]=$item('Support permissions','support.permissions');}
        $group('administration','Administration',$links);
        $links=[];
        if($manage) {
            $links[]=$item('Evidence recovery rehearsals','evidence-restore.index',[],['evidence-restore.*']);
            $links[]=$item('Reminder mail pilot','reminder-mail.index',[],['reminder-mail.*']);
        }
        if($u->role==='ceo') {$links[]=$item('CEO emergency access','executive.index',[],['executive.*']);}
        $group('system','System tools',$links);
        $links=[];
        if(SecondFactor::required($u)||SecondFactor::mayEnrol($u)) {$links[]=$item('Two-step sign-in','second-factor.settings',[],['second-factor.settings','second-factor.change']);}
        $links[]=$item('Confirm email','account.email',[],['account.email*']);$links[]=$item('Change password','account.password',[],['account.password*']);
        $group('account','My account',$links);
        return ['primary'=>$primary,'groups'=>$groups,'help'=>$item('Help and how to','help.index',[],['help.*'])];
    }
}
