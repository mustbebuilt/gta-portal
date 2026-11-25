<?php

namespace App\Support;

use App\Models\TrainingTopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SubjectMarkingQueue
{
    public static function allowedSubjects(User $user): array
    {
        return array_values(array_filter(['maths', 'english'], fn ($subject) =>
            LearningAccess::manager($user) || StaffDuties::has($user, $subject.'_tutor')
        ));
    }

    public static function filters(array $input): array
    {
        $values = Validator::make($input, [
            'subject' => ['nullable', Rule::in(['all', 'english', 'maths'])],
            'search' => ['nullable', 'string', 'max:100'],
        ])->validate();

        return ['subject' => $values['subject'] ?? 'all', 'search' => trim($values['search'] ?? '')];
    }

    public static function query(User $user, array $subjects, array $filters): Builder
    {
        $query = TrainingTopic::where('evidence_stage', 'Awaiting review')
            ->whereIn('subject', $subjects)
            ->with('apprentice:id,first_name,last_name,reference');

        if (!LearningAccess::manager($user)) {
            $query->where(function (Builder $scope) use ($user, $subjects) {
                $scope->whereRaw('1 = 0');
                foreach ($subjects as $subject) {
                    $scope->orWhere(fn (Builder $topics) => $topics->where('subject', $subject)
                        ->whereHas('apprentice', fn (Builder $people) => $people->where($subject.'_tutor_id', $user->id)));
                }
            });
        }

        if ($filters['subject'] !== 'all') {
            $query->where('subject', $filters['subject']);
        }
        if ($filters['search'] !== '') {
            $pattern = '%'.$filters['search'].'%';
            $query->whereHas('apprentice', fn (Builder $people) => $people->where(fn (Builder $name) => $name
                ->where('reference', 'like', $pattern)
                ->orWhere('first_name', 'like', $pattern)
                ->orWhere('last_name', 'like', $pattern)));
        }

        return $query->orderBy('updated_at')->orderBy('id');
    }

    public static function pendingSubmission(TrainingTopic $topic): ?array
    {
        $submission = collect($topic->submissions ?? [])->last();

        return is_array($submission) && $submission !== [] && ($submission['decision'] ?? null) === null
            ? $submission
            : null;
    }

    public static function next(User $user, array $subjects, array $filters): ?int
    {
        return self::query($user, $subjects, $filters)->select('id', 'submissions')->cursor()
            ->first(fn (TrainingTopic $topic) => self::pendingSubmission($topic) !== null)?->id;
    }

    public static function url(int $topic, array $filters): string
    {
        return route('topics.show', ['topic' => $topic, 'from_marking' => 1] + $filters);
    }

    public static function indexUrl(array $filters = []): string
    {
        return route('marking.index', ['kind' => 'skills'] + $filters);
    }
}
