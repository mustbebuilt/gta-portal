<?php
namespace App\Support;

use App\Models\{Apprentice, User};
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AssignmentChecks
{
    public const ROLES = ['tutor'=>'Main tutor','careers_adviser'=>'Careers adviser','mentor'=>'Learning & Progress Mentor','maths_tutor'=>'Maths tutor','english_tutor'=>'English tutor'];
    public const POSITIONS = ['eligible'=>'Eligible for assignment','unassigned'=>'Not assigned','inactive'=>'Inactive account','missing'=>'Account not found','changed'=>'Role or subject duty needs checking'];

    public static function snapshot(): array
    {
        // Only identity/assignment fields. Do not load private case, review, topic or evidence bodies.
        $people=Apprentice::select(['id','reference','first_name','last_name',...array_map(fn($r)=>$r.'_id',array_keys(self::ROLES))])->orderBy('last_name')->orderBy('first_name')->orderBy('id')->get();
        $users=User::select('id','name','role','active')->get()->keyBy('id');
        $eligible=[];
        foreach(self::ROLES as $role=>$label) {
            $query=$role==='tutor' ? User::where('active',true)->where('role','tutor') : StaffDuties::query(User::query(),$role);
            $eligible[$role]=array_fill_keys($query->pluck('id')->all(),true);
        }
        $referenced=[];
        $rows=$people->map(function($a) use($users,$eligible,&$referenced) {
            $slots=[];
            foreach(self::ROLES as $role=>$label) {
                $id=$a->{$role.'_id'};$u=$id ? $users->get($id) : null;
                if($id) { $referenced[$id]=true; }
                $position=!$id?'unassigned':(!$u?'missing':(!$u->active?'inactive':(isset($eligible[$role][$id])?'eligible':'changed')));
                $slots[$role]=['role'=>$role,'label'=>$label,'user_id'=>$id,'name'=>$u?->name,'position'=>$position,
                    'can_edit_staff'=>$u && in_array($u->role,DirectoryCatalog::STAFF_ROLES,true)];
            }
            return ['apprentice'=>$a,'slots'=>$slots];
        });
        $staff=$users->filter(fn($u)=>isset($referenced[$u->id])||in_array($u->role,DirectoryCatalog::STAFF_ROLES,true))->sortBy(fn($u)=>Str::lower($u->name))->values();
        return ['rows'=>$rows,'staff'=>$staff];
    }

    public static function filter(Collection $rows, array $filters): array
    {
        $search=Str::lower(trim($filters['search']??''));$role=$filters['role']??'all';$staff=$filters['staff']??null;
        $selected=$rows->map(function($row) use($role,$staff,$search) {
            $row['slots']=array_filter($row['slots'],fn($s)=>($role==='all'||$s['role']===$role)&&(!$staff||$s['user_id']===(int)$staff));
            if(!$row['slots']) { return null; }
            $a=$row['apprentice'];$names=implode(' ',array_column($row['slots'],'name'));
            if($search!==''&&!Str::contains(Str::lower($a->reference.' '.$a->first_name.' '.$a->last_name.' '.$names),$search)) { return null; }
            return $row;
        })->filter()->values();
        $counts=['apprentices'=>$selected->count(),'eligible'=>0,'unassigned'=>0,'checks'=>0];
        foreach($selected as $row) { foreach($row['slots'] as $slot) {
            $key=in_array($slot['position'],['eligible','unassigned'],true)?$slot['position']:'checks';$counts[$key]++;
        } }
        $view=$filters['view']??'checks';
        $visible=$selected->filter(function($row) use($view) {
            if($view==='all') { return true; }
            foreach($row['slots'] as $slot) {
                if($view==='unassigned'&&$slot['position']==='unassigned') { return true; }
                if($view==='checks'&&!in_array($slot['position'],['eligible','unassigned'],true)) { return true; }
            }
            return false;
        })->values();
        return ['rows'=>$visible,'counts'=>$counts];
    }
}
