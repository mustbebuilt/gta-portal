<?php

namespace App\Support;

use App\Models\{Apprentice, StaffDuty, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffDuties
{
    public const LABELS = ['careers_adviser'=>'Careers adviser','mentor'=>'Learning & Progress Mentor'];

    public static function has(User $user, string $duty): bool
    {
        if (! isset(self::LABELS[$duty])) { return false; }
        $u = User::whereKey($user->id)->when(DB::transactionLevel()>0,fn($q)=>$q->lockForUpdate())->first();
        if (! $u || ! $u->active || ! in_array($u->role,DirectoryCatalog::STAFF_ROLES,true)) { return false; }
        return $u->role === $duty || (bool) StaffDuty::whereKey($u->id)->value($duty);
    }

    /** Compatible with Eloquent and the validation presence verifier's query builder. */
    public static function query($query, string $duty)
    {
        if (! isset(self::LABELS[$duty])) { throw new \InvalidArgumentException('Unknown staff duty.'); }
        return $query->where('active',true)->where(function($q) use($duty) {
            $q->where('role',$duty)->orWhere(function($q) use($duty) {
                $q->whereIn('role',DirectoryCatalog::STAFF_ROLES)->whereExists(function($sub) use($duty) {
                    $sub->selectRaw('1')->from('staff_duties')->whereColumn('staff_duties.user_id','users.id')->where('staff_duties.'.$duty,true);
                });
            });
        });
    }

    public static function assigned(User $user, Apprentice $a, string $duty): bool
    {
        $field = $duty === 'mentor' ? 'mentor_id' : 'careers_adviser_id';
        return isset(self::LABELS[$duty]) && $a->{$field} === $user->id && self::has($user,$duty);
    }

    // Existing primary-role sources retain their keys until a duty record exists.
    public static function binding(User $user, string $duty): ?string
    {
        $row=StaffDuty::find($user->id);
        if (! $row) { return null; }
        $u=User::find($user->id);
        return ReviewedImports::hash(['id'=>$u->id,'role'=>$u->role,'auth_version'=>$u->auth_version,
            'version'=>$row->version,'duty'=>$duty,'enabled'=>self::has($u,$duty)]);
    }

    public static function save(User $target, User $actor, int $actorVersion, int $recordVersion, int $dutyVersion, array $flags): bool
    {
        return DB::transaction(function() use($target,$actor,$actorVersion,$recordVersion,$dutyVersion,$flags) {
            $users=User::whereIn('id',[$target->id,$actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $admin=$users->get($actor->id);$u=$users->get($target->id);
            abort_unless($admin && LearningAccess::manager($admin) && $admin->auth_version===$actorVersion,403);
            abort_unless($u && in_array($u->role,DirectoryCatalog::STAFF_ROLES,true),403);
            $row=StaffDuty::whereKey($u->id)->lockForUpdate()->first();
            if($u->record_version!==$recordVersion || ($row?->version??0)!==$dutyVersion) {
                throw ValidationException::withMessages(['duties'=>'This account or its duties changed. Reopen the staff record.']);
            }
            $before=['careers_adviser'=>(bool)($row?->careers_adviser),'mentor'=>(bool)($row?->mentor)];
            $after=['careers_adviser'=>(bool)($flags['careers_adviser']??false),'mentor'=>(bool)($flags['mentor']??false)];
            if($before===$after) { return false; }
            if(!$u->active && ($after['careers_adviser'] || $after['mentor'])) {
                throw ValidationException::withMessages(['duties'=>'Activate and review the staff account before granting additional duties.']);
            }
            $row??=new StaffDuty(['user_id'=>$u->id,'version'=>0]);
            $history=$row->history??[];$history[]=['before'=>$before,'after'=>$after,'by'=>$admin->id,'at'=>now()->toIso8601String()];
            $row->careers_adviser=$after['careers_adviser'];$row->mentor=$after['mentor'];$row->version++;$row->history=$history;$row->save();
            AccountAccess::invalidate($u,$admin->id,'staff_duties_changed','staff_duties');
            return true;
        });
    }
}
