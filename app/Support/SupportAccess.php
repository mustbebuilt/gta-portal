<?php

namespace App\Support;

use App\Models\Apprentice;
use App\Models\SupportCapability;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportAccess
{
    public const DUTIES = ['staff' => 'No Inclusion Team duty', 'member' => 'Inclusion Team member', 'lead' => 'Inclusion Team lead'];

    public const SCOPES = ['assigned' => 'Assigned apprentices', 'all' => 'All apprentices'];

    public static function settings(User $user): array
    {
        $u = User::whereKey($user->id)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
        if (! $u || ! in_array($u->role, [...DirectoryCatalog::STAFF_ROLES, 'admin', 'ceo'], true)) {
            return ['duty' => 'staff', 'scope' => 'assigned', 'apprentice_ids' => [], 'group_ids' => [], 'version' => 0, 'eligible' => false, 'admin' => false];
        }
        $row = SupportCapability::where('user_id', $u->id)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
        $duty = $row?->duty ?? match ($u->role) {
            'inclusion_lead' => 'lead','inclusion_member' => 'member',default => 'staff'
        };
        $scope = $row?->scope ?? (in_array($u->role, ['admin', 'ceo', 'inclusion_lead', 'inclusion_member'], true) ? 'all' : 'assigned');
        if (! array_key_exists($duty, self::DUTIES) || ! array_key_exists($scope, self::SCOPES)) {
            $duty = 'staff';
            $scope = 'assigned';
        }

        return ['duty' => $duty, 'scope' => $scope, 'apprentice_ids' => $row?->apprentice_ids ?? [], 'group_ids' => $row?->group_ids ?? [], 'version' => $row?->version ?? 0, 'eligible' => (bool) $u->active, 'admin' => in_array($u->role, ['admin', 'ceo'], true)];
    }

    public static function ids(User $u): array
    {
        $s = self::settings($u);
        if (! $s['eligible'] || (! $s['admin'] && ! in_array($s['duty'], ['member', 'lead'], true))) {
            return [];
        }
        if ($s['admin'] || $s['scope'] === 'all') {
            return Apprentice::pluck('id')->all();
        }

        return Apprentice::where(function ($q) use ($u, $s): void {
            $q->whereIn('id', $s['apprentice_ids'])->orWhereIn('tutor_group_id', $s['group_ids']);
            foreach (['tutor_id', 'maths_tutor_id', 'english_tutor_id', 'mentor_id', 'careers_adviser_id'] as $key) {
                $q->orWhere($key, $u->id);
            }$q->orWhereHas('groupRecord', fn ($g) => $g->where('tutor_id', $u->id));
        })->pluck('id')->all();
    }

    public static function team(User $u, ?Apprentice $a = null): bool
    {
        $s = self::settings($u);

        return $s['eligible'] && ($s['admin'] || in_array($s['duty'], ['member', 'lead'], true)) && (! $a || in_array($a->id, self::ids($u), true));
    }

    public static function lead(User $u, ?Apprentice $a = null): bool
    {
        $s = self::settings($u);

        return self::team($u, $a) && ($s['admin'] || $s['duty'] === 'lead');
    }

    public static function writer(User $u, Apprentice $a): bool
    {
        $s = self::settings($u);

        return self::team($u, $a) && in_array($s['duty'], ['member', 'lead'], true);
    }

    public static function approver(User $u, Apprentice $a): bool
    {
        return self::writer($u, $a) && self::settings($u)['duty'] === 'lead';
    }

    public static function owners(Apprentice $a): Collection
    {
        return User::where('active', true)->whereIn('role', [...DirectoryCatalog::STAFF_ROLES, 'admin', 'ceo'])->get()->filter(fn ($u) => self::team($u, $a))->values();
    }

    public static function assertSingleLead(User $target): void
    {
        if (! $target->active) {
            return;
        }$explicit = $target->exists ? SupportCapability::where('user_id', $target->id)->first() : null;
        if (($explicit?->duty ?? ($target->role === 'inclusion_lead' ? 'lead' : 'staff')) !== 'lead') {
            return;
        }
        foreach (User::where('active', true)->when($target->exists, fn ($q) => $q->where('id', '!=', $target->id))->get() as $other) {
            if (self::settings($other)['duty'] === 'lead') {
                throw ValidationException::withMessages(['duty' => 'An active Inclusion Team lead already exists. Remove that duty before assigning a replacement.']);
            }
        }
    }
}
