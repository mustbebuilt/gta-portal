<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\User;

class LearningAccess
{
    public static function manager(User $u): bool
    {
        return $u->active && in_array($u->role, ['admin', 'ceo'], true);
    }

    public static function tutor(User $u, Apprentice $a): bool
    {
        return $u->active && ($u->role === 'tutor' && $a->tutor_id === $u->id);
    }

    public static function learner(User $u, Apprentice $a): bool
    {
        return $u->active && $u->role === 'apprentice' && $a->user_id === $u->id;
    }

    public static function training(User $u, Apprentice $a): bool
    {
        return self::manager($u) || self::tutor($u, $a) || self::learner($u, $a);
    }

    public static function subject(User $u, Apprentice $a, string $subject): bool
    {
        return in_array($subject,['maths','english'],true) && (self::manager($u) || StaffDuties::assigned($u,$a,$subject.'_tutor'));
    }
}
