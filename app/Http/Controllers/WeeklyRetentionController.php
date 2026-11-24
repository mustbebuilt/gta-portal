<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\WeeklyRetentionCheck;
use App\Models\WeeklyRetentionReminder;
use App\Support\SupportAccess;
use App\Support\WeeklyRetention;
use Illuminate\Http\Request;

class WeeklyRetentionController extends Controller
{
    public function index(Request $request)
    {
        abort_unless(SupportAccess::team($request->user()), 403);
        $filters = $request->validate([
            'week' => ['nullable', 'date_format:Y-m-d'],
            'view' => ['nullable', 'in:needs-review,all'],
        ]);
        $query = WeeklyRetentionCheck::whereIn('apprentice_id', SupportAccess::ids($request->user()));
        $weeks = (clone $query)->distinct()->orderByDesc('week_of')->limit(26)->pluck('week_of');
        $week = $filters['week'] ?? WeeklyRetention::monday()->toDateString();
        $view = $filters['view'] ?? 'needs-review';
        $checks = $query->where('week_of', $week)->when($view === 'needs-review', fn ($q) => $q->whereNull('reviewed_at'))
            ->with(['apprentice', 'reminders'])->orderBy('id')->paginate(30)->withQueryString();

        return response()->view('support.weekly-retention', compact('checks', 'weeks', 'week', 'view'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function reminders(Request $request)
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['tutor', 'apprentice'], true), 403);
        $ids = Apprentice::visibleTo($user)->select('id');
        $reminders = WeeklyRetentionReminder::where('user_id', $user->id)
            ->whereHas('check', fn ($q) => $q->whereIn('apprentice_id', $ids))
            ->with('check.apprentice')->latest('id')->paginate(25);

        return response()->view('support.weekly-retention-reminders', compact('reminders'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function reviewed(Request $request, WeeklyRetentionCheck $check)
    {
        abort_unless(SupportAccess::team($request->user(), $check->apprentice), 403);
        WeeklyRetentionCheck::whereKey($check->id)->whereNull('reviewed_at')
            ->update(['reviewed_at' => now(), 'reviewed_by' => $request->user()->id]);

        return back()->with('status', 'Weekly check marked reviewed.');
    }
}
