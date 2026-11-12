<?php

namespace App\Support;

use App\Models\Employer;
use App\Models\Programme;
use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DirectoryCatalog
{
    public const STAFF_ROLES = ['tutor', 'functional_skills_tutor', 'mentor', 'careers_adviser', 'inclusion_lead', 'inclusion_member', 'manager', 'staff'];

    public static function title(string $kind): string
    {
        return ['employers' => 'Employers', 'programmes' => 'Apprenticeship programmes', 'groups' => 'Tutor groups', 'staff' => 'Staff'][$kind] ?? abort(404);
    }

    public static function query(string $kind): Builder
    {
        return match ($kind) {
            'employers' => Employer::query(), 'programmes' => Programme::query(), 'groups' => TutorGroup::query(),
            'staff' => User::whereIn('role', self::STAFF_ROLES), default => abort(404),
        };
    }

    public static function blank(string $kind): Model
    {
        return match ($kind) {
            'employers' => new Employer, 'programmes' => new Programme, 'groups' => new TutorGroup, 'staff' => new User, default => abort(404)
        };
    }

    public static function fields(string $kind, ?Model $record = null): array
    {
        $active = ['1' => 'Active', '0' => 'Inactive'];
        $roles = ['tutor' => 'Tutor / trainer', 'functional_skills_tutor' => 'Maths / English tutor', 'mentor' => 'Learning & Progress Mentor', 'careers_adviser' => 'Careers adviser', 'inclusion_lead' => 'Inclusion lead', 'inclusion_member' => 'Inclusion team member', 'manager' => 'Manager', 'staff' => 'Staff'];
        $groups = $kind === 'groups' ? [
            'programme_id' => ['Programme', self::options(Programme::query(), $record?->programme_id)],
            'tutor_id' => ['Group tutor', self::options(User::where('role', 'tutor'), $record?->tutor_id)],
        ] : [];

        return match ($kind) {
            'employers' => ['reference' => ['Employer reference *', 'text'], 'name' => ['Employer name *', 'text'], 'contact_name' => ['Workplace contact name', 'text'], 'contact_email' => ['Workplace contact email', 'email'], 'contact_phone' => ['Workplace contact telephone', 'text'], 'size_band' => ['Organisation headcount band', ['1–9' => '1–9', '10–49' => '10–49', '50–249' => '50–249', '250+' => '250+', 'Unknown' => 'Unknown']], 'active' => ['Status *', $active]],
            'programmes' => ['standard_code' => ['Standard code *', 'text'], 'version' => ['Standard version *', 'text'], 'name' => ['Apprenticeship title *', 'text'], 'level' => ['Level *', 'number'], 'active' => ['Status *', $active]],
            'groups' => ['reference' => ['Group reference *', 'text'], 'name' => ['Group name *', 'text'], ...$groups, 'active' => ['Status *', $active]],
            'staff' => ['name' => ['Staff name *', 'text'], 'email' => ['Work email *', 'email'], 'role' => ['Role *', $roles], 'subject_scope' => ['Subject scope (Maths / English tutors)', ['maths' => 'Maths only', 'english' => 'English only', 'both' => 'Maths and English']], 'active' => ['Status *', $active]],
            default => abort(404),
        };
    }

    public static function options(Builder $query, ?int $currentId = null): array
    {
        $model = $query->getModel();
        $ids = (clone $query)->where('active', true)->pluck('id')->all();
        $eligibleIds = $ids;
        if ($currentId) {
            $ids[] = $currentId;
        }

        return $model->newQuery()->whereIn('id', $ids)->orderBy('name')->get()->mapWithKeys(function (Model $row) use ($eligibleIds): array {
            $suffix = $row instanceof Programme ? ' ('.$row->standard_code.' '.$row->version.')' : '';

            $position = ! $row->active ? ' (inactive, current selection)' : (! in_array($row->id, $eligibleIds, true) ? ' (current selection, role/scope changed)' : '');

            return [$row->id => $row->name.$suffix.$position];
        })->all();
    }
}
