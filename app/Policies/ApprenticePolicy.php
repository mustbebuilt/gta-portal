<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Apprentice;
use App\Models\User;

class ApprenticePolicy
{
    /**
     * Determine whether the user can view the apprentices directory.
     */
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->role === UserRole::Employer;
    }

    /**
     * Determine whether the user can view a specific apprentice's details.
     */
    public function view(User $user, Apprentice $apprentice): bool
    {
        // 1. Administrators have unrestricted access
        if ($user->role === UserRole::Admin) {
            return true;
        }

        // 2. The apprentice can view their own profile
        if ($user->role === UserRole::Apprentice) {
            return $apprentice->user_id === $user->id;
        }

        // 3. Tutors can view learners they are assigned to teach
        if ($user->role === UserRole::Trainer) {
            return $apprentice->trainer_id === $user->id;
        }

        // 4. Employers can only view apprentices employed at their organisation
        if ($user->role === UserRole::Employer) {
            return $apprentice->employer?->contact_email === $user->email;
        }

        // 5. Mentors can view assigned caseload
        if ($user->role === UserRole::Mentor) {
            return true;
        }

        return false;
    }

    /**
     * Privacy Firewall: Determine whether the user can view confidential support notes / ALS / Careers.
     * Rule from RULES_AND_PERMISSIONS.txt: Employers are strictly barred from private support/Careers.
     */
    public function viewConfidentialSupport(User $user, Apprentice $apprentice): bool
    {
        // Employers are strictly excluded
        if ($user->role === UserRole::Employer) {
            return false;
        }

        // Apprentices cannot view internal staff safeguarding / support deliberation
        if ($user->role === UserRole::Apprentice) {
            return false;
        }

        // Admin, assigned mentor, and authorized trainers have access
        return $user->isStaff();
    }
}

