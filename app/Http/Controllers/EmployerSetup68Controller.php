<?php

namespace App\Http\Controllers;

use App\Models\{Apprentice, Employer, EmployerApprenticeGrant, ProgrammeOutcome};
use App\Support\AssignmentHistory;
use App\Support\EmployerActions;
use App\Support\EntryContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use App\Support\EmployerWorkflow68;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmployerSetup68Controller extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->user()->can('create', Apprentice::class), 403);
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'link' => ['nullable', 'integer', 'min:1'],
            'apprentice' => ['nullable', 'integer', 'min:1', 'required_with:link_selection'],
        ]);
        $person = isset($input['apprentice'])
            ? Apprentice::visibleTo($request->user())->whereNotIn('id', ProgrammeOutcome::archived()->select('apprentice_id'))->findOrFail($input['apprentice'])
            : null;
        $search = trim($input['search'] ?? '');
        $matches = $search === '' ? collect() : Employer::where(fn ($query) => $query
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%'])
            ->orWhereRaw('UPPER(reference)=?', [strtoupper($search)]))->limit(20)->get();
        $employer = isset($input['link']) ? Employer::where('active', true)->findOrFail($input['link']) : null;
        $people = $employer ? Apprentice::visibleTo($request->user())
            ->whereNotIn('id', ProgrammeOutcome::archived()->select('apprentice_id')) : null;

        if ($employer && $person) {
            return to_route('apprentices.edit', ['apprentice'=>$person, 'link_employer'=>$employer->id]);
        }

        $choices = $person && ! $employer ? Employer::where('active', true)->where('id', '!=', $person->employer_id ?? 0)
            ->orderBy('name')->orderBy('reference')->get(['id', 'name', 'reference', 'record_version']) : collect();

        return response()->view('setup68.employer', [
            'choices' => $choices,
            'token' => old('token', (string) Str::uuid()),
            'matches' => $matches,
            'apprentice' => $person,
            'employer' => $employer,
            'people' => $people?->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'reference']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function change(Request $request, Apprentice $apprentice)
    {
        Gate::authorize('update', $apprentice);
        if ($request->has('employer_choice')) {
            $choice = $request->validate(['employer_choice' => ['required', 'string', 'regex:/^[1-9][0-9]{0,18}:[1-9][0-9]{0,18}$/D']]);
            [$id, $version] = explode(':', $choice['employer_choice']);
            $request->merge(['employer_id' => $id, 'employer_version' => $version]);
        }
        $input = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'employer_id' => ['required', 'integer', 'min:1'],
            'employer_version' => ['required', 'integer', 'min:1'],
            'effective_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($request, $apprentice, $input): void {
            $profile = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            $actor = EmployerActions::actor($request->user(), (int) $request->session()->get('auth_version'), $profile, true);
            abort_if(ProgrammeOutcome::archived()->where('apprentice_id', $profile->id)->exists(), 404);
            if ($profile->version !== (int) $input['version']) {
                throw ValidationException::withMessages(['version' => 'The apprentice record changed. Reopen the employer change before saving.']);
            }
            $employer = Employer::whereKey($input['employer_id'])->lockForUpdate()->first();
            if (! $employer?->active || $employer->record_version !== (int) $input['employer_version']) {
                throw ValidationException::withMessages(['employer_id' => 'The employer record changed. Select it again from the directory.']);
            }
            if ($profile->employer_id === $employer->id) {
                return;
            }
            $before = $profile->only(AssignmentHistory::KEYS);
            $profile->employer_id = $employer->id;
            $profile->employer = $employer->name;
            $profile->version++;
            $profile->save();
            AssignmentHistory::record($profile, $actor->id, $before);
            EntryContext::employment($actor, $profile, [
                'entry_employer_id' => $employer->id,
                'effective_on' => $input['effective_on'],
                'reason' => $input['reason'] ?? ($before['employer_id'] ? 'Employer changed' : 'Employer linked'),
            ]);
            $grants = EmployerApprenticeGrant::where('apprentice_id', $profile->id)
                ->where('active', true)->orderBy('id')->get();
            foreach ($grants as $grant) {
                EmployerActions::revoke($actor, $actor->auth_version, $grant, $grant->version, 'Linked employer changed');
            }
        });

        return to_route('apprentices.edit', ['apprentice' => $apprentice, 'link_employer' => $input['employer_id']])
            ->with('status', 'Employer link saved.');
    }

    public function store(Request $r)
    {
        $v = $r->validate(['token'=>['required','uuid'],'data'=>['required','array'],'apprentice'=>['nullable','integer']]);
        $person = isset($v['apprentice']) ? Apprentice::visibleTo($r->user())
            ->whereNotIn('id', ProgrammeOutcome::archived()->select('apprentice_id'))->findOrFail($v['apprentice']) : null;
        $e = EmployerWorkflow68::create($r->user(), (int) $r->session()->get('auth_version'), $v['token'], $v['data']);

        if ($person) {
            return to_route('apprentices.edit', ['apprentice' => $person, 'link_employer' => $e->id])
                ->with('status', 'Employer created. Confirm the link below.');
        }

        return to_route('directories.show', ['employers', $e])->with('status', 'Employer created.');
    }
}
