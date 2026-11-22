<?php
namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MarkingQueueViews
{
    public const VIEWS=['overdue','today','all','date-check','needs-owner'];

    public static function parameters(mixed $input): array
    {
        if (!is_array($input)) {
            $input = [];
        }
        return [
            'view'=>in_array($input['view'] ?? null, self::VIEWS, true) ? $input['view'] : 'all',
            'search'=>is_string($input['search'] ?? null) ? trim(mb_substr($input['search'], 0, 250)) : '',
            'tutor'=>is_string($input['tutor'] ?? null) ? mb_substr($input['tutor'], 0, 30) : '',
        ];
    }

    public static function filter(Builder $query, string $search, string $tutor): Builder
    {
        if ($tutor === 'unassigned') {
            $query->whereHas('apprentice', fn ($a) => $a->whereNull('tutor_id'));
        } elseif ($tutor !== '') {
            $query->whereHas('apprentice', fn ($a) => $a->where('tutor_id', $tutor));
        }
        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $query->whereHas('apprentice', fn ($a) => $a->where(fn ($name) => $name
                ->where('reference', 'like', $pattern)->orWhere('first_name', 'like', $pattern)->orWhere('last_name', 'like', $pattern)));
        }

        return $query;
    }

    public static function next(User $user, array $filters, int $previous): ?int
    {
        $query = MarkingReminders::query($user)->whereKeyNot($previous);
        $tutor = $filters['tutor'];
        if (!LearningAccess::manager($user)) {
            $tutor = '';
        } elseif ($tutor !== 'unassigned' && $tutor !== '' && !ctype_digit($tutor)) {
            return null;
        }

        return self::apply(self::filter($query, $filters['search'], $tutor), $filters['view'])
            ->orderBy('submitted_at')->orderBy('id')->cursor()
            ->first(function ($task) {
                $attempt = collect($task->submissions ?? [])->last();
                return ($task->template['kind'] ?? 'source-pending') !== 'source-pending'
                    && is_array($attempt) && array_key_exists('decision', $attempt) && $attempt['decision'] === null
                    && ($attempt['template_hash'] ?? null) === ($task->template['sha256'] ?? null);
            })?->id;
    }

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
