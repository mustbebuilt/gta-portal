<?php

namespace App\Support;

use App\Models\{Apprentice, StaffDuty, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffDuties
{
    public const LABELS = ['careers_adviser'=>'Careers adviser','mentor'=>'Learning & Progress Mentor','maths_tutor'=>'Maths tutor','english_tutor'=>'English tutor'];

    public static function primary(User $u, string $duty): bool
    {
        if (in_array($duty,['maths_tutor','english_tutor'],true)) {
            return $u->role==='functional_skills_tutor' && in_array($u->subject_scope,[str_replace('_tutor','',$duty),'both'],true);
        }
        return isset(self::LABELS[$duty]) && $u->role===$duty;
    }

    public static function has(User $user, string $duty): bool
    {
        if (! isset(self::LABELS[$duty])) { return false; }
        $u = User::whereKey($user->id)->when(DB::transactionLevel()>0,fn($q)=>$q->lockForUpdate())->first();
        if (! $u || ! $u->active || ! in_array($u->role,DirectoryCatalog::STAFF_ROLES,true)) { return false; }
        return self::primary($u,$duty) || (bool) StaffDuty::whereKey($u->id)->value($duty);
    }

    /** Compatible with Eloquent and the validation presence verifier's query builder. */
    public static function query($query, string $duty)
    {
        if (! isset(self::LABELS[$duty])) { throw new \InvalidArgumentException('Unknown staff duty.'); }
        return $query->where('active',true)->where(function($q) use($duty) {
            $q->where(function($primary) use($duty) {
                if(in_array($duty,['maths_tutor','english_tutor'],true)) {
                    $primary->where('role','functional_skills_tutor')->whereIn('subject_scope',[str_replace('_tutor','',$duty),'both']);
                } else { $primary->where('role',$duty); }
            })->orWhere(function($q) use($duty) {
                $q->whereIn('role',DirectoryCatalog::STAFF_ROLES)->whereExists(function($sub) use($duty) {
                    $sub->selectRaw('1')->from('staff_duties')->whereColumn('staff_duties.user_id','users.id')->where('staff_duties.'.$duty,true);
                });
            });
        });
    }

    public static function assigned(User $user, Apprentice $a, string $duty): bool
    {
        $field = $duty.'_id';
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
            $before=[];$after=[];
            foreach(self::LABELS as $key=>$label) { $before[$key]=(bool)($row?->{$key});$after[$key]=(bool)($flags[$key]??false); }
            if($before===$after) { return false; }
            if(!$u->active && in_array(true,$after,true)) {
                throw ValidationException::withMessages(['duties'=>'Activate and review the staff account before granting additional duties.']);
            }
            $row??=new StaffDuty(['user_id'=>$u->id,'version'=>0]);
            $history=$row->history??[];$history[]=['before'=>$before,'after'=>$after,'by'=>$admin->id,'at'=>now()->toIso8601String()];
            $row->fill($after);$row->version++;$row->history=$history;$row->save();
            AccountAccess::invalidate($u,$admin->id,'staff_duties_changed','staff_duties');
            return true;
        });
    }
}
