<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\SupportMarkerSchedule71;
use App\Models\SupportSharedAction71;
use App\Models\User;
use App\Support\ReportedChoices;
use App\Support\SupportAccess;
use App\Support\SupportRegister71;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupportRegister71Controller extends Controller
{
    public function index(Request $r)
    {
        abort_unless(SupportRegister71::allowed($r->user()), 403);
        if (SupportAccess::team($r->user())) {
            $r->merge([
                'view' => $r->input('view') === 'all' ? 'all' : 'needs-review',
                'marker' => $r->query->get('code'),
            ]);

            return app(SupportController::class)->worklist($r);
        }
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
        if (SupportAccess::team($r->user())) {
            return app(SupportController::class)->registerReport($r);
        }
        $data = SupportRegister71::query($r->user(), ['view' => 'all', ...$r->only(['search', 'code'])]);

        return response()->view('support71.reports', $data)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function addMarker(Request $r): RedirectResponse
    {
        $this->authoriseInput85($r);
        $v = $r->validate([
            'apprentice_id' => ['required', 'integer', 'exists:apprentices,id'],
            'code' => ['required', 'string', 'max:12'],
            'interval_key' => ['required', 'string', 'max:20'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $apprentice = Apprentice::findOrFail($v['apprentice_id']);
        SupportRegister71::addMarker($r->user(), $apprentice, $v);

        return to_route('support.worklist', ['view' => 'needs-review'])->with('status', 'Marker added for review.');
    }

    public function review(Request $r, SupportMarkerSchedule71 $schedule): RedirectResponse
    {
        $v = $r->validate([
            'version' => ['required', 'integer'],
            'kind' => ['required', 'string'],
            'review_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'interval_key' => ['nullable', 'string', 'max:20'],
            'review_outcome_code' => ['exclude_unless:kind,formal_review', 'nullable', Rule::in(array_keys(ReportedChoices::SUPPORT_REVIEW))],
            'note' => ['nullable', Rule::requiredIf($r->input('kind') === 'formal_review' && $r->input('review_outcome_code') === 'other'), 'string', 'max:2000'],
            ...SupportRegister71::reviewTargetRules(),
        ]);
        SupportRegister71::recordReview($r->user(), $schedule, $v);

        return SupportRegister71::reviewReturn($r, $v)->with('status', match ($v['kind']) {
            'formal_review' => 'Review saved. Next review date updated.',
            'discussion_only' => 'Discussion logged. Formal review due date was not moved.',
            'change_frequency' => 'Review frequency updated with recorded reason.',
            'close_marker' => 'Marker episode closed and history retained.',
        });
    }

    public function addAction(Request $r): RedirectResponse
    {
        $this->authoriseInput85($r);
        $v = $r->validate([
            'apprentice_id' => ['required', 'integer', 'exists:apprentices,id'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'summary' => ['required', 'string', 'max:2000'],
            'target_type' => ['nullable', 'in:smart'],
            'target_template' => ['nullable', 'string', \Illuminate\Validation\Rule::in([
                'custom', ...collect(config('staff_templates.support_targets'))->flatMap(fn ($group) => array_keys($group))
                    ->map(fn ($label) => \Illuminate\Support\Str::slug($label))->all(),
            ])],
            'measure' => ['nullable', 'required_if:target_type,smart', 'string', 'max:1000'],
            'request_uuid' => ['required', 'uuid'],
            'support_concern_id' => ['nullable', 'integer'],
            'support_plan_id' => ['nullable', 'integer'],
            'lead_team' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(SupportRegister71::LEAD_TEAMS))],
        ]);
        $apprentice = Apprentice::findOrFail($v['apprentice_id']);
        SupportRegister71::createAction($r->user(), $apprentice, $v);

        if ($r->input('_support_form') === route('support-register71.add-action')) {
            return redirect()->to(route('support.index', $apprentice).'#support-actions')->with('status', 'Target saved.');
        }

        return back()->with('status', 'Action saved.');
    }

    public function completeAction(Request $r, SupportSharedAction71 $action): RedirectResponse
    {
        $v = $r->validate(['outcome' => ['nullable', 'string', 'max:1000']]);
        SupportRegister71::completeAction($r->user(), $action, $v);

        return back()->with('status', 'Action completed.');
    }

    private function authoriseInput85(Request $request): void
    {
        abort_unless(SupportAccess::team($request->user()), 403);
        $id = $request->input('apprentice_id');
        abort_unless(is_scalar($id) && ctype_digit((string) $id), 403);
        $apprentice = Apprentice::whereKey((int) $id)->first();
        abort_unless($apprentice && SupportAccess::team($request->user(), $apprentice), 403);
    }
}
