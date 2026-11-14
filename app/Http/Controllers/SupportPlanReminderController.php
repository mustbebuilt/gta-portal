<?php

namespace App\Http\Controllers;

use App\Models\{Apprentice, SupportPlan, SupportPlanReminder};
use App\Support\{SupportAccess, SupportPlanReminders};
use Illuminate\Http\Request;

class SupportPlanReminderController extends Controller
{
    public function assign(Request $request, SupportPlan $plan)
    {
        abort_unless(SupportAccess::lead($request->user(), $plan->apprentice), 403);
        $data = $request->validate(['version' => ['required', 'integer'], 'coordinator' => ['nullable', 'integer', 'min:1']]);
        SupportPlanReminders::assign($plan, $request->user(), $request->session()->get('auth_version'),
            $data['version'], isset($data['coordinator']) ? (int) $data['coordinator'] : null);
        return back()->with('status', 'Review coordinator saved. Approved support, review dates and funding decisions are unchanged.');
    }

    public function index(Request $request)
    {
        abort_unless(SupportAccess::team($request->user()), 403);
        $plans = SupportPlan::whereIn('apprentice_id', SupportAccess::ids($request->user()))->select('id');
        $rows = SupportPlanReminder::where('recipient_id', $request->user()->id)->whereIn('support_plan_id', $plans)->orderByDesc('id')->paginate(20);
        $visible = [];
        foreach ($rows as $row) {
            $plan = SupportPlan::find($row->support_plan_id);
            $a = $plan ? Apprentice::find($plan->apprentice_id) : null;
            if (! $a || ! SupportAccess::team($request->user(), $a)) { continue; }
            $visible[] = ['reminder' => $row, 'plan' => $plan, 'apprentice' => $a,
                'current' => SupportPlanReminders::current($row, $request->user())];
        }
        return response()->view('support.review-reminders', ['items' => $visible, 'pages' => $rows])
            ->header('Cache-Control', 'no-store, private');
    }

    public function seen(Request $request, SupportPlanReminder $reminder)
    {
        SupportPlanReminders::seen($reminder, $request->user(), $request->session()->get('auth_version'));
        return back()->with('status', 'Reminder marked seen. Record any actual support review in the original plan.');
    }

    public function dismiss(Request $request, SupportPlanReminder $reminder)
    {
        SupportPlanReminders::acknowledge($reminder, $request->user(), $request->session()->get('auth_version'), true);
        return back()->with('status', 'Outdated reminder dismissed. Plan history and actual reviews are unchanged.');
    }
}
