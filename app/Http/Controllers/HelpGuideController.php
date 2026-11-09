<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

class HelpGuideController extends Controller
{
    /**
     * Display role-scoped Help & How-To Guides.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();
        $userRole = $user ? $user->role->value : 'apprentice';

        $guidesPath = resource_path('data/help-guides.json');
        $allGuides = [];
        $categories = [];

        if (File::exists($guidesPath)) {
            $data = json_decode(File::get($guidesPath), true);
            $rawGuides = $data['guides'] ?? [];

            foreach ($rawGuides as $guide) {
                $roles = $guide['roles'] ?? ['all'];
                // Check if guide applies to current user role or 'all'
                if (in_array('all', $roles) || in_array($userRole, $roles) || ($user && $user->isStaff() && in_array('staff', $roles))) {
                    $allGuides[] = $guide;
                    if (!empty($guide['category'])) {
                        $categories[$guide['category']] = true;
                    }
                }
            }
        }

        $categories = array_keys($categories);
        sort($categories);

        return view('help.index', compact('allGuides', 'categories', 'userRole'));
    }
}

