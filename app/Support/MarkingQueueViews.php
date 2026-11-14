<?php
namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

class MarkingQueueViews
{
    public const VIEWS=['overdue','today','all','date-check','needs-owner'];

    public static function apply(Builder $query,string $view): Builder
    {
        if($view==='overdue'){return $query->where('submitted_at','<',now()->subDays(7));}
        if($view==='today'){
            // Valid, not-yet-overdue seven-calendar-day deadlines falling today.
            return $query->whereNotNull('submitted_at')->where('submitted_at','<=',now())
                ->where('submitted_at','>=',now()->subDays(7))
                ->where('submitted_at','>=',today()->subDays(7))
                ->where('submitted_at','<',today()->addDay()->subDays(7));
        }
        if($view==='date-check'){return $query->where(fn($q)=>$q->whereNull('submitted_at')->orWhere('submitted_at','>',now()));}
        if($view==='needs-owner'){return $query->whereDoesntHave('apprentice.tutor',fn($q)=>$q->where('active',true)->where('role','tutor'));}
        return $query;
    }
    public static function counts(Builder $query): array
    {
        return ['awaiting'=>(clone $query)->count(),'overdue'=>self::apply(clone $query,'overdue')->count(),'due_today'=>self::apply(clone $query,'today')->count(),'date_checks'=>self::apply(clone $query,'date-check')->count(),'owner_checks'=>self::apply(clone $query,'needs-owner')->count()];
    }
}
