<?php

namespace App\Http\Controllers;

use App\Models\{Apprentice, SupportMarkerSchedule71, SupportSharedAction71, User};
use App\Support\{SupportAccess, SupportRegister71};
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Str;

class SupportRegister71Controller extends Controller
{
    public function index(Request $r)
    {
        abort_unless(SupportRegister71::allowed($r->user()), 403);
        $data = SupportRegister71::query($r->user(), $r->only(['view', 'search', 'code']));

        $canManage = SupportAccess::team($r->user());
        $allApprentices = $canManage ? Apprentice::orderBy('last_name')->orderBy('first_name')->get(['id', 'reference', 'first_name', 'last_name']) : collect();
        $staffOwners = $canManage ? User::where('active', true)->whereNotIn('role', ['apprentice', 'employer'])->orderBy('name')->get(['id', 'name', 'role']) : collect();

        return response()->view('support71.register', [
            ...$data,
            'canManage' => $canManage,
            'allApprentices' => $allApprentices,
            'staffOwners' => $staffOwners,
            'intervals' => SupportRegister71::INTERVALS,
            'actionUuid' => (string) Str::uuid(),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function reports(Request $r)
    {
        abort_unless(SupportRegister71::allowed($r->user()), 403);
        $data = SupportRegister71::query($r->user(), ['view' => 'all', ...$r->only(['search', 'code'])]);

        return response()->view('support71.reports', $data)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function addMarker(Request $r): RedirectResponse
    {
        $v = $r->validate([
            'apprentice_id' => ['required', 'integer', 'exists:apprentices,id'],
            'code' => ['required', 'string', 'max:12'],
            'interval_key' => ['required', 'string', 'max:20'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $apprentice = Apprentice::findOrFail($v['apprentice_id']);
        SupportRegister71::addMarker($r->user(), $apprentice, $v);

        return to_route('support-register71.index', ['view' => 'needs_review'])
            ->with('status', 'Marker added to Support and Retention Register with its initial review schedule. No support plan or funding claim was created automatically.');
    }

    public function review(Request $r, SupportMarkerSchedule71 $schedule): RedirectResponse
    {
        $v = $r->validate([
            'version' => ['required', 'integer'],
            'kind' => ['required', 'string'],
            'review_date' => ['nullable', 'date_format:Y-m-d'],
            'interval_key' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        SupportRegister71::recordReview($r->user(), $schedule, $v);

        return back()->with('status', match ($v['kind']) {
            'formal_review' => 'Formal review recorded (Register). Next review due date updated.',
            'discussion_only' => 'Discussion logged. Formal review due date was not moved.',
            'change_frequency' => 'Review frequency updated with recorded reason.',
            'close_marker' => 'Marker episode closed and history retained.',
        });
    }

    public function addAction(Request $r): RedirectResponse
    {
        $v = $r->validate([
            'apprentice_id' => ['required', 'integer', 'exists:apprentices,id'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'summary' => ['required', 'string', 'max:2000'],
            'request_uuid' => ['required', 'uuid'],
            'support_concern_id' => ['nullable', 'integer'],
            'support_plan_id' => ['nullable', 'integer'],
        ]);
        $apprentice = Apprentice::findOrFail($v['apprentice_id']);
        SupportRegister71::createAction($r->user(), $apprentice, $v);

        return back()->with('status', 'Shared follow-up action saved.');
    }

    public function completeAction(Request $r, SupportSharedAction71 $action): RedirectResponse
    {
        $v = $r->validate(['outcome' => ['nullable', 'string', 'max:1000']]);
        SupportRegister71::completeAction($r->user(), $action, $v);

        return back()->with('status', 'Action marked complete. Marker review dates remain separate.');
    }
}
