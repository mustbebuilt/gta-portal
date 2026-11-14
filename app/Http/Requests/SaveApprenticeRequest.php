<?php

namespace App\Http\Requests;

use App\Models\Apprentice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveApprenticeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('apprentice')
            ? $this->user()->can('update', $this->route('apprentice'))
            : $this->user()->can('create', Apprentice::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reference' => strtoupper(trim((string) $this->input('reference')))]);
    }

    public function attributes(): array
    {
        return [
            'reference' => 'learner reference',
            'first_name' => 'first name',
            'last_name' => 'last name',
            'email' => 'email address',
            'programme_id' => 'apprenticeship programme',
            'start_date' => 'first day in learning',
            'planned_end_date' => 'planned training end date',
            'employer_id' => 'employer',
            'tutor_group_id' => 'tutor group',
            'tutor_id' => 'main tutor',
            'mentor_id' => 'Learning & Progress Mentor',
            'careers_adviser_id' => 'careers adviser',
            'maths_tutor_id' => 'maths tutor',
            'english_tutor_id' => 'English tutor',
            'version' => 'record version',
        ];
    }

    public function rules(): array
    {
        $record = $this->route('apprentice');

        return [
            'reference' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('apprentices')->ignore($record)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'programme' => ['nullable', 'string', 'max:200'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'planned_end_date' => array_filter(['nullable', 'date_format:Y-m-d', $this->input('start_date') ? 'after_or_equal:start_date' : null]),
            'employer' => ['nullable', 'string', 'max:200'],
            'group_name' => ['nullable', 'string', 'max:100'],
            'tutor_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('role', 'tutor')->where('active', true))
                ->when($record?->tutor_id, fn ($q) => $q->orWhere('id', $record->tutor_id)))],
            ...$this->linkedRules($record),
            'version' => $record ? ['required', 'integer', 'min:1'] : ['prohibited'],
        ];
    }

    private function linkedRules(?Apprentice $record): array
    {
        $rules = [];
        foreach (['programme_id' => 'programmes', 'employer_id' => 'employers', 'tutor_group_id' => 'tutor_groups'] as $field => $table) {
            $rules[$field] = ['nullable', 'integer', Rule::exists($table, 'id')->where(fn ($q) => $q->where('active', true)->when($record?->$field, fn ($q) => $q->orWhere('id', $record->$field)))];
        }
        foreach (['mentor_id' => 'mentor', 'careers_adviser_id' => 'careers_adviser', 'maths_tutor_id' => 'functional_skills_tutor', 'english_tutor_id' => 'functional_skills_tutor'] as $field => $role) {
            $subject = $field === 'maths_tutor_id' ? 'maths' : ($field === 'english_tutor_id' ? 'english' : null);
            $rules[$field] = ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where(fn ($q) => $q->where('active', true)->where('role', $role)->when($subject, fn ($q) => $q->whereIn('subject_scope', [$subject, 'both'])))->when($record?->$field, fn ($q) => $q->orWhere('id', $record->$field)))];
        }

        return $rules;
    }
}
