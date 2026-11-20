<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Support\{LearningAccess, ProgressReview74};
use Illuminate\Http\Request;

class ProgressReview74Controller extends Controller
{
    public function show(Request $r, Apprentice $apprentice)
    {
        abort_unless(LearningAccess::training($r->user(), $apprentice), 403);
        $v = $r->validate(['meeting_date' => ['nullable', 'date_format:Y-m-d']]);
        $data = ProgressReview74::build($apprentice->load(['programmeRecord', 'employerRecord', 'tutor', 'mentor']), $v['meeting_date'] ?? null);

        return response()->view('reviews74.summary', $data)
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
