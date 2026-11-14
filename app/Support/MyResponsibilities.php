<?php
namespace App\Support;
use App\Models\{Apprentice,User};

class MyResponsibilities
{
    public static function allowed(User $user): bool
    {
        return $user->active && in_array($user->role,[...DirectoryCatalog::STAFF_ROLES,'admin','ceo'],true);
    }

    public static function summary(User $user): array
    {
        $u=User::findOrFail($user->id);abort_unless(self::allowed($u),403);
        $roles=[];
        if($u->role==='tutor') { $roles['tutor']=AssignmentChecks::ROLES['tutor']; }
        foreach(StaffDuties::LABELS as $key=>$label) {
            if(StaffDuties::has($u,$key)) { $roles[$key]=$label; }
        }
        $counts=array_fill_keys(array_keys($roles),0);$rows=collect();
        if($roles) {
            // Filter in SQL before names are read; no unrelated people or private case bodies.
            $fields=array_map(fn($key)=>$key.'_id',array_keys($roles));
            $people=Apprentice::select(['id','reference','first_name','last_name',...$fields])
                ->where(function($q) use($fields,$u) { foreach($fields as $field) { $q->orWhere($field,$u->id); } })
                ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->get();
            $rows=$people->map(function($a) use($roles,$u,&$counts) {
                $duties=[];
                foreach($roles as $key=>$label) { if($a->{$key.'_id'}===$u->id) { $duties[$key]=$label;$counts[$key]++; } }
                return ['apprentice'=>$a,'duties'=>$duties];
            });
        }
        return ['roles'=>$roles,'counts'=>$counts,'rows'=>$rows,'total'=>$rows->count()];
    }

    public static function destination(string $role, ?Apprentice $apprentice=null): string
    {
        return match($role) {
            'tutor'=>$apprentice?route('learning.index',$apprentice):route('apprentices.index'),
            'careers_adviser'=>$apprentice?route('careers.index',$apprentice):route('careers.worklist'),
            'mentor'=>$apprentice?route('reviews.create',$apprentice):route('reviews.index'),
            'maths_tutor','english_tutor'=>$apprentice?route('em.index',[$apprentice,'subject'=>str_replace('_tutor','',$role)]):route('em.caseload',['subject'=>str_replace('_tutor','',$role)]),
            default=>throw new \InvalidArgumentException('Unknown responsibility.'),
        };
    }
}
