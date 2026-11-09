<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class GroupManagementController extends Controller
{
    /**
     * Display the Tutor Groups management dashboard.
     */
    public function index(): View
    {
        $groups = TutorGroup::with(['tutor', 'apprentices.user', 'apprentices.employer'])->get();
        $trainers = User::where('role', UserRole::Trainer)->get();
        $unassignedApprentices = Apprentice::with('user')
            ->where('status', 'Live')
            ->whereDoesntHave('tutorGroups')
            ->get();

        return view('admin.groups.index', compact('groups', 'trainers', 'unassignedApprentices'));
    }

    /**
     * Store a new tutor group and assign its lead tutor.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'group_name' => 'required|string|max:100',
            'group_code' => 'required|string|max:50|unique:tutor_groups,group_code',
            'tutor_id' => 'required|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        TutorGroup::create([
            'group_name' => $validated['group_name'],
            'group_code' => $validated['group_code'],
            'tutor_id' => $validated['tutor_id'],
        ]);

        return redirect()->route('admin.groups.index')->with('success', "Tutor group '{$validated['group_name']}' created successfully.");
    }

    /**
     * Assign or move an apprentice to a tutor group and sync their trainer_id.
     */
    public function assign(Request $request, TutorGroup $group): RedirectResponse
    {
        $validated = $request->validate([
            'apprentice_id' => 'required|exists:apprentice,apprentice_id',
        ]);

        $apprentice = Apprentice::findOrFail($validated['apprentice_id']);

        DB::transaction(function () use ($group, $apprentice) {
            // Remove from any prior tutor groups
            DB::table('apprentice_tutor_groups')
                ->where('apprentice_id', $apprentice->apprentice_id)
                ->delete();

            // Link to new tutor group
            DB::table('apprentice_tutor_groups')->insert([
                'apprentice_id' => $apprentice->apprentice_id,
                'tutor_group_id' => $group->tutor_group_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Sync the assigned GTA tutor to match the group tutor
            if ($group->tutor_id) {
                $apprentice->trainer_id = $group->tutor_id;
                $apprentice->save();
            }
        });

        return redirect()->route('admin.groups.index')->with('success', "Apprentice assigned to {$group->group_name}.");
    }

    /**
     * Remove an apprentice from a tutor group.
     */
    public function remove(TutorGroup $group, Apprentice $apprentice): RedirectResponse
    {
        DB::table('apprentice_tutor_groups')
            ->where('apprentice_id', $apprentice->apprentice_id)
            ->where('tutor_group_id', $group->tutor_group_id)
            ->delete();

        return redirect()->route('admin.groups.index')->with('success', "Apprentice removed from {$group->group_name}.");
    }
}

