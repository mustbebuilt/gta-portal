<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\TutorGroup;
use App\Services\AttendanceRagService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    public function __construct(
        protected AttendanceRagService $ragService
    ) {}

    /**
     * Display Attendance Overview and group cohorts with live RAG calculation.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();

        // If logged-in user is an Apprentice, show their personal attendance history
        if ($user->role === UserRole::Apprentice) {
            $apprentice = Apprentice::where('user_id', $user->id)->firstOrFail();
            return $this->apprentice($apprentice);
        }

        // Staff & Employer view: List active apprentices with calculated RAG status
        $query = Apprentice::with(['user', 'employer'])
            ->where('status', 'Live');

        if ($user->role === UserRole::Employer) {
            $employer = \App\Models\Employer::where('contact_email', $user->email)->first();
            $query->where('employer_id', $employer?->employer_id ?? -1);
        }

        $apprentices = $query->get()
            ->map(function (Apprentice $apprentice) {
                $ragData = $this->ragService->computeRag($apprentice);
                return [
                    'model' => $apprentice,
                    'rag' => $ragData,
                ];
            });

        $recentSessions = AttendanceSession::with(['creator', 'records'])
            ->orderByDesc('session_datetime')
            ->limit(10)
            ->get();

        $tutorGroups = TutorGroup::all();

        return view('attendance.index', compact('apprentices', 'recentSessions', 'tutorGroups'));
    }

    /**
     * Single Apprentice detailed attendance ledger.
     */
    public function apprentice(Apprentice $apprentice): View
    {
        $rag = $this->ragService->computeRag($apprentice);

        $records = AttendanceRecord::with(['session', 'marker'])
            ->where('apprentice_id', $apprentice->apprentice_id)
            ->orderByDesc('created_at')
            ->get();

        return view('attendance.apprentice', compact('apprentice', 'rag', 'records'));
    }

    /**
     * Store a newly taken session register.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'session_datetime' => ['required', 'date'],
            'topic' => ['required', 'string', 'max:255'],
            'records' => ['required', 'array'],
            'records.*.apprentice_id' => ['required', 'integer'],
            'records.*.status' => ['required', 'in:Present,Late,Authorised Absence,Unauthorised Absence'],
            'records.*.authorised_code' => ['nullable', 'in:L,W,S,H,E'],
            'records.*.comment' => ['nullable', 'string', 'max:500'],
        ]);

        $session = AttendanceSession::create([
            'tutor_group_id' => $request->input('tutor_group_id'),
            'created_by_user_id' => $request->user()->id,
            'session_datetime' => $request->input('session_datetime'),
            'topic' => $request->input('topic'),
        ]);

        foreach ($request->input('records') as $recordData) {
            AttendanceRecord::create([
                'attendance_session_id' => $session->attendance_session_id,
                'apprentice_id' => $recordData['apprentice_id'],
                'marked_by_user_id' => $request->user()->id,
                'status' => $recordData['status'],
                'authorised_code' => $recordData['authorised_code'] ?? null,
                'comment' => $recordData['comment'] ?? null,
                'arrival_datetime' => in_array($recordData['status'], ['Present', 'Late']) ? $request->input('session_datetime') : null,
            ]);
        }

        return redirect()->route('attendance.index')->with('success', 'Attendance register recorded successfully.');
    }
}
