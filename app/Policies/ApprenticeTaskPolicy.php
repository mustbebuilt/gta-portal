<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ApprenticeTask;
use App\Models\User;

class ApprenticeTaskPolicy
{
    /**
     * Determine whether the user can view the task.
     */
    public function view(User $user, ApprenticeTask $task): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->role === UserRole::Apprentice) {
            return $task->apprentice->user_id === $user->id;
        }

        if ($user->role === UserRole::Trainer) {
            return $task->apprentice->trainer_id === $user->id;
        }

        if ($user->role === UserRole::Employer) {
            return true; // Employers have read-only view of workplace progress
        }

        return false;
    }

    /**
     * Determine whether the apprentice can upload evidence / submit the task.
     */
    public function submit(User $user, ApprenticeTask $task): bool
    {
        if ($user->role !== UserRole::Apprentice) {
            return false;
        }

        if ($task->apprentice->user_id !== $user->id) {
            return false;
        }

        return in_array($task->status, ['Not Started', 'In Progress', 'Referred'], true);
    }

    /**
     * Determine whether the user can mark / approve / refer the task.
     * Rule: GTA main tutor or admin approves the whole task. Employers cannot approve GTA tasks.
     */
    public function mark(User $user, ApprenticeTask $task): bool
    {
        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->role === UserRole::Trainer) {
            return $task->apprentice->trainer_id === $user->id;
        }

        return false;
    }
}

