<?php

namespace App\Http\Controllers;

use App\Models\MarkingReminder;
use App\Support\LearningAccess;
use App\Support\MarkingReminders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarkingQueueController extends Controller
{
    public function index(Request $request): View
    {
        $base = MarkingReminders::query($request->user());
        $input = $request->validate(['view' => ['nullable', 'string', 'max:30'], 'search' => ['nullable', 'string', 'max:250']]);
        $counts = ['awaiting' => (clone $base)->count(), 'overdue' => (clone $base)->where('submitted_at', '<', now()->subDays(7))->count(), 'date_checks' => (clone $base)->where(fn ($q) => $q->whereNull('submitted_at')->orWhere('submitted_at', '>', now()))->count()];
        $filter = ($input['view'] ?? 'overdue');
        $q = clone $base;
        if ($filter === 'overdue') {
            $q->where('submitted_at', '<', now()->subDays(7));
        } elseif ($filter === 'date-check') {
            $q->where(fn ($d) => $d->whereNull('submitted_at')->orWhere('submitted_at', '>', now()));
        } elseif ($filter === 'needs-owner') {
            $q->whereDoesntHave('apprentice.tutor', fn ($t) => $t->where('active', true)->where('role', 'tutor'));
        } elseif ($filter !== 'all') {
            $filter = 'overdue';
            $q->where('submitted_at', '<', now()->subDays(7));
        }
        $search = trim($input['search'] ?? '');
        if ($search !== '') {
            $q->whereHas('apprentice', fn ($a) => $a->where(fn ($n) => $n->where('reference', 'like', '%'.$search.'%')->orWhere('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')));
        }
        $tasks = $q->with('apprentice.tutor')->orderBy('submitted_at')->orderBy('id')->paginate(25)->withQueryString();
        $sources = [];
        foreach ($tasks as $task) {
            $sources[$task->id] = MarkingReminders::source($task);
        }
        $reminders = MarkingReminder::whereIn('source_key', array_filter(array_column($sources, 'source_key')))->get()->keyBy('source_key');

        return view('marking.index', ['reminderScheduleEnabled'=>(bool)config('portal_reminders.scheduled',false),'lastReminderRun'=>LearningAccess::manager($request->user()) ? \Illuminate\Support\Facades\DB::table('portal_reminder_runs')->latest('id')->first() : null,'tasks' => $tasks, 'counts' => $counts, 'sources' => $sources, 'reminders' => $reminders, 'manager' => LearningAccess::manager($request->user()), 'filter' => $filter, 'search' => $search]);
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
