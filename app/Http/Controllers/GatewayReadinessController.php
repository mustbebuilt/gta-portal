<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\GatewayReadinessPlan;
use App\Models\Qualification;
use App\Support\GatewayReadiness;
use App\Support\LearningAccess;
use App\Support\PortalPdf;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class GatewayReadinessController extends Controller
{
    public function index(Request $r)
    {
        abort_unless(LearningAccess::manager($r->user()) || ($r->user()->active && $r->user()->role === 'tutor'), 403);
        $data = $r->validate(['view' => ['nullable', Rule::in(['all', 'not-assessed', 'remaining', 'configured-met'])], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:1000000']]);
        $view = $data['view'] ?? 'all';
        $search = trim($data['q'] ?? '');
        $people = Apprentice::when(! LearningAccess::manager($r->user()), fn ($q) => $q->where('tutor_id', $r->user()->id))->orderBy('reference')->get();
        $rows = $people->map(function ($a) {
            $position = GatewayReadiness::position($a);

            return ['apprentice' => $a, 'position' => $position, 'bucket' => ! $position['current'] ? 'not-assessed' : ($position['all_met'] ? 'configured-met' : 'remaining')];
        });
        $counts = ['all' => $rows->count()];
        foreach (['not-assessed', 'remaining', 'configured-met'] as $bucket) {
            $counts[$bucket] = $rows->where('bucket', $bucket)->count();
        }
        $rows = $rows->filter(fn ($x) => ($view === 'all' || $x['bucket'] === $view) && ($search === '' || mb_stripos($x['apprentice']->first_name.' '.$x['apprentice']->last_name.' '.$x['apprentice']->reference, $search) !== false))->values();
        $page = min((int) ($data['page'] ?? 1), max(1, (int) ceil($rows->count() / 15)));
        $list = new LengthAwarePaginator($rows->forPage($page, 15)->values(), $rows->count(), 15, $page, ['path' => $r->url(), 'query' => $r->except('page')]);

        return response()->view('gateway.index', ['rows' => $list, 'counts' => $counts, 'view' => $view, 'search' => $search])->header('Cache-Control', 'no-store, private');
    }

    public function pdf(Request $r, Apprentice $apprentice)
    {
        abort_unless(GatewayReadiness::allowed($r->user(), $apprentice), 403);

        return PortalPdf::response('pdf.gateway', ['apprentice' => $apprentice, 'position' => GatewayReadiness::position($apprentice)], 'GTA-Gateway-Preparation-'.$apprentice->reference);
    }

    public function show(Request $r, Apprentice $apprentice)
    {
        abort_unless(GatewayReadiness::allowed($r->user(), $apprentice), 403);
        $staff = GatewayReadiness::staff($r->user(), $apprentice);

        $position=GatewayReadiness::position($apprentice);
        return response()->view('gateway.show', ['apprentice' => $apprentice, 'staff' => $staff, 'position' => $position,'gatewaySummary'=>\App\Support\GatewaySummary::for($r->user(),$apprentice,$position), 'plans' => $staff ? GatewayReadinessPlan::where('apprentice_id', $apprentice->id)->orderByDesc('id')->get() : collect(), 'qualifications' => $staff ? Qualification::where('apprentice_id', $apprentice->id)->orderBy('id')->get(['id', 'title']) : collect()])->header('Cache-Control', 'no-store, private');
    }

    public function prepare(Request $r, Apprentice $apprentice)
    {
        abort_unless(GatewayReadiness::staff($r->user(), $apprentice), 403);
        $data = $r->validate(['cohort' => ['required', 'string', 'max:180'], 'source_reference' => ['required', 'string', 'max:4000'], 'confirmed' => ['accepted'], 'criteria' => ['required', 'array', 'min:6', 'max:12'], 'criteria.*.label' => ['nullable', 'string', 'max:180'], 'criteria.*.category' => ['required', Rule::in(array_keys(GatewayReadiness::CATEGORIES))], 'criteria.*.kind' => ['required', Rule::in(['manual', 'taskset', 'qualification'])], 'criteria.*.qualification_id' => ['nullable', 'integer']]);
        GatewayReadiness::prepare($r->user(), (int) $r->session()->get('auth_version'), $apprentice, $data);

        return back()->with('status', 'Criteria draft saved. A different Admin/CEO must check the actual sources and cohort before approval.');
    }

    public function change(Request $r, GatewayReadinessPlan $plan)
    {
        $data = $r->validate(['version' => ['required', 'integer'], 'state' => ['required', Rule::in(['Approved', 'Withdrawn'])], 'note' => ['required', 'string', 'max:4000'], 'confirmed' => ['accepted']]);
        GatewayReadiness::change($r->user(), (int) $r->session()->get('auth_version'), $plan, $data);

        return back()->with('status', 'Criteria state saved. This does not approve the apprentice for Gateway.');
    }

    public function check(Request $r, GatewayReadinessPlan $plan)
    {
        $data = $r->validate(['plan_version' => ['required', 'integer'], 'version' => ['required', 'integer', 'min:0'], 'criterion_key' => ['required', 'string', 'max:16'], 'state' => ['required', Rule::in(['Met', 'Missing', 'Unknown'])], 'evidence_reference' => ['required', 'string', 'max:2000'], 'note' => ['required', 'string', 'max:4000'], 'checked_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'confirmed' => ['accepted']]);
        GatewayReadiness::check($r->user(), (int) $r->session()->get('auth_version'), $plan, $data);

        return back()->with('status','Shareable readiness check recorded. Final provider/EPAO Gateway decisions remain separate.');
    }
}
