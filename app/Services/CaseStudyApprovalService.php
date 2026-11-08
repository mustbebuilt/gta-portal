<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\CaseStudy;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class CaseStudyApprovalService
{
    /**
     * Approve a case study with strict different-administrator enforcement.
     * Preserved Rule from RULES_AND_PERMISSIONS.txt & Integration_Contracts.json:
     * - Only administrators or CEO may approve.
     * - The authoring administrator CANNOT self-approve.
     */
    public function approve(CaseStudy $caseStudy, User $approver): CaseStudy
    {
        if ($approver->role !== UserRole::Admin) {
            throw ValidationException::withMessages([
                'approver' => 'Only administrators or executive leads have authority to approve case studies.',
            ]);
        }

        // Mandatory Handover Rule: Different administrator required
        if ($caseStudy->author_user_id === $approver->id) {
            throw ValidationException::withMessages([
                'approver' => 'A different administrator or CEO must review and approve this case study. Self-approval is strictly prohibited.',
            ]);
        }

        $caseStudy->update([
            'status' => 'Approved',
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);

        return $caseStudy;
    }

    /**
     * Reject a case study back to the author with feedback.
     */
    public function reject(CaseStudy $caseStudy, User $approver): CaseStudy
    {
        if ($approver->role !== UserRole::Admin) {
            throw ValidationException::withMessages([
                'approver' => 'Only administrators can reject case studies.',
            ]);
        }

        $caseStudy->update([
            'status' => 'Rejected',
        ]);

        return $caseStudy;
    }
}

