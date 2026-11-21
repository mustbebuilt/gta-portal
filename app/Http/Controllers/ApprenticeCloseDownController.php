<?php

namespace App\Http\Controllers;

use App\Models\Apprentice;
use App\Models\ProgrammeOutcome;
use App\Support\AccountLifecycle;
use App\Support\DestinationMilestones;
use App\Support\LearningAccess;
use App\Support\PrivateEvidence;
use App\Support\RetentionAnalysis;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ApprenticeCloseDownController extends Controller
{
    private const GRADES = ['Pass', 'Merit', 'Distinction'];
    private const MAX_ATTEMPTS = 3;

    public function show(Request $request, Apprentice $apprentice): View
    {
        $this->authorizeStaff($request);
        $outcome = ProgrammeOutcome::where('apprentice_id', $apprentice->id)->first();
        $context = $outcome?->context ?? [];
        $attempts = $context['epa_attempts'] ?? [];
        $latest = end($attempts) ?: null;
        $pending = ($latest['result'] ?? null) === 'Awaiting result';
        $number = $pending ? count($attempts) : count($attempts) + 1;

        return view('apprentices.close-down', [
            'apprentice' => $apprentice,
            'outcome' => $outcome,
            'attempts' => $attempts,
            'employmentChoices' => RetentionAnalysis::EXIT_EMPLOYMENT,
            'exitEmployment' => RetentionAnalysis::employmentAtExit($context),
            'closure' => $context['epa_closure'] ?? null,
            'endedWithoutAchievement' => $this->endedWithoutAchievement($context),
            'number' => $number <= self::MAX_ATTEMPTS ? $number : null,
            'draft' => $pending ? $latest : [],
            'grades' => self::GRADES,
        ]);
    }

    public function archive(Request $request): View
    {
        $this->authorizeStaff($request);
        $records = Apprentice::whereIn('id', ProgrammeOutcome::archived()->select('apprentice_id'))
            ->with('programmeRecord')->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate(15);
        $outcomes = ProgrammeOutcome::whereIn('apprentice_id', $records->getCollection()->modelKeys())
            ->get()->keyBy('apprentice_id');

        return view('apprentices.archive', compact('records', 'outcomes'));
    }

    public function downloadCompletionPack(Request $request, Apprentice $apprentice): StreamedResponse
    {
        $this->authorizeStaff($request);
        $entry = ProgrammeOutcome::where('apprentice_id', $apprentice->id)->first()?->context['epa_closure']['completion_pack'] ?? null;
        abort_unless(is_array($entry) && PrivateEvidence::exists($entry), 404);
        abort_unless(PrivateEvidence::matchesHash($entry), 409);

        return PrivateEvidence::download($entry);
    }

    public function save(Request $request, Apprentice $apprentice): RedirectResponse
    {
        $this->authorizeStaff($request);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'result' => ['required', Rule::in(['Awaiting result', 'Unsuccessful', ...self::GRADES])],
            'assessment_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'received_date' => ['nullable', 'required_unless:result,Awaiting result', 'prohibited_if:result,Awaiting result', 'date_format:Y-m-d', 'after_or_equal:assessment_date', 'before_or_equal:today'],
            'achievement_date' => ['nullable', 'required_if:result,Pass,Merit,Distinction', 'prohibited_unless:result,Pass,Merit,Distinction', 'date_format:Y-m-d', 'after_or_equal:assessment_date', 'before_or_equal:received_date'],
            'end_date' => ['nullable', 'required_if:result,Pass,Merit,Distinction', 'prohibited_unless:result,Pass,Merit,Distinction', 'date_format:Y-m-d', 'before_or_equal:achievement_date'],
            'exit_employment' => ['required_if:action,archive', 'prohibited_unless:action,archive', Rule::in(RetentionAnalysis::EXIT_EMPLOYMENT)],
            'completion_pack' => ['required_if:action,archive', 'prohibited_unless:action,archive', 'file', 'mimes:pdf', 'extensions:pdf', 'max:15360'],
            'action' => ['required', Rule::in(['record', 'archive'])],
        ], [
            'exit_employment.required_if' => 'Select employment status at exit. Choose Unknown if it has not been established.',
            'completion_pack.required_if' => 'Upload the scanned completion pack as a PDF before closing the programme.',
            'completion_pack.mimes' => 'The completion pack must be a PDF.',
            'completion_pack.extensions' => 'The completion pack filename must end in .pdf.',
        ]);
        $achieved = in_array($data['result'], self::GRADES, true);
        if ($achieved !== ($data['action'] === 'archive')) {
            throw ValidationException::withMessages(['result' => $achieved
                ? 'Use Confirm achievement and archive to close the programme with this result.'
                : 'Select Pass, Merit or Distinction to confirm achievement and archive.']);
        }

        if ($achieved) {
            $header = file_get_contents($data['completion_pack']->getRealPath(), false, null, 0, 8);
            if ($header === false || ! preg_match('/^%PDF-[0-9]\.[0-9]/', $header)) {
                throw ValidationException::withMessages(['completion_pack' => 'The completion pack must be a PDF.']);
            }
        }

        $pack = null;
        try {
            DB::transaction(function () use ($request, $apprentice, $data, $achieved, &$pack): void {
                $profile = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
                $outcome = ProgrammeOutcome::where('apprentice_id', $profile->id)->lockForUpdate()->first();
                $context = $outcome?->context ?? [];
                if ((int) $data['version'] !== ($outcome?->version ?? 0)) {
                    throw ValidationException::withMessages(['version' => 'This record changed. Reload the page and check the attempt history before saving.']);
                }
                if (isset($context['epa_closure']) || $this->endedWithoutAchievement($context)) {
                    throw ValidationException::withMessages(['result' => 'This programme is already closed.']);
                }
                $attempts = $context['epa_attempts'] ?? [];
                $latest = end($attempts) ?: null;
                $pending = ($latest['result'] ?? null) === 'Awaiting result';
                $number = $pending ? count($attempts) : count($attempts) + 1;
                if ($number > self::MAX_ATTEMPTS) {
                    throw ValidationException::withMessages(['result' => 'All three EPA attempts have been recorded.']);
                }
                $previous = $attempts[$number - 2] ?? null;
                $earliest = $previous['assessment_date'] ?? $profile->start_date?->toDateString();
                if ($earliest && $data['assessment_date'] < $earliest) {
                    throw ValidationException::withMessages(['assessment_date' => 'The assessment date must be on or after the programme start and any earlier attempt.']);
                }
                if ($achieved && (! $profile->start_date || $data['end_date'] < $profile->start_date->toDateString())) {
                    throw ValidationException::withMessages(['end_date' => 'Enter an actual end date on or after the recorded programme start.']);
                }
                if ($achieved && filled($context['destinationDate'] ?? null) && $context['destinationDate'] < $data['end_date']) {
                    throw ValidationException::withMessages(['end_date' => 'The recorded destination date comes before this programme end date. Check the dates in Outcome and reporting context.']);
                }
                $before = $context;
                $actor = $request->user()->id;
                $now = now()->toIso8601String();
                $attempts[$number - 1] = [
                    'number' => $number,
                    'assessment_date' => $data['assessment_date'],
                    'result' => $data['result'],
                    'received_date' => $data['received_date'] ?? null,
                    'recorded_by' => $actor,
                    'recorded_at' => $now,
                ];
                $context['epa_attempts'] = $attempts;
                if ($achieved) {
                    try {
                        $pack = PrivateEvidence::store($data['completion_pack'], 'completion-packs', $actor);
                    } catch (\RuntimeException $error) {
                        report($error);
                        throw ValidationException::withMessages(['completion_pack' => 'The completion pack could not be saved. Please try uploading it again.']);
                    }
                    $context = array_replace($context, [
                        'status' => 'Completed',
                        'achievement' => 'Achieved',
                        'endDate' => $data['end_date'],
                        'achievementDate' => $data['achievement_date'],
                        'exitEmployment' => $data['exit_employment'],
                        'sourceRef' => 'Completion pack · '.$pack['name'],
                        'reason' => 'Not established',
                        'reasonBasis' => 'Not confirmed',
                        'epa_closure' => [
                            'grade' => $data['result'],
                            'attempt' => $number,
                            'archived_at' => $now,
                            'archived_by' => $actor,
                            'completion_pack' => $pack,
                        ],
                    ]);
                }

                $outcome ??= new ProgrammeOutcome(['apprentice_id' => $profile->id]);
                $outcome->context = $context;
                $outcome->history = [...($outcome->history ?? []), ['before' => $before, 'by' => $actor, 'at' => $now]];
                if ($outcome->exists) {
                    $outcome->version++;
                }
                $outcome->save();
                DB::table('learning_events')->insert([
                    'user_id' => $actor, 'apprentice_id' => $profile->id, 'kind' => 'outcome_context',
                    'record_id' => $outcome->id, 'event' => $achieved ? 'achievement_archived' : 'epa_attempt_recorded',
                    'created_at' => now(),
                ]);
                if ($achieved) {
                    DestinationMilestones::sync($profile);
                }
            });
        } catch (\Throwable $error) {
            if ($pack !== null) {
                rescue(fn () => PrivateEvidence::delete($pack));
            }
            throw $error;
        }

        return to_route('apprentices.close-down', $apprentice)->with('status', $achieved
            ? 'Achievement confirmed. The programme has been completed and archived.'
            : 'EPA attempt saved.');
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless(LearningAccess::manager($request->user()) && !AccountLifecycle::blocked($request->user()), 403);
    }

    private function endedWithoutAchievement(array $context): bool
    {
        return in_array($context['status'] ?? null, ['Withdrawn', 'Transferred'], true);
    }
}
