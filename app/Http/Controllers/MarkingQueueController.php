<?php

namespace App\Http\Controllers;

use App\Models\MarkingReminder;
use App\Support\LearningAccess;
use App\Support\MarkingReminders;
use App\Support\MarkingQueueViews;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarkingQueueController extends Controller
{
    public function index(Request $request): View
    {
        $base = MarkingReminders::query($request->user());
        $input=$request->validate(['view'=>['nullable','string','max:30'],'search'=>['nullable','string','max:250'],'tutor'=>['nullable','string','max:30']]);
        $manager=LearningAccess::manager($request->user());
        $tutorFilter=$input['tutor']??'';$tutorOptions=collect();
        if($manager){
            $ids=(clone $base)->join('apprentices','apprentices.id','=','learning_tasks.apprentice_id')->whereNotNull('apprentices.tutor_id')->distinct()->pluck('apprentices.tutor_id');
            $tutorOptions=User::where(fn($q)=>$q->where('role','tutor')->orWhereIn('id',$ids))->orderBy('name')->orderBy('id')->get(['id','name','active','role']);
            if($tutorFilter==='unassigned'){$base->whereHas('apprentice',fn($q)=>$q->whereNull('tutor_id'));}
            elseif($tutorFilter!==''){
                abort_unless(ctype_digit($tutorFilter) && $tutorOptions->contains(fn($u)=>(string)$u->id===$tutorFilter),422,'Choose a main tutor listed in this worklist.');
                $base->whereHas('apprentice',fn($q)=>$q->where('tutor_id',(int)$tutorFilter));
            }
        }elseif($tutorFilter!=='' && $tutorFilter!==(string)$request->user()->id){abort(403,'Your worklist follows your current apprentice assignments.');}
        $search=trim($input['search']??'');
        if($search!==''){$base->whereHas('apprentice',fn($a)=>$a->where(fn($n)=>$n->where('reference','like','%'.$search.'%')->orWhere('first_name','like','%'.$search.'%')->orWhere('last_name','like','%'.$search.'%')));}
        $counts=MarkingQueueViews::counts($base);
        $filter=$input['view']??'overdue';if(!in_array($filter,MarkingQueueViews::VIEWS,true)){$filter='overdue';}
        $q=MarkingQueueViews::apply(clone $base,$filter);
        $q->with('apprentice.tutor')->orderBy('submitted_at')->orderBy('id');
        $tasks=(clone $q)->paginate(25)->withQueryString();
        if($tasks->currentPage()>$tasks->lastPage()){$tasks=(clone $q)->paginate(25,['*'],'page',$tasks->lastPage())->withQueryString();}
        $sources = [];
        foreach ($tasks as $task) {
            $sources[$task->id] = MarkingReminders::source($task);
        }
        $reminders = MarkingReminder::whereIn('source_key', array_filter(array_column($sources, 'source_key')))->get()->keyBy('source_key');

        return view('marking.index', ['reminderScheduleEnabled'=>(bool)config('portal_reminders.scheduled',false),'lastReminderRun'=>LearningAccess::manager($request->user()) ? \Illuminate\Support\Facades\DB::table('portal_reminder_runs')->latest('id')->first() : null,'tasks' => $tasks, 'counts' => $counts, 'sources' => $sources, 'reminders' => $reminders, 'manager' => LearningAccess::manager($request->user()), 'filter' => $filter, 'search' => $search,'tutorFilter'=>$tutorFilter,'tutorOptions'=>$tutorOptions]);
    }

    public function record(Request $request): RedirectResponse
    {
        abort_unless(LearningAccess::manager($request->user()), 403);
        $result = MarkingReminders::generate(true, $request->user());

        return back()->with('status', $result['created'].' new in-portal reminders recorded. '.$result['already_recorded'].' already existed. No email was sent.');
    }

    public function seen(Request $request, MarkingReminder $reminder): RedirectResponse
    {
        MarkingReminders::seen($reminder, $request->user());

        return back()->with('status', 'Reminder marked seen. The task still needs a tutor decision.');
    }
}
