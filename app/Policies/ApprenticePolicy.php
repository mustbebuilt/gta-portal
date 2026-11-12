<?php

namespace App\Policies;

use App\Models\Apprentice;
use App\Models\User;

class ApprenticePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->active && in_array($user->role, ['admin', 'ceo', 'tutor', 'apprentice'], true);
    }

    public function create(User $user): bool
    {
        return $user->active && in_array($user->role, ['admin', 'ceo'], true);
    }

    public function update(User $user, Apprentice $apprentice): bool
    {
        return $this->create($user);
    }

    public function view(User $user, Apprentice $apprentice): bool
    {
        return $this->viewAny($user) && ($this->create($user)
            || ($user->role === 'tutor' && $apprentice->tutor_id === $user->id)
            || ($user->role === 'apprentice' && $apprentice->user_id === $user->id));
    }
}
