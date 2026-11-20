<?php

namespace App\Http\Controllers;

use App\Models\{Apprentice, TutorGroup};
use App\Support\{LearningAccess, TrainingJourney72};
use Illuminate\Http\Request;

class TrainingJourney72Controller extends Controller
{
    public function show(Request $r, Apprentice $apprentice)
    {
        abort_unless(LearningAccess::training($r->user(), $apprentice), 403);
        $data = TrainingJourney72::forApprentice($apprentice);

        return response()->view('training72.journey', [
            'apprentice' => $apprentice->load(['programmeRecord', 'groupRecord']),
            ...$data,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function gaps(Request $r)
    {
        $u = $r->user();
        abort_unless(in_array($u->role, ['admin', 'ceo', 'tutor'], true), 403);
        $v = $r->validate([
            'group' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $groupId = isset($v['group']) ? (int) $v['group'] : null;
        $search = trim((string) ($v['search'] ?? ''));

        $report = TrainingJourney72::gapsReport($u, $groupId, $search);
        $groups = TutorGroup::where('active', true)->orderBy('name')->get();

        return response()->view('training72.gaps', [
            ...$report,
            'groups' => $groups,
            'groupId' => $groupId,
            'search' => $search,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
