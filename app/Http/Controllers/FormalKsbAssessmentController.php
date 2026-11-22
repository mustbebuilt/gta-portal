<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\FormalKsbAssessment;
use App\Support\DeliveryPlan;
use App\Support\FormalKsbResults;
use App\Support\LearningAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FormalKsbAssessmentController extends Controller
{
    public function index(Request $request, Apprentice $apprentice)
    {
        $this->authorise($request, $apprentice);
        $plan = DeliveryPlan::approved($apprentice);
        $key = FormalKsbResults::key($plan);
        $records = FormalKsbAssessment::where('apprentice_id',$apprentice->id)->latest('assessed_on')->latest('id')->paginate(20);

        return response()->view('reviews.ksb-assessments', compact('apprentice','plan','key','records'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Apprentice $apprentice)
    {
        $this->authorise($request, $apprentice);
        $data = $request->validate([
            'plan_hash'=>['required','string','size:64'],
            'code'=>['required','string','regex:/^[KSB][1-9][0-9]{0,5}(?:\\.[1-9][0-9]{0,3})?$/i'],
            'outcome'=>['required',Rule::in(array_keys(FormalKsbResults::OUTCOMES))],
            'assessed_on'=>['required','date_format:Y-m-d','before_or_equal:today'],
            'evidence_reference'=>['required','string','max:500'],
            'mapping_checked'=>['accepted'],
        ]);
        DB::transaction(function () use ($request, $apprentice, $data): void {
            $current = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            $this->authorise($request, $current);
            $plan = DeliveryPlan::approved($current);
            if (!$plan || !hash_equals($plan['hash'], $data['plan_hash']) || !$key = FormalKsbResults::key($plan)) {
                throw ValidationException::withMessages(['plan_hash'=>'Reopen the current approved delivery plan before recording this result.']);
            }
            if ($data['assessed_on'] < $plan['identity']['start']) {
                throw ValidationException::withMessages(['assessed_on'=>'The assessment cannot predate the apprentice’s current programme.']);
            }

            FormalKsbAssessment::create([
                'apprentice_id'=>$current->id, 'recorded_by'=>$request->user()->id, 'curriculum_key'=>$key,
                'code'=>strtoupper($data['code']), 'outcome'=>$data['outcome'], 'assessed_on'=>$data['assessed_on'],
                'evidence_reference'=>$data['evidence_reference'],
            ]);
        });

        return back()->with('status','Formal KSB result saved. Save the progress review to capture the result.');
    }

    private function authorise(Request $request, Apprentice $apprentice): void
    {
        abort_unless(LearningAccess::manager($request->user()) || LearningAccess::tutor($request->user(), $apprentice), 403);
    }
}
