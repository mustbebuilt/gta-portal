<?php

namespace App\Support;

use App\Http\Requests\SaveApprenticeRequest;
use App\Models\Apprentice;
use App\Models\ApprenticeSetupDraft68 as Draft;
use App\Models\Employer;
use App\Models\IndividualDeliveryPlan;
use App\Models\LearningTask;
use App\Models\Programme;
use App\Models\ProgrammeOutcome;
use App\Models\ProgrammeSetupTemplate68 as Template;
use App\Models\TrainingPlanWorkspace;
use App\Models\TutorGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GuidedSetup68
{
    public const STEPS = [1 => 'Apprentice details', 2 => 'Inclusion Markers', 3 => 'Apprenticeship', 4 => 'Prior learning', 5 => 'Employer', 6 => 'Functional Skills', 7 => 'Review and confirm'];

    public static function actor(User $actor, int $auth): User
    {
        $u = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        abort_unless(LearningAccess::manager($u) && $u->auth_version === $auth && $u->can('create', Apprentice::class), 403);

        return $u;
    }

    public static function start(User $u, int $auth): Draft
    {
        return DB::transaction(function () use ($u, $auth) {
            self::actor($u, $auth);
            $d = new Draft;
            $d->id = (string) Str::uuid();
            $d->owner_id = $u->id;
            $d->payload = ['authors' => [$u->id], 'step' => 1];
            $d->history = [];
            $d->save();

            return $d;
        });
    }

    public static function event(Draft $d, User $u, string $event, array $meta = []): void
    {
        $d->history = [...($d->history ?? []), ['event' => $event, 'actor' => $u->id, 'at' => now()->toIso8601String(), 'revision' => $d->version, ...$meta]];
    }

    public static function lock(Draft $draft, int $version): Draft
    {
        $d = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
        abort_if($d->version !== $version, 409, 'Draft changed. Reload before saving.');

        return $d;
    }

    public static function template(array $p): ?Template
    {
        $t = Template::find($p['programme']['template_id'] ?? 0);
        $programme = $t ? Programme::find($t->programme_id) : null;

        return $programme?->active && $t && self::templateMatchesProgramme($t, $programme) ? $t : null;
    }

    private static function templateMatchesProgramme(Template $template, Programme $programme): bool
    {
        return $template->active && Rpl68::templateMatches($template)
            && strtoupper($programme->standard_code) === strtoupper($template->template['standard_code'] ?? '')
            && ltrim(strtolower($programme->version), 'v') === ltrim(strtolower($template->template['standard_version'] ?? ''), 'v');
    }

    public static function programmeChoices(): array
    {
        $programmes = Programme::orderBy('name')->orderBy('standard_code')->orderBy('version')->get();
        $templates = Template::whereIn('programme_id', $programmes->pluck('id'))->get()->keyBy('programme_id');
        $available = collect();
        $unavailable = [];
        $registered = [];
        foreach ($programmes as $programme) {
            $key = TaskCatalogue::keyForStandard($programme->standard_code, $programme->version);
            if ($key) {
                $registered[$key] = true;
            }
            if (! $programme->active) {
                if ($key) {
                    $unavailable[] = ['name'=>$programme->name, 'code'=>$programme->standard_code, 'version'=>$programme->version, 'reason'=>'Programme is inactive'];
                }
                continue;
            }
            $template = $templates->get($programme->id);
            if ($key && $template && self::templateMatchesProgramme($template, $programme)) {
                $available->push($template);

                continue;
            }
            $reason = match (true) {
                ! $key => 'Controlled task catalogue missing',
                ! $template => 'Approved training template missing',
                default => 'Training template inactive or does not match the programme',
            };
            $unavailable[] = ['name'=>$programme->name, 'code'=>$programme->standard_code, 'version'=>$programme->version, 'reason'=>$reason];
        }
        foreach (array_diff_key(TaskCatalogue::PROGRAMMES, $registered) as $source) {
            $unavailable[] = [...$source, 'reason'=>'Programme missing from the directory'];
        }

        return ['templates'=>$available, 'unavailableProgrammes'=>$unavailable];
    }

    public static function employerKey(array $e): string
    {
        return isset($e['id']) && $e['id'] ? 'existing:'.$e['id'] : 'new:'.strtoupper(trim($e['reference'] ?? '')).':'.mb_strtolower(trim($e['name'] ?? ''));
    }

    public static function matches(array $v, ?int $except = null): array
    {
        $ref = strtoupper(trim($v['reference'] ?? ''));
        $email = mb_strtolower(trim($v['email'] ?? ''));
        $first = mb_strtolower(trim($v['first_name'] ?? ''));
        $last = mb_strtolower(trim($v['last_name'] ?? ''));
        if ($ref === '' && $email === '' && ($first === '' || $last === '')) {
            return [];
        }

        return Apprentice::when($except, fn ($q) => $q->whereKeyNot($except))->where(function ($q) use ($ref, $email, $first, $last) {
            $q->whereRaw('1=0');
            if ($ref !== '') {
                $q->orWhereRaw('UPPER(reference)=?', [$ref]);
            }if ($email !== '') {
                $q->orWhereRaw('LOWER(email)=?', [$email]);
            }if ($first !== '' && $last !== '') {
                $q->orWhere(fn ($q) => $q->whereRaw('LOWER(first_name)=?', [$first])->whereRaw('LOWER(last_name)=?', [$last]));
            }
        })->limit(20)->get(['id', 'reference', 'first_name', 'last_name', 'email'])->map(fn ($a) => ['id' => $a->id, 'reference' => $a->reference, 'name' => $a->first_name.' '.$a->last_name, 'exact_reference' => strtoupper($a->reference) === $ref, 'exact_email' => $email !== '' && mb_strtolower($a->email ?? '') === $email])->all();
    }

    public static function save(User $u, int $auth, Draft $draft, int $version, int $step, array $input): Draft
    {
        return DB::transaction(function () use ($u, $auth, $draft, $version, $step, $input) {
            $u = self::actor($u, $auth);
            $d = self::lock($draft, $version);
            abort_unless($d->state === 'Draft', 409, 'Reopen this record with a reason before correcting it.');
            $p = $d->payload;
            $before = $p;
            if ($step === 1) {
                $v = Validator::make($input, ['reference' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'], 'first_name' => ['nullable', 'string', 'max:100'], 'last_name' => ['nullable', 'string', 'max:100'], 'email' => ['nullable', 'email:rfc', 'max:254'], 'duplicate_reason' => ['nullable', 'string', 'max:500']])->validate();
                foreach ($v as $k => $value) {
                    $v[$k] = is_string($value) ? trim($value) : $value;
                }$v['reference'] = strtoupper($v['reference'] ?? '');
                $p['details'] = $v;
                if (isset($before['details']) && $before['details'] !== $v) {
                    unset($p['rpl']['remaining_confirmed'],$p['rpl']['confirmation_hash'],$p['employer']['agreement_verified']);
                }
            } elseif ($step === 2) {
                abort_unless(SupportAccess::lead($u), 403);
                $codes = json_decode(file_get_contents(resource_path('data/inclusion-markers.json')), true, 512, JSON_THROW_ON_ERROR);
                $v = Validator::make($input, ['state' => ['nullable', 'in:selected,none,pending'], 'codes' => ['nullable', 'array'], 'codes.*' => ['string', Rule::in(array_keys($codes))], 'other' => ['nullable', 'string', 'max:500']])->validate();
                if (($v['state'] ?? null) !== 'selected') {
                    $v['codes'] = [];
                    $v['other'] = null;
                } elseif (! in_array('IM40', $v['codes'] ?? [], true)) {
                    $v['other'] = null;
                }$v['codes'] = array_values(array_unique($v['codes'] ?? []));
                $p['markers'] = $v;
            } elseif ($step === 3) {
                $v = Validator::make($input, ['template_id' => ['nullable', 'integer'], 'start_date' => ['nullable', 'date_format:Y-m-d'], 'tutor_id' => ['nullable', 'integer'], 'tutor_group_id' => ['nullable', 'integer'], 'mentor_id' => ['nullable', 'integer'], 'careers_adviser_id' => ['nullable', 'integer'], 'maths_tutor_id' => ['nullable', 'integer'], 'english_tutor_id' => ['nullable', 'integer']])->validate();
                $previous = $before['programme'] ?? [];
                if (empty($v['template_id']) && !empty($previous['template_id'])) {
                    $v['template_id'] = $previous['template_id'];
                    $v['template_hash'] = $previous['template_hash'] ?? null;
                } else {
                    $t = self::template(['programme' => $v]);
                    if (!empty($v['template_id']) && ! $t) {
                        Rpl68::fail('Choose an active, controlled template matching the programme standard/version.');
                    }
                    $v['template_hash'] = $t?->source_hash;
                }
                $templateChanged = (int) ($previous['template_id'] ?? 0) !== (int) ($v['template_id'] ?? 0);
                if (! $templateChanged && isset($previous['template_id'])) {
                    // Keep earlier draft/date bindings stable when an HTML form posts the same ID as a string.
                    $v['template_id'] = $previous['template_id'];
                }
                $p['programme'] = $v;
                if ($templateChanged || ($previous['template_hash'] ?? null) !== ($v['template_hash'] ?? null)) {
                    unset($p['rpl']['remaining_confirmed'], $p['rpl']['confirmation_hash'], $p['employer']['agreement_verified']);
                    if ($templateChanged) {
                        $p['rpl'] = [];
                    }
                }
            } elseif ($step === 4) {
                $t = self::template($p);
                if (! $t) {
                    Rpl68::fail('Select the apprenticeship template first.');
                }$v = Validator::make($input, ['mode' => ['nullable', 'in:none,applies'], 'assessment_ref' => ['nullable', 'string', 'max:1000'], 'adjustments' => ['nullable', 'array'], 'remaining_confirmed' => ['nullable', 'boolean']])->validate();
                $v['adjustments'] = $v['adjustments'] ?? [];
                if (($v['mode'] ?? null) === 'none') {
                    $v = ['mode' => 'none', 'adjustments' => [], 'remaining_confirmed' => true];
                }Rpl68::calculate($t->template, $v);
                if (! empty($v['remaining_confirmed'])) {
                    $v['confirmation_hash'] = Rpl68::hash([$t->source_hash, $v['mode'] ?? null, $v['assessment_ref'] ?? null, $v['adjustments']]);
                }if (($p['rpl'] ?? []) !== $v) {
                    unset($p['employer']['agreement_verified']);
                }$p['rpl'] = $v;
            } elseif ($step === 5) {
                $v = Validator::make($input, ['id' => ['nullable', 'integer'], 'reference' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'], 'name' => ['nullable', 'string', 'max:200'], 'contact_name' => ['nullable', 'string', 'max:150'], 'contact_email' => ['nullable', 'email:rfc', 'max:254'], 'contact_phone' => ['nullable', 'string', 'max:50'], 'size_band' => ['nullable', Rule::in(['1–9', '10–49', '50–249', '250+', 'Unknown'])], 'use_contact' => ['nullable', 'boolean'], 'agreement_ref' => ['nullable', 'string', 'max:1000'], 'agreement_verified' => ['nullable', 'boolean'], 'duplicate_reason' => ['nullable', 'string', 'max:500']])->validate();
                if (! empty($v['id'])) {
                    $e = Employer::find($v['id']);
                    if (! $e?->active) {
                        Rpl68::fail('Choose an active employer.');
                    }$v['id'] = (int) $e->id;
                    $v = array_intersect_key($v, array_flip(['id', 'use_contact', 'agreement_ref', 'agreement_verified']));
                    $v['record_version'] = $e->record_version;
                } else {
                    $v['reference'] = strtoupper(trim($v['reference'] ?? ''));
                }
                if (self::employerKey($p['employer'] ?? []) !== self::employerKey($v) || ($p['employer']['record_version'] ?? null) !== ($v['record_version'] ?? null)) {
                    $v['agreement_verified'] = false;
                }
                if (! empty($v['agreement_verified'])) {
                    $v['agreement_binding'] = Rpl68::hash([self::employerKey($v), $v['record_version'] ?? null, $p['rpl'] ?? [], $v['agreement_ref'] ?? null]);
                }$p['employer'] = $v;
            } elseif ($step === 6) {
                $p['functional_skills82'] = FunctionalSkillsSetup82::validate($input);
                $p['fs_outcome_version82'] = (int) ($input['record_version82'] ?? 0);
                unset($p['fs_applied82']);
            } else {
                Rpl68::fail('Choose a setup step from one to six.');
            }
            if ($d->apprentice_id && ! isset($p['apprentice_version'])) {
                $p['apprentice_version'] = Apprentice::findOrFail($d->apprentice_id)->version;
            }
            $p['step'] = $step;
            $p['authors'] = array_values(array_unique([...($p['authors'] ?? []), $u->id]));
            $d->payload = $p;
            $d->version++;
            self::event($d, $u, 'step_saved', ['step' => $step, ...($step === 4 ? ['rpl_before' => $before['rpl'] ?? [], 'rpl_after' => $p['rpl'] ?? []] : [])]);
            $d->save();

            return $d;
        });
    }

    public static function savePlanDates(User $u, int $auth, Draft $draft, int $version, array $input): Draft
    {
        return DB::transaction(function () use ($u, $auth, $draft, $version, $input) {
            self::actor($u, $auth);
            $d = self::lock($draft, $version);
            abort_unless($d->state === 'Draft', 409);
            $p = $d->payload;
            $template = self::template($p);
            $policy = $template ? RplPlanDates69::policy($template->template) : null;
            if (! $policy || empty($p['programme']['start_date'])) {
                Rpl68::fail('Choose the approved programme and start date first.');
            }

            $timetable = ($template->template['date_basis'] ?? null) === 'agreed_timetable';
            $dates = Validator::make($input, [
                'source' => ['required', 'string', 'max:250'],
                'agreed_hours' => $timetable ? ['exclude'] : ['required', 'numeric', 'min:0.01', 'max:10000'],
                'practical_end' => ['required', 'date_format:Y-m-d'],
                'programme_end' => ['required', 'date_format:Y-m-d', 'after:practical_end'],
                'pause_start' => ['nullable', 'required_with:pause_end', 'date_format:Y-m-d'],
                'pause_end' => ['nullable', 'required_with:pause_start', 'date_format:Y-m-d'],
                'schedule' => ['nullable', 'string', 'max:1000'],
            ])->validate();
            $dates['binding'] = RplPlanDates69::binding($p['programme'], $p['rpl'] ?? [], $policy);
            $old = $p['plan_dates69'] ?? [];
            $p['plan_dates69'] = $dates;
            $p['authors'] = array_values(array_unique([...($p['authors'] ?? []), $u->id]));
            $d->payload = $p;
            $d->version++;
            self::event($d, $u, 'training_plan_dates_saved', ['previous_dates' => $old, 'new_dates' => $dates]);
            $d->save();

            return $d;
        });
    }

    public static function nextStep(Draft $draft): int
    {
        if ($draft->state !== 'Draft') {
            return 7;
        }

        $issue = self::review($draft)['issues'][0] ?? null;

        if ($issue) {
            return $issue['step'];
        }

        $skills = self::functionalSkills($draft);

        return isset($skills['maths'], $skills['english']) ? 7 : 6;
    }

    public static function functionalSkills(Draft $draft): array
    {
        $payload = $draft->payload;
        if (isset($payload['functional_skills82']) && empty($payload['fs_applied82'])) {
            return $payload['functional_skills82'];
        }

        return FunctionalSkillsSetup82::current($draft->apprentice_id) ?: ($payload['functional_skills82'] ?? []);
    }

    public static function review(Draft $d): array
    {
        $p = $d->payload;
        $issues = [];
        $add = function (int $step, string $text, ?string $field = null) use (&$issues) {
            $issues[] = ['step' => $step, 'message' => $text, 'field' => $field];
        };
        $details = $p['details'] ?? [];
        foreach (['reference', 'first_name', 'last_name'] as $k) {
            if (! filled($details[$k] ?? null)) {
                $add(1, 'Enter '.str_replace('_', ' ', $k).'.', $k);
            }
        }
        $matches = self::matches($details, $d->apprentice_id);
        foreach ($matches as $m) {
            if ($m['exact_reference'] || $m['exact_email']) {
                $add(1, 'Existing apprentice '.$m['reference'].' matches. Open that record instead of creating another.');
            } elseif (! filled($details['duplicate_reason'] ?? null)) {
                $add(1, 'Review the possible name match '.$m['reference'].' and explain why this is a different person.');
            }
        }
        $markers = $p['markers'] ?? [];
        if (! in_array($markers['state'] ?? null, ['selected', 'none', 'pending'], true)) {
            $add(2, 'Choose markers, none or pending.', 'marker-state');
        }if (($markers['state'] ?? null) === 'selected' && ! count($markers['codes'] ?? [])) {
            $add(2, 'Select at least one marker, or choose none/pending.', 'marker-state');
        }if (in_array('IM40', $markers['codes'] ?? [], true) && ! filled($markers['other'] ?? null)) {
            $add(2, 'Briefly specify the Other marker.', 'other');
        }
        $t = self::template($p);
        $calc = null;
        if (! $t) {
            $add(3, 'Choose an available programme or check which template is missing.', 'template');
        } elseif (! TaskCatalogue::keyFor(new Apprentice(['programme_id' => $t->programme_id]))) {
            $add(3, 'This programme has no loaded task catalogue. Check the programme sources.', 'programme-availability');
        } elseif ($t->source_hash !== ($p['programme']['template_hash'] ?? null)) {
            $add(3, 'The template changed. Reselect the apprenticeship and confirm RPL again.', 'template');
        }
        if (! empty($p['programme']['tutor_group_id']) && $t) {
            $group = TutorGroup::find($p['programme']['tutor_group_id']);
            if (! $group?->active || ($group->programme_id && (int) $group->programme_id !== $t->programme_id)) {
                $add(3, 'Choose an active tutor group for the selected programme.', 'group');
            }
        }
        if (! filled($p['programme']['start_date'] ?? null)) {
            $add(3, 'Enter the first day in learning.', 'start');
        }
        $record = $d->apprentice_id ? Apprentice::find($d->apprentice_id) : null;
        foreach (['tutor_id'=>'main tutor', 'mentor_id'=>'GTA mentor', 'careers_adviser_id'=>'careers adviser',
            'maths_tutor_id'=>'maths tutor', 'english_tutor_id'=>'English tutor'] as $field => $label) {
            $id = $p['programme'][$field] ?? null;
            if (! $id || (int) $id === $record?->$field) {
                continue;
            }
            $staff = User::whereKey($id);
            if ($field === 'tutor_id') {
                $staff->where('active', true)->where('role', 'tutor');
            } else {
                StaffDuties::query($staff, str_replace('_id', '', $field));
            }
            if (! $staff->exists()) {
                $add(3, 'Choose a current '.$label.' or leave the role unassigned.', $field === 'tutor_id' ? 'tutor' : $field);
            }
        }
        $r = $p['rpl'] ?? [];
        if (! in_array($r['mode'] ?? null, ['none', 'applies'], true)) {
            $add(4, 'Confirm no relevant prior learning or enter the approved RPL outcomes.', 'rpl-mode');
        }
        if ($t) {
            try {
                $calc = Rpl68::calculate($t->template, $r);
            } catch (ValidationException $ex) {
                $add(4, $ex->getMessage());
            }
        }
        if (($r['mode'] ?? null) === 'applies' && ! filled($r['assessment_ref'] ?? null)) {
            $add(4, 'Enter the completed assessment reference once.', 'assessment');
        }
        if (! ($r['remaining_confirmed'] ?? false) || ($t && ($r['confirmation_hash'] ?? null) !== Rpl68::hash([$t->source_hash, $r['mode'] ?? null, $r['assessment_ref'] ?? null, $r['adjustments'] ?? []]))) {
            if (in_array($r['mode'] ?? null, ['none', 'applies'], true)) {
                $add(4, 'Review the saved prior learning and select Save and continue.', 'rpl-mode');
            }
        }
        if ($calc && $calc['unresolved']) {
            $affected = [];
            foreach ($calc['unresolved'] as $number => $reasons) {
                $affected[] = 'block '.$number.' ('.implode(', ', $reasons).')';
            }
            $add(4, 'Ask the programme team to check the OTJ allocation for '.implode(', ', $affected).'.', 'rpl-allocation');
        }
        if ($calc && $calc['revised_hours'] <= 0) {
            $add(4, 'No training remains. Refer this case for programme review.', 'rpl-hours');
        }
        $e = $p['employer'] ?? [];
        $employer = null;
        if (! empty($e['id'])) {
            $employer = Employer::find($e['id']);
            if (! $employer?->active || $employer->record_version !== ($e['record_version'] ?? null)) {
                $add(5, 'Employer changed or is inactive. Reselect and verify its agreement.', 'employer-search');
            }
        } else {
            if (! filled($e['reference'] ?? null) || ! filled($e['name'] ?? null)) {
                $add(5, 'Select an existing employer or enter the new employer reference and name.', 'employer-search');
            } else {
                foreach (Employer::where(fn ($q) => $q->whereRaw('UPPER(reference)=?', [strtoupper($e['reference'])])->orWhereRaw('LOWER(name)=?', [mb_strtolower(trim($e['name']))]))->get() as $candidate) {
                    if (strtoupper($candidate->reference) === strtoupper($e['reference'])) {
                        $add(5, 'Employer reference already exists; select its existing record.', 'employer-search');
                    } elseif (! filled($e['duplicate_reason'] ?? null)) {
                        $add(5, 'Possible employer name match: '.$candidate->name.'. Select it or explain the separate organisation.', 'employer-duplicate');
                    }
                }
            }
        }
        if (($r['mode'] ?? null) === 'applies' && (! filled($e['agreement_ref'] ?? null) || empty($e['agreement_verified']) || ($e['agreement_binding'] ?? null) !== Rpl68::hash([self::employerKey($e), $e['record_version'] ?? null, $r, $e['agreement_ref'] ?? null]))) {
            $add(5, 'Verify the employer agreement reference against this linked employer and RPL revision.', 'agreement');
        }
        $dates = $t ? RplPlanDates69::step6Dates($t->template, $p['programme'] ?? [], $r, $calc, $p['plan_dates69'] ?? []) : null;

        return ['issues' => $issues, 'calculation' => $calc, 'template' => $t, 'employer' => $employer, 'matches' => $matches, 'dates69' => $dates];
    }

    public static function submit(User $u, int $auth, Draft $draft, int $version): Draft
    {
        return DB::transaction(function () use ($u, $auth, $draft, $version) {
            self::actor($u, $auth);
            $d = self::lock($draft, $version);
            abort_unless($d->state === 'Draft', 409);
            if (self::review($d)['issues']) {
                Rpl68::fail('Complete the linked missing items before requesting plan approval.');
            }$d->state = 'Ready';
            $d->version++;
            self::event($d, $u, 'submitted_for_independent_review');
            $d->save();

            return $d;
        });
    }

    public static function reopen(User $u, int $auth, Draft $draft, int $version, string $reason): Draft
    {
        return DB::transaction(function () use ($u, $auth, $draft, $version, $reason) {
            self::actor($u, $auth);
            if (! filled(trim($reason))) {
                Rpl68::fail('Record the reason for this correction.');
            }$d = self::lock($draft, $version);
            abort_unless(in_array($d->state, ['Ready', 'Complete'], true), 409);
            $p = $d->payload;
            $p['authors'] = [$u->id];
            $p['correction_reason'] = trim($reason);
            $d->payload = $p;
            $d->state = 'Draft';
            $d->version++;
            self::event($d, $u, 'reopened', ['reason' => trim($reason)]);
            $d->save();

            return $d;
        });
    }

    public static function confirm(User $u, int $auth, Draft $draft, int $version, ?string $practicalEnd = null, ?string $programmeEnd = null, bool $datesOnly = false): Draft
    {
        return DB::transaction(function () use ($u, $auth, $draft, $version, $practicalEnd, $programmeEnd, $datesOnly) {
            $u = self::actor($u, $auth);
            DB::table('setup68_locks')->where('key', 'commit')->lockForUpdate()->first();
            $d = Draft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            if ($d->state === 'Complete' && ! $datesOnly) {
                return $d;
            }
            $d = self::lock($d, $version);
            abort_unless(in_array($d->state, ['Draft', 'Ready'], true), 409);
            $p = $d->payload;
            $view = self::review($d);
            if ($datesOnly) {
                abort_unless($d->state === 'Draft', 409);
                if (! $view['dates69'] || $view['calculation']['unresolved']
                    || collect($view['issues'])->contains(fn ($issue) => $issue['step'] === 3)) {
                    Rpl68::fail('Check the programme and OTJ allocation before saving the dates.');
                }
            } elseif ($view['issues']) {
                Rpl68::fail($d->state === 'Ready' ? 'Reopen the setup and resolve the items listed in Step 7.' : 'Resolve the items listed in Step 7 before confirming the setup.');
            }$t = $view['template'];
            $calc = $view['calculation'];
            $e = $p['employer'] ?? [];
            $employer = $view['employer'];
            $dates69 = $view['dates69'] ?? [];
            $chosenPractical = filled($practicalEnd) ? $practicalEnd : ($dates69['selected']['practical_end'] ?? null);
            $chosenProgramme = filled($programmeEnd) ? $programmeEnd : ($dates69['selected']['programme_end'] ?? null);
            $agreedDates = null;
            if ($chosenPractical && $chosenProgramme) {
                try {
                    $agreedDates = RplPlanDates69::buildStep6Plan($t->template, $p['programme'] ?? [], $p['rpl'] ?? [], $calc, $chosenPractical, $chosenProgramme, $p['plan_dates69']['schedule'] ?? null);
                } catch (\InvalidArgumentException $ex) {
                    throw ValidationException::withMessages(['practical_end' => $ex->getMessage()]);
                }
                $p['plan_dates69'] = [...($p['plan_dates69'] ?? []), 'practical_end' => $agreedDates['practical_end'], 'programme_end' => $agreedDates['programme_end'], 'source' => $p['plan_dates69']['source'] ?? $agreedDates['source'], 'binding' => $agreedDates['binding']];
            }
            if ($datesOnly) {
                $d->payload = $p;
                $d->version++;
                self::event($d, $u, 'training_plan_dates_saved', ['previous_dates'=>$view['dates69']['selected'], 'new_dates'=>$p['plan_dates69']]);
                $d->save();

                return $d;
            }
            if (! $employer) {
                $employer = EmployerWorkflow68::create($u, $auth, $d->id, $e);
            }
            $fields = collect($p['details'])->only(['reference', 'first_name', 'last_name', 'email'])->all();
            $fields = [...$fields, ...collect($p['programme'])->only(['tutor_id', 'tutor_group_id', 'mentor_id', 'careers_adviser_id', 'maths_tutor_id', 'english_tutor_id'])->all(), 'programme_id' => $t->programme_id, 'employer_id' => $employer->id, 'start_date' => $p['programme']['start_date']];
            $end = CarbonImmutable::parse($fields['start_date'])->addDays(collect($t->template['blocks'])->max('end_day'))->toDateString();
            $fields['planned_end_date'] = $agreedDates['programme_end'] ?? $end;
            if (!empty($fields['tutor_group_id'])) TeachingAllocationChecks::lock();
            $a = $d->apprentice_id ? Apprentice::whereKey($d->apprentice_id)->lockForUpdate()->firstOrFail() : new Apprentice;
            if ($a->exists && $a->version !== ($p['apprentice_version'] ?? null)) {
                Rpl68::fail('The apprentice changed since this setup was opened; reconcile the record first.');
            }
            if ($a->exists && ($a->programme_id !== $t->programme_id) && LearningTask::where('apprentice_id', $a->id)->exists()) {
                Rpl68::fail('Use the controlled programme-transfer process for existing allocated learning.');
            }
            // Reuse the full current apprentice request rules for linked roles/groups and all original field constraints.
            $req = new SaveApprenticeRequest;
            $req->merge($fields);
            $rules = $req->rules();
            unset($rules['version']);
            $rules['reference'] = ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('apprentices', 'reference')->ignore($a->id)];
            $fields = Validator::make($fields, $rules)->validate();
            $before = $a->exists ? $a->only(AssignmentHistory::KEYS) : null;
            $a->fill($fields);
            $recheckAllocation = $a->isDirty(['tutor_group_id', 'programme_id', 'start_date', 'employer_id']);
            $a->inclusion_status68 = $p['markers']['state'];
            if ($a->exists) {
                $a->version++;
            }$a->save();
            if ($recheckAllocation) TeachingAllocationChecks::checkAssignment($a);
            AssignmentHistory::record($a, $u->id, $before);
            abort_unless(SupportAccess::lead($u, $a), 403);
            $old = MarkerHistory::codes($a->id);
            $markers = $p['markers'];
            if ($markers['state'] !== 'pending') {
                $selected = $markers['state'] === 'none' ? [] : $markers['codes'];
                DB::table('apprentice_markers')->where('apprentice_id', $a->id)->whereNotIn('code', $selected)->delete();
                foreach (array_diff($selected, $old) as $code) {
                    DB::table('apprentice_markers')->insert(['apprentice_id' => $a->id, 'code' => $code, 'recorded_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
                }MarkerHistory::record($a->id, $u->id, 'guided_setup68', $old, 'Recorded during apprentice setup');
            }
            if (isset($p['functional_skills82']) && ! ($p['fs_applied82'] ?? false)) {
                FunctionalSkillsSetup82::persist($a, $u, $p['functional_skills82'], (int) ($p['fs_outcome_version82'] ?? 0));
                $p['fs_applied82'] = true;
            } elseif (! FunctionalSkillsSetup82::current($a->id)) {
                FunctionalSkillsSetup82::persist($a, $u, FunctionalSkillsSetup82::defaults($u), ProgrammeOutcome::where('apprentice_id', $a->id)->value('version') ?? 0);
            }
            $w = TrainingPlanWorkspace::firstOrNew(['apprentice_id' => $a->id]);
            $previousRpl = $w->setup_rpl;
            $rpl = [...$p['rpl'], 'remaining_confirmed' => true, 'template_id' => $t->id, 'template_hash' => $t->source_hash, 'calculation' => $calc, 'assessment_ref' => $p['rpl']['assessment_ref'] ?? null, 'employer_id' => $employer->id, 'employer_agreement_ref' => $e['agreement_ref'] ?? null, 'approved_by' => $u->id, 'approved_at' => now()->toIso8601String()];
            $w->setup_rpl = $rpl;
            $w->source_hash = $t->source_hash;
            $w->proposal = $w->proposal ?? [];
            $w->rpl = $w->rpl ?? [];
            $w->history = [...($w->history ?? []), ['event' => 'setup68_rpl_confirmed', 'by' => $u->id, 'at' => now()->toIso8601String(), 'draft' => $d->id, 'reason' => $p['correction_reason'] ?? null]];
            if ($w->exists) {
                $w->version++;
            }$w->save();
            $blocks = [];
            foreach ($calc['blocks'] as $b) {
                $blocks[] = ['number' => $b['number'], 'title' => $b['title'], 'hours' => (string) $b['hours'], 'start' => CarbonImmutable::parse($a->start_date)->addDays($b['start_day'])->toDateString(), 'end' => CarbonImmutable::parse($a->start_date)->addDays($b['end_day'])->toDateString()];
            }
            if ($agreedDates) {
                $blocks = $agreedDates['blocks'];
            }
            $plan = IndividualDeliveryPlan::firstOrNew(['apprentice_id' => $a->id]);
            $previousPlan = $plan->approved;
            $approved = ['identity' => DeliveryPlan::identity($a), 'catalogue_hash' => DeliveryPlan::catalogueHash(TaskCatalogue::keyFor($a)), 'blocks' => $blocks, 'details' => ['target_hours' => $calc['revised_hours'], 'basis' => $rpl['mode'] === 'none' ? 'Full training retained' : 'Controlled individual plan', 'source_ref' => $t->template['source_ref'], 'ksb_ref' => $t->template['ksb_ref'], 'qualifications_ref' => $t->template['qualifications_ref'], 'release_ref' => $t->template['release_ref'], ...($agreedDates ? ['practical_end_date' => $agreedDates['practical_end'], 'apprenticeship_end_date' => $agreedDates['programme_end'], 'agreed_dates_source' => $agreedDates['source']] : [])], 'rpl_binding' => Rpl68::hash($rpl), 'authors' => $p['authors'], 'approved_by' => $u->id, 'approved_at' => now()->toIso8601String(), 'approval_scope' => 'Authorised provider setup confirmation'];
            if (isset($previousPlan['curriculum']) && ($previousPlan['identity']['catalogue'] ?? null) === $approved['identity']['catalogue'] && ($previousRpl['template_hash'] ?? null) === $rpl['template_hash'] && ($previousRpl['mode'] ?? null) === $rpl['mode'] && ($previousRpl['adjustments'] ?? []) === $rpl['adjustments']) {
                $approved['curriculum'] = $previousPlan['curriculum'];
            }$approved['hash'] = Rpl68::hash($approved);
            $plan->draft = $approved;
            $plan->approved = $approved;
            $plan->active = true;
            $plan->history = [...($plan->history ?? []), ['event' => 'setup68_plan_confirmed', 'by' => $u->id, 'at' => now()->toIso8601String(), 'approved_hash' => $approved['hash'], ...($agreedDates && $previousPlan ? ['previous_dates' => $previousPlan['details'], 'previous_schedule' => $previousPlan['blocks']] : [])]];
            if ($plan->exists) {
                $plan->version++;
            }$plan->save();
            $p['employer'] = ['id' => $employer->id, 'record_version' => $employer->record_version, 'use_contact' => $e['use_contact'] ?? false, 'agreement_ref' => $e['agreement_ref'] ?? null, 'agreement_verified' => $e['agreement_verified'] ?? false];
            if ($p['employer']['agreement_verified']) {
                $p['employer']['agreement_binding'] = Rpl68::hash([self::employerKey($p['employer']), $employer->record_version, $p['rpl'], $e['agreement_ref'] ?? null]);
            }
            $p['apprentice_version'] = $a->version;
            $p['approved_hours'] = $calc['revised_hours'];
            $d->payload = $p;
            $d->apprentice_id = $a->id;
            $d->state = 'Complete';
            $d->version++;
            self::event($d, $u, 'setup_confirmed', ['apprentice_id' => $a->id, 'plan_hash' => $approved['hash']]);
            $d->save();
            DB::table('audit_events')->insert(['user_id' => $u->id, 'apprentice_id' => $a->id, 'event' => 'guided_setup68_confirmed', 'created_at' => now()]);

            return $d;
        });
    }

    public static function resumeExisting(User $actor, int $auth, Apprentice $record): Draft
    {
        return DB::transaction(function () use ($actor, $auth, $record): Draft {
            $u = self::actor($actor, $auth);
            $a = Apprentice::whereKey($record->id)->lockForUpdate()->firstOrFail();
            abort_if(ProgrammeOutcome::archived()->where('apprentice_id', $a->id)->exists(), 409, 'Reopen the archived programme through its existing workflow first.');
            abort_if(DeliveryPlan::approved($a) !== null, 409, 'This apprentice already has a current approved plan. Use the individual delivery-plan workflow.');
            $existing = Draft::where('apprentice_id', $a->id)->whereIn('state', ['Draft', 'Ready'])->orderByDesc('updated_at')->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            $d = self::start($u, $auth);
            $d->apprentice_id = $a->id;
            $programme = $a->only(['tutor_id', 'tutor_group_id', 'mentor_id', 'careers_adviser_id', 'maths_tutor_id', 'english_tutor_id']);
            $programme['start_date'] = $a->start_date?->toDateString();
            $templates = Template::where('programme_id', $a->programme_id)->where('active', true)->get()->filter(fn ($t) => self::template(['programme' => ['template_id' => $t->id]]) !== null);
            if ($templates->count() === 1) {
                $t = $templates->first();
                $programme['template_id'] = $t->id;
                $programme['template_hash'] = $t->source_hash;
            }
            $employer = $a->employer_id ? Employer::find($a->employer_id) : null;
            $d->payload = [...$d->payload, 'details' => $a->only(['reference', 'first_name', 'last_name', 'email']),
                'programme' => $programme, 'markers' => ['state' => 'pending'], 'employer' => $employer ? ['id' => (int) $employer->id, 'record_version' => $employer->record_version] : [],
                'apprentice_version' => $a->version, 'completion85' => true];
            self::event($d,$u,'existing_record_setup_opened',['apprentice_id' => $a->id, 'plan_approved' => false]);
            $d->save();

            return $d;
        });
    }
}
