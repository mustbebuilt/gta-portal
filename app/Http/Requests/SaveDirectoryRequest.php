<?php

namespace App\Http\Requests;

use App\Support\DirectoryCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SaveDirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-directories');
    }

    protected function prepareForValidation(): void
    {
        foreach (['reference', 'standard_code'] as $key) {
            if ($this->has($key)) {
                $this->merge([$key => strtoupper(trim((string) $this->input($key)))]);
            }
        }
    }

    public function attributes(): array
    {
        return [
            'name' => 'name',
            'active' => 'active status',
            'record_version' => 'record version',
            'reference' => 'reference',
            'contact_name' => 'contact name',
            'contact_email' => 'contact email address',
            'contact_phone' => 'contact phone number',
            'size_band' => 'employer size band',
            'standard_code' => 'standard code',
            'version' => 'standard version',
            'level' => 'level',
            'programme_id' => 'programme',
            'tutor_id' => 'main tutor',
            'email' => 'email address',
            'role' => 'staff role',
            'subject_scope' => 'maths/English subject scope',
        ];
    }

    public function rules(): array
    {
        $kind = $this->route('kind');
        $record = $this->route('record') ? DirectoryCatalog::query($kind)->findOrFail($this->route('record')) : null;
        $common = ['name' => ['required', 'string', 'max:200'], 'active' => ['required', 'boolean'], 'record_version' => $record ? ['required', 'integer', 'min:1'] : ['prohibited']];
        $rules = match ($kind) {
            'employers' => ['reference' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('employers')->ignore($record)], 'contact_name' => ['nullable', 'string', 'max:150'], 'contact_email' => ['nullable', 'email:rfc', 'max:254'], 'contact_phone' => ['nullable', 'string', 'max:50'], 'size_band' => ['nullable', Rule::in(['1–9', '10–49', '50–249', '250+', 'Unknown'])]],
            'programmes' => ['standard_code' => ['required', 'string', 'max:40', Rule::unique('programmes')->where('version', $this->input('version'))->ignore($record)], 'version' => ['required', 'string', 'max:30'], 'level' => ['required', 'integer', 'min:1', 'max:9']],
            'groups' => ['reference' => ['required', 'string', 'max:40', Rule::unique('tutor_groups')->ignore($record)], 'programme_id' => ['nullable', 'integer', Rule::exists('programmes', 'id')->where(fn ($q) => $q->where('active', true)->when($record?->programme_id, fn ($q) => $q->orWhere('id', $record->programme_id)))], 'tutor_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where(fn ($q) => $q->where('role', 'tutor')->where('active', true))->when($record?->tutor_id, fn ($q) => $q->orWhere('id', $record->tutor_id)))]],
            'staff' => ['email' => ['required', 'email:rfc', 'max:254', Rule::unique('users')->ignore($record)], 'role' => ['required', Rule::in(DirectoryCatalog::STAFF_ROLES)], 'subject_scope' => ['nullable', 'required_if:role,functional_skills_tutor', Rule::in(['maths', 'english', 'both'])]],
            default => abort(404),
        };

        return [...$common, ...$rules];
    }
}
