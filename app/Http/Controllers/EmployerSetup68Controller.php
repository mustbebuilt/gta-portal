<?php

namespace App\Http\Controllers;

use App\Models\{Apprentice, Employer, ProgrammeOutcome};
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
        $search = trim($input['search'] ?? '');
        $matches = $search === '' ? collect() : Employer::where(fn ($query) => $query
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%'])
            ->orWhereRaw('UPPER(reference)=?', [strtoupper($search)]))->limit(20)->get();
        $employer = isset($input['link']) ? Employer::where('active', true)->findOrFail($input['link']) : null;
        $people = $employer ? Apprentice::visibleTo($request->user())
            ->whereNotIn('id', ProgrammeOutcome::archived()->select('apprentice_id')) : null;

        if ($employer && isset($input['apprentice'])) {
            $person = $people->findOrFail($input['apprentice']);
            return to_route('apprentices.edit', ['apprentice'=>$person, 'link_employer'=>$employer->id]);
        }

        return response()->view('setup68.employer', [
            'token' => old('token', (string) Str::uuid()),
            'matches' => $matches,
            'employer' => $employer,
            'people' => $people?->orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'reference']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $r)
    {
        $v = $r->validate(['token'=>['required','uuid'],'data'=>['required','array']]);
        $e = EmployerWorkflow68::create($r->user(), (int) $r->session()->get('auth_version'), $v['token'], $v['data']);

        return to_route('directories.show', ['employers', $e])->with('status', 'Employer created.');
    }
}
