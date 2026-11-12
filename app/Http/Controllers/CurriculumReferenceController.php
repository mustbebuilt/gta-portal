<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\LearningTask;
use App\Support\LearningAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CurriculumReferenceController extends Controller
{
    public function show(Request $request, Apprentice $apprentice): View
    {
        abort_unless(LearningAccess::training($request->user(), $apprentice), 403);
        $load = fn (string $name): array => json_decode(file_get_contents(resource_path('data/'.$name.'.json')), true, 512, JSON_THROW_ON_ERROR);

        return view('learning.curriculum', [
            'apprentice' => $apprentice,
            'reference' => $load('training-plan-reference'),
            'baseline' => $load('programme-baseline'),
            'alignment' => $load('source-alignment'),
            'tasks' => LearningTask::where('apprentice_id', $apprentice->id)->orderBy('block')->orderBy('reference')->get(),
        ]);
    }
}
