<?php

namespace App\Http\Controllers;

use App\Models\CaseStudy;
use App\Services\CaseStudyApprovalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CaseStudyController extends Controller
{
    public const CATEGORIES = [
        'Additional learning support',
        'Attendance and engagement',
        'Employment and retention',
        'Maths and English',
        'Teaching and assessment',
        'Employer partnership',
        'Careers and progression',
        'Practical barriers and wellbeing',
        'Wider development',
        'Staff development and improvement',
        'Other',
    ];

    public function __construct(
        protected CaseStudyApprovalService $approvalService
    ) {}

    /**
     * Display the Anonymous Case Studies Bank.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', CaseStudy::class);

        $selectedCategory = $request->query('category');
        $user = Auth::user();

        $query = CaseStudy::with(['author', 'approver']);

        if ($selectedCategory) {
            $query->where('category', $selectedCategory);
        }

        // Staff can see all Approved records + their own authored drafts/pending records
        $query->where(function ($q) use ($user) {
            $q->where('status', 'Approved')
              ->orWhere('author_user_id', $user->id);
            if ($user->role->value === 'admin') {
                $q->orWhere('status', 'Pending Approval');
            }
        });

        $caseStudies = $query->orderByDesc('created_at')->get();
        $categories = self::CATEGORIES;

        return view('case-studies.index', compact('caseStudies', 'categories', 'selectedCategory'));
    }

    /**
     * Authoring form for a new anonymous case study.
     */
    public function create(): View
    {
        Gate::authorize('create', CaseStudy::class);
        $categories = self::CATEGORIES;

        return view('case-studies.create', compact('categories'));
    }

    /**
     * Store a newly created case study.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', CaseStudy::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:' . implode(',', self::CATEGORIES)],
            'challenge' => ['required', 'string', 'min:10'],
            'starting_point' => ['required', 'string', 'min:10'],
            'response' => ['required', 'string', 'min:10'],
            'before_state' => ['required', 'string', 'min:5'],
            'before_date' => ['required', 'date'],
            'after_state' => ['required', 'string', 'min:5'],
            'after_date' => ['required', 'date'],
            'evidence' => ['required', 'string', 'min:5'],
            'outcome' => ['required', 'string', 'max:150'],
            'submit_for_approval' => ['nullable', 'boolean'],
        ]);

        $status = $request->boolean('submit_for_approval') ? 'Pending Approval' : 'Draft';

        $caseStudy = CaseStudy::create([
            ...$validated,
            'author_user_id' => $request->user()->id,
            'status' => $status,
        ]);

        $msg = $status === 'Pending Approval'
            ? 'Case study submitted for peer administrator review.'
            : 'Case study saved as a draft.';

        return redirect()->route('case-studies.show', $caseStudy->id)->with('success', $msg);
    }

    /**
     * Display a specific case study.
     */
    public function show(CaseStudy $caseStudy): View
    {
        Gate::authorize('view', $caseStudy);
        $caseStudy->load(['author', 'approver']);

        return view('case-studies.show', compact('caseStudy'));
    }

    /**
     * Approve a case study (enforces Different-Administrator rule).
     */
    public function approve(Request $request, CaseStudy $caseStudy): RedirectResponse
    {
        Gate::authorize('approve', $caseStudy);

        $this->approvalService->approve($caseStudy, $request->user());

        return redirect()->back()->with('success', 'Case study approved and published to the inspection evidence bank.');
    }
}

