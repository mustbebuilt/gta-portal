<?php

namespace App\Http\Controllers;

use App\Models\ReviewReminder;
use App\Support\LearningAccess;
use App\Support\ReviewReminders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewReminderController extends Controller
{
    public function record(Request $request): RedirectResponse
    {
        abort_unless(LearningAccess::manager($request->user()),403);
        $counts=ReviewReminders::generate(true,$request->user());
        return to_route('reviews.index')->with('status',$counts['created'].' new in-portal review reminders recorded. '.$counts['already_recorded'].' already existed. No email was sent.');
    }
    public function seen(Request $request, ReviewReminder $reminder): RedirectResponse
    {
        ReviewReminders::seen($reminder,$request->user());
        return to_route('reviews.index')->with('status','Review reminder marked seen. The tracked due date remains until a newer signed meeting is independently checked.');
    }
}
