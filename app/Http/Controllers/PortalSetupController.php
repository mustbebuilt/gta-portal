<?php

namespace App\Http\Controllers;

use App\Models\{Apprentice, Employer, Programme, TutorGroup, User};
use App\Support\DirectoryCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PortalSetupController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('manage-directories');
        return response()->view('setup.index', ['owner' => $request->user(), 'counts' => [
            'staff' => User::whereIn('role', DirectoryCatalog::STAFF_ROLES)->where('active', true)->count(),
            'employers' => Employer::where('active', true)->count(), 'programmes' => Programme::where('active', true)->count(),
            'groups' => TutorGroup::where('active', true)->count(), 'apprentices' => Apprentice::count(),
        ]])->header('Cache-Control', 'no-store, private');
    }
}
