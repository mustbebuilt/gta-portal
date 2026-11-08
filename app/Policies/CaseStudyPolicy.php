<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CaseStudy;
use App\Models\User;

class CaseStudyPolicy
{
    /**
     * Determine whether the user can view the case studies list.
     * Rule: Staff only. Apprentices and external users cannot view internal case studies.
     */
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Determine whether the user can view a specific case study.
     * Author or Admin can view drafts; other staff can only view Approved records.
     */
    public function view(User $user, CaseStudy $caseStudy): bool
    {
        if (!$user->isStaff()) {
            return false;
        }

        if ($caseStudy->status === 'Approved') {
            return true;
        }

        return $caseStudy->author_user_id === $user->id || $user->role === UserRole::Admin;
    }

    /**
     * Determine whether the user can create a draft case study.
     */
    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Determine whether the user can update a draft case study.
     */
    public function update(User $user, CaseStudy $caseStudy): bool
    {
        if ($caseStudy->status === 'Approved') {
            return false; // Editing approved records creates a new draft version
        }

        return $caseStudy->author_user_id === $user->id || $user->role === UserRole::Admin;
    }

    /**
     * Determine whether the user can approve a case study.
     * MANDATORY HANDOVER RULE:
     * Approval must be performed by a DIFFERENT administrator than the author.
     * Self-approval by authoring administrators is strictly forbidden.
     */
    public function approve(User $user, CaseStudy $caseStudy): bool
    {
        // 1. Must be an administrator
        if ($user->role !== UserRole::Admin) {
            return false;
        }

        // 2. Author cannot approve their own case study
        if ($caseStudy->author_user_id === $user->id) {
            return false;
        }

        return true;
    }
}

