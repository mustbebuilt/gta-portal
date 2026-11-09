<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\ApprenticeFunctionalSkill;
use App\Models\ApprenticeMandatoryQual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class EnglishMathsController extends Controller
{
    /**
     * Display English & Maths assessment, diagnostic and topics matching english-maths-summary.html.
     */
    public function index(Request $request, ?Apprentice $apprentice = null): View
    {
        $user = Auth::user();

        if (!$apprentice || !$apprentice->exists) {
            if ($user->role === UserRole::Apprentice) {
                $apprentice = Apprentice::where('user_id', $user->id)->firstOrFail();
            } else {
                $apprenticeId = $request->input('apprentice', 1);
                $apprentice = Apprentice::find($apprenticeId) ?? Apprentice::where('status', 'Live')->first();
            }
        }

        $functionalSkills = $apprentice
            ? ApprenticeFunctionalSkill::where('apprentice_id', $apprentice->apprentice_id)->get()
            : collect();

        $mathsTopics = $functionalSkills->where('subject', 'like', '%Maths%');
        $englishTopics = $functionalSkills->where('subject', 'like', '%English%');

        $mandatoryQuals = $apprentice
            ? ApprenticeMandatoryQual::where('apprentice_id', $apprentice->apprentice_id)->get()
            : collect();

        $allApprentices = $user->isStaff()
            ? Apprentice::with('user')->where('status', 'Live')->get()
            : collect();

        return view('english-maths.index', compact(
            'apprentice',
            'mathsTopics',
            'englishTopics',
            'mandatoryQuals',
            'allApprentices'
        ));
    }
}

