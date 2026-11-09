<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\ApprenticeTask;
use App\Models\Block;
use App\Models\Employer;
use App\Models\Standard;
use App\Models\TutorGroup;
use App\Models\User;
use App\Services\AttendanceRagService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class ApprenticeManagementController extends Controller
{
    public function __construct(
        protected AttendanceRagService $ragService
    ) {}

    /**
     * Display the apprentice roster with search, filtering, and RAG status.
     */
    public function index(Request $request): View
    {
        $query = Apprentice::with(['user', 'employer', 'trainer', 'standard']);

        // Search query
        if ($search = $request->input('search')) {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Employer filter
        if ($employerId = $request->input('employer_id')) {
            $query->where('employer_id', $employerId);
        }

        $apprentices = $query->orderBy('created_at', 'desc')->paginate(15);

        // Compute RAG metrics for each apprentice
        $apprenticesWithRag = $apprentices->through(function (Apprentice $apprentice) {
            $rag = $this->ragService->computeRag($apprentice);
            return [
                'model' => $apprentice,
                'rag' => $rag,
            ];
        });

        $totalCount = Apprentice::count();
        $liveCount = Apprentice::where('status', 'Live')->count();
        $employers = Employer::orderBy('employer_name')->get();
        $standards = Standard::all();

        return view('admin.apprentices.index', compact(
            'apprentices',
            'apprenticesWithRag',
            'totalCount',
            'liveCount',
            'employers',
            'standards'
        ));
    }

    /**
     * Show the form to create a new apprentice (Apprentice Setup).
     */
    public function create(): View
    {
        $standards = Standard::all();
        $employers = Employer::orderBy('employer_name')->get();
        $tutorGroups = TutorGroup::with('tutor')->get();
        $trainers = User::where('role', UserRole::Trainer)->get();
        $mentors = User::where('role', UserRole::Mentor)->get();
        $admins = User::where('role', UserRole::Admin)->get();

        return view('admin.apprentices.create', compact(
            'standards',
            'employers',
            'tutorGroups',
            'trainers',
            'mentors',
            'admins'
        ));
    }

    /**
     * Store a newly created apprentice and generate their 10-block curriculum tasks.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|unique:users,email',
            'username' => 'required|string|unique:users,username',
            'user_dob' => 'nullable|date',
            'tutor_group_id' => 'nullable|exists:tutor_groups,tutor_group_id',
            'trainer_id' => 'required|exists:users,id',
            'employer_id' => 'nullable|exists:employers,employer_id',
            'new_employer_name' => 'nullable|string|max:255',
            'workplace_mentor_name' => 'nullable|string|max:255',
            'workplace_mentor_email' => 'nullable|email',
            'standard_id' => 'required|exists:standards,standard_id',
            'start_date' => 'required|date',
            'planned_end_date' => 'required|date|after:start_date',
            'otj_target_hours' => 'nullable|numeric|min:1',
        ]);

        DB::transaction(function () use ($validated, $request) {
            // 1. Create or resolve Employer
            $employerId = $validated['employer_id'] ?? null;
            if (!$employerId && !empty($validated['new_employer_name'])) {
                $employer = Employer::create([
                    'employer_name' => $validated['new_employer_name'],
                    'contact_name' => $validated['workplace_mentor_name'] ?? 'Workshop Manager',
                    'contact_email' => $validated['workplace_mentor_email'] ?? null,
                ]);
                $employerId = $employer->employer_id;
            }

            // 2. Create User account for Apprentice
            $user = User::create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'email' => $validated['email'],
                'username' => $validated['username'],
                'user_dob' => $validated['user_dob'] ?? null,
                'role' => UserRole::Apprentice,
                'password' => Hash::make($request->input('password', 'password')),
            ]);

            // 3. Create Apprentice record
            $apprentice = Apprentice::create([
                'user_id' => $user->id,
                'employer_id' => $employerId,
                'trainer_id' => $validated['trainer_id'],
                'standard_id' => $validated['standard_id'],
                'start_date' => $validated['start_date'],
                'planned_end_date' => $validated['planned_end_date'],
                'otj_target_hours' => $validated['otj_target_hours'] ?? 663.00,
                'otj_hours_completed' => 0.00,
                'status' => 'Live',
            ]);

            // 4. Link to Tutor Group if selected
            if (!empty($validated['tutor_group_id'])) {
                DB::table('apprentice_tutor_groups')->insert([
                    'apprentice_id' => $apprentice->apprentice_id,
                    'tutor_group_id' => $validated['tutor_group_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // 5. Generate 50 Tasks (5 tasks across 10 blocks)
            $blocks = Block::where('standard_id', $validated['standard_id'])->get();
            if ($blocks->isEmpty()) {
                // Default 10 blocks if not in standard
                for ($b = 1; $b <= 10; $b++) {
                    for ($t = 1; $t <= 5; $t++) {
                        ApprenticeTask::create([
                            'apprentice_id' => $apprentice->apprentice_id,
                            'ablk_id' => $b,
                            'task_num' => $t,
                            'task_type' => "Block {$b} Task {$t}: Practical Assessment & Evidence",
                            'status' => 'Not Started',
                        ]);
                    }
                }
            } else {
                foreach ($blocks as $block) {
                    for ($t = 1; $t <= 5; $t++) {
                        ApprenticeTask::create([
                            'apprentice_id' => $apprentice->apprentice_id,
                            'ablk_id' => $block->block_num,
                            'task_num' => $t,
                            'task_type' => "Block {$block->block_num} Task {$t}: " . ($block->primary_themes ?? 'Workshop Assessment'),
                            'status' => 'Not Started',
                        ]);
                    }
                }
            }
        });

        return redirect()->route('admin.apprentices.index')->with('success', 'Apprentice setup completed successfully with all curriculum tasks initialized.');
    }
}

