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
        if($u->role==='employer') {$primary[]=$item('Getting started','employer-start.index',[],['employer-start.*']);$primary[]=$item('My employer actions','employer-actions.index',[],['employer-actions.index','employer-actions.show','employer-actions.respond']);}
        if(StaffAttention::allowed($u)) {$primary[]=$item('Needs attention','attention.index',[],['attention.index','operations-desk.*']);}
        if($u->role==='apprentice') {$primary[]=$item('My next step','experience.home');$primary[]=$item('Getting started','getting-started.index',[],['getting-started.*']);}
        $group=function(string $id,string $label,array $links)use(&$groups):void {
            if(!$links) {return;}
            $groups[]=['id'=>$id,'label'=>$label,'links'=>$links,'open'=>collect($links)->contains(fn($l)=>$l['current'])];
        };
        if(StaffHandover::recipientAllowed($u)) {$primary[]=$item('Staff handovers','incoming-handovers.index',[],['incoming-handovers.*']);}
        if(MyResponsibilities::allowed($u)) {$primary[]=$item('My responsibilities','responsibilities.index',[],['responsibilities.*']);}
        if(in_array($u->role,['admin','ceo','tutor','apprentice'],true)) {$primary[]=$item($u->role === 'apprentice' ? 'My dashboard' : 'Work today','dashboard.index',[],['dashboard.index']);}
        $reports = ReportDirectory::links($u);
        if ($reports) {
            $primary[] = $item('Reports','reports.index',[],['reports.index', ...array_keys($reports), 'dashboard.pdf', 'visual-reports.pdf', 'historical-cohorts.*', ... (isset($reports['timetable-management.index']) ? ['timetable-management.*'] : [])]);
        }
        if(in_array($u->role,['admin','ceo','tutor','apprentice','employer','mentor'],true)||StaffDuties::has($u,'mentor')||($u->role==='manager'&&CentreManagerAccess::workspace($u))) {$primary[]=$item($u->role==='apprentice'?'My progress':'View progress','progress66.index',[],['progress66.*']);}
        $links=[];
        if(Gate::forUser($u)->allows('viewAny',Apprentice::class)) {
            $primary[]=$item('Apprentices','apprentices.index',[],['apprentices.*','learning.*','tasks.*','blocks.*','plans.*','delivery.*','actions.*','otj.*','extra.*','scorecards.*']);
        }
        if(Gate::forUser($u)->allows('create', Apprentice::class)) {
            $primary[]=$item('Add apprentice','setup68.index',[],['setup68.*']);
            $primary[]=$item('Add or link employer','employer68.show',[],['employer68.*']);
        }
        if(TaskLibrary::allowed($u)) {$links[]=$item('Task library','task-library.index',[],['task-library.*']);}
        if(in_array($u->role,['admin','ceo','tutor','apprentice','functional_skills_tutor'],true)||StaffDuties::has($u,'maths_tutor')||StaffDuties::has($u,'english_tutor')) {
            $links[]=$item('Maths and English','em.caseload',[],['em.*','topics.*','qualifications.*']);
        }
        if(in_array($u->role,['admin','ceo','tutor'],true)) {
            $primary[]=$item('Apprentice register','attendance.index',[],['attendance.*']);
        }
        if(MarkingReminders::allowed($u) || SubjectMarkingQueue::allowedSubjects($u)) {
            $primary[]=$item('Work to mark','marking.index',[],['marking.*'],
                $request->routeIs('marking.*') || ($request->routeIs('topics.show') && $request->boolean('from_marking')));
        }
        if(in_array($u->role,['admin','ceo','tutor'],true)){$links[]=$item('Gateway preparation','gateway.index',[],['gateway.*']);}
        if($u->role==='apprentice'){$links[]=$item('My calendar','calendar.index',[],['calendar.*']);}
        $group('training','Training tools',$links);
        $links=[];
        if(CentrePlanning::staff($u)){$links[]=$item('Timetable','centre-planning.index',[],['centre-planning.*','annual.*','occurrence-register.*','work-moves.*','optimiser.*','timetable-duties.*','timetable-governance.*']);}
        if(in_array($u->role,['admin','ceo','tutor'],true)) {$links[]=$item('Register changes','session-operations.index',[],['session-operations.*','session-changes.*','register-actions.*']);}
        if(\App\Support\ApprenticeRecordChecks::allowed($u)){$links[]=$item('Record checks','record-checks.index',[],['record-checks.*']);}
        if(CentreManagerAccess::workspace($u)){$links[]=$item('Centre oversight','centre-oversight.index',[],['centre-oversight.*']);}
        if(CentreFollowups::inbox($u)){$links[]=$item('Centre follow-ups','centre-followups.index',[],['centre-followups.*']);}
        $group('timetable-registers','Timetable and registers',$links);
        $links=[];
        if(ReviewReminders::allowed($u)) {$primary[]=$item('Reviews','reviews.index',[],['reviews.*','review-appointments.*','electronic-reviews.*','review-authority.*','review-workspace.*']);}
        if(in_array($u->role,['admin','ceo','apprentice'],true)||StaffDuties::has($u,'careers_adviser')) {$links[]=$item('Careers','careers.worklist',[],['careers.*']);}
        if(in_array($u->role,['apprentice','employer'],true)){$links[]=$item('Review appointments','review-appointments.index',[],['review-appointments.*']);$links[]=$item('Review signing','electronic-reviews.index',[],['electronic-reviews.*','review-authority.*']);}
        if(LeaverDestinations::allowed($u)) {$links[]=$item('Leaver follow-up','destinations.index',[],['destinations.*']);}
        $group('reviews-careers','Reviews & Careers',$links);
        $links=[];
        if(SupportAccess::team($u)) {
            $primary[]=$item('Support and Retention Register','support.worklist',[],[],
                $request->routeIs('support.*','support-register71.*','routine-support.*','support-review-reminders.*')
                && !$request->routeIs('support.permissions','support.report'));
        }
        if(QuestionnaireLinks::canView($u)) {$links[]=$item('Questionnaires','questionnaires.index',[],['questionnaires.index','portal-questionnaires.*']);}
        if($u->role==='apprentice'||ApprenticeExperience::queueAllowed($u)) {$links[]=$item($u->role==='apprentice'?'My help & achievements':'Help & achievement responses','experience.index',[],['experience.index','experience.create','experience.respond']);}
        if(in_array($u->role,['apprentice','employer'],true)||SupportAccess::team($u)) {$links[]=$item('Workplace support sharing','workplace-support.index',[],['workplace-support.*']);}
        if(Gate::forUser($u)->allows('view-employer-resources')) {$links[]=$item('Employer & Mentor Resources','employer-resources.index',[],['employer-resources.*']);}
        $group('support','Support tools',$links);
        $manage=Gate::forUser($u)->allows('manage-directories');$links=[];
        if(!in_array($u->role,['apprentice','employer'],true)) {$links[]=$item('Staff development','staff.index',[],['staff.*']);}
        if($u->role!=='apprentice') {$links[]=$item('Case studies','cases.index',[],['cases.*']);}
        if($manage) {$links[]=$item('Curriculum source checks','curriculum-sources.index',[],['curriculum-sources.*']);}
        if(SavedPortalWork::allowed($u)){$links[]=$item('Saved work','saved-work.index',[],['saved-work.*']);}
        if($u->role==='apprentice') {$primary[]=$item('Quick actions','quick-actions.index',[],['quick-actions.*']);}
        else {$links[]=$item('Quick actions','quick-actions.index',[],['quick-actions.*']);}
        $group('staff-quality','Other work',$links);
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
        if(LearningAccess::manager($u)){$links[]=$item('Account access','account-operations.index',[],['account-operations.*','restriction-recovery.*']);}
        if(LearningAccess::manager($u)){$links[]=$item('Centre Manager access','centre-access.index',[],['centre-access.*']);}
        if(LearningAccess::manager($u)){$links[]=$item('Retention administration','retention.admin',[],['retention.admin','retention.entry*','retention.timeline','retention.milestone.*']);}
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
        if(LearningAccess::manager($u)){$links[]=$item('Communication checks','communications.index');}
        $group('system','System tools',$links);
        $links=[];
        if(SecondFactor::required($u)||SecondFactor::mayEnrol($u)) {$links[]=$item('Two-step sign-in','second-factor.settings',[],['second-factor.settings','second-factor.change']);}
        if($u->role==='apprentice') {$links[]=$item('My reminders','apprentice-reminders.index',[],['apprentice-reminders.*']);}
        if(in_array($u->role,['apprentice','tutor'],true)) {
            $links[]=$item('Weekly training reminders','weekly-retention.reminders');
        }
        if($u->role==='apprentice') {$links[]=$item('Alert preferences','alerts.index',[],['alerts.*']);}
        $links[]=$item('Confirm email','account.email',[],['account.email*']);$links[]=$item('Change password','account.password',[],['account.password*']);
        if($u->role==='apprentice'){$links[]=$item('My reminder status','communications.own');}
        $group('account','My account',$links);
        // Creation stays on Home and the relevant list; keep occasional follow-up tools in their existing group.
        $primary = array_values(array_filter($primary, fn ($link) => !in_array($link['label'], ['Add apprentice', 'Add or link employer'], true)));
        $moveTo = ['Staff handovers'=>'staff-quality','My responsibilities'=>'staff-quality',
            'Quick actions'=>'support'];
        foreach ($primary as $key => $link) {
            if (!isset($moveTo[$link['label']])) { continue; }
            foreach ($groups as &$section) {
                if ($section['id'] !== $moveTo[$link['label']]) { continue; }
                array_unshift($section['links'], $link);
                $section['open'] = $section['open'] || $link['current'];
                unset($primary[$key]);
                break;
            }
            unset($section);
        }
        $primary = array_values($primary);
        $daily = ['My next step', 'Needs attention', 'Work today', 'Apprentices', 'Add apprentice', 'Add or link employer',
            'View progress', 'My progress', 'Apprentice register', 'Work to mark', 'Reviews', 'Support and Retention Register', 'Reports'];
        $priority = array_flip($daily);
        usort($primary, fn ($first, $second) =>
            ($priority[$first['label']] ?? count($daily)) <=> ($priority[$second['label']] ?? count($daily)));
        return ['primary'=>$primary,'groups'=>$groups,'help'=>$item('Help and how to','help.index',[],['help.*'])];
    }
    public static function forDisplay(User $user, Request $request): array
    {
        $menu = self::build($user, $request);
        $labels = match ($user->role) {
            'admin', 'ceo' => ['Needs attention', 'Work today', 'Apprentices', 'Apprentice register', 'Reports'],
            'tutor' => ['Needs attention', 'Work today', 'Apprentices', 'Apprentice register', 'Work to mark'],
            'apprentice' => ['My next step', 'My progress', 'My calendar', 'Review signing'],
            'employer' => ['My employer actions', 'View progress', 'Review appointments', 'Review signing'],
            'inclusion_lead', 'inclusion_member' => ['Support and Retention Register', 'Reports'],
            'mentor' => ['Needs attention', 'Reviews', 'View progress'],
            'functional_skills_tutor' => ['Maths and English', 'Work to mark'],
            'careers_adviser' => ['Careers', 'Leaver follow-up'],
            default => ['Centre oversight', 'Needs attention', 'Staff development', 'Maths and English', 'Careers'],
        };
        $sections = [['id'=>'other-pages', 'label'=>'Other pages', 'links'=>$menu['primary']], ...$menu['groups']];
        $links = collect($sections)->pluck('links')->collapse();
        $byLabel = $links->keyBy('label');
        $daily = collect($labels)->map(fn ($label) => $byLabel->get($label))->filter()->values();
        if ($daily->isEmpty()) {
            $daily = collect($menu['primary'])->take(3);
        }
        $dailyUrls = $daily->pluck('href')->all();
        $active = $links->firstWhere('current', true) ?? ($menu['help']['current'] ?? false ? $menu['help'] : null);
        foreach ($sections as &$section) {
            $section['links'] = array_values(array_filter($section['links'], fn ($link) => ! in_array($link['href'], $dailyUrls, true)));
        }
        unset($section);

        return [
            'daily'=>$daily,
            'sections'=>array_values(array_filter($sections, fn ($section) => $section['links'])),
            'currentUrl'=>$active['href'] ?? null,
            'currentTool'=>$active && ! in_array($active['href'], $dailyUrls, true) && $active['href'] !== ($menu['help']['href'] ?? null) ? $active['label'] : null,
            'help'=>$menu['help'],
        ];
    }

}
