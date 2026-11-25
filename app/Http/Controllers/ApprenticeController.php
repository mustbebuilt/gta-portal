<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveApprenticeRequest;
use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\LearningTask;
use App\Models\MonthlyScorecard;
use App\Models\Programme;
use App\Models\TutorGroup;
use App\Models\User;
use App\Support\ApprenticeList;
use App\Support\AssignmentHistory;
use App\Support\DirectoryCatalog;
use App\Support\EntryContext;
use App\Support\LearningAccess;
use App\Support\MonthlyDevelopment;
use App\Support\OtjPosition;
use App\Support\TaskCatalogue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ApprenticeController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Apprentice::class);
        $filters = ApprenticeList::filters($request->query());
        $search = $filters['search'] ?? '';
        $scope = Apprentice::visibleTo($request->user())
            ->whereNotIn('id', \App\Models\ProgrammeOutcome::archived()->select('apprentice_id'));

        $options = [
            'group' => TutorGroup::whereIn('id', (clone $scope)->select('tutor_group_id'))->orderBy('name')->pluck('name', 'id'),
            'programme' => Programme::whereIn('id', (clone $scope)->select('programme_id'))->orderBy('name')->pluck('name', 'id'),
        ];
        if (LearningAccess::manager($request->user())) {
            $options['tutor'] = User::whereIn('id', (clone $scope)->select('tutor_id'))->orderBy('name')->pluck('name', 'id');
        } else {
            unset($filters['tutor']);
        }

        $query = $scope->with(['tutor', 'programmeRecord', 'groupRecord']);
        foreach (['group' => 'tutor_group_id', 'programme' => 'programme_id', 'tutor' => 'tutor_id'] as $key => $field) {
            if (isset($filters[$key])) {
                $query->where($field, $filters[$key]);
            }
        }
        foreach (preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $query->where(fn ($q) => $q->where('reference', 'like', '%'.$term.'%')
                ->orWhere('first_name', 'like', '%'.$term.'%')
                ->orWhere('last_name', 'like', '%'.$term.'%'));
        }

        $query->orderBy('last_name')->orderBy('first_name')->orderBy('id');
        $records = (clone $query)->paginate(15);
        if ($records->currentPage() > $records->lastPage()) {
            $records = $query->paginate(15, ['*'], 'page', $records->lastPage());
        }
        unset($filters['page']);
        $records->appends($filters);
        $returnFilters = $filters + ($records->currentPage() > 1 ? ['page' => $records->currentPage()] : []);

        return view('apprentices.index', compact('records', 'search', 'filters', 'returnFilters', 'options'));
    }

    public function create(): View
    {
        Gate::authorize('create', Apprentice::class);

        return view('apprentices.form', ['apprentice' => new Apprentice, 'recruitmentChoices' => array_intersect_key(EntryContext::choices(), EntryContext::RECRUITMENT_FIELDS), 'tutors' => $this->tutors(), 'linkedOptions' => $this->linkedOptions()]);
    }

    public function store(SaveApprenticeRequest $request): RedirectResponse
    {
        $record = DB::transaction(function () use ($request) {
            $recruitmentFields = array_keys(EntryContext::RECRUITMENT_FIELDS);
            $record = Apprentice::create($request->safe()->except(['version', ...$recruitmentFields, ...\App\Support\ApprenticeAlerts::INPUTS]));
            $recruitment = array_filter($request->safe()->only($recruitmentFields), fn ($value) => $value !== null);
            if ($recruitment !== []) {
                EntryContext::save($request->user(), $record, ['stage' => 'Application', ...$recruitment], 0);
            }
            if($request->boolean('change_alert_preferences')) { \App\Support\ApprenticeAlerts::apply($record,$request->user(),$request->session()->get('auth_version'),$request->validated());$record->save(); }
            AssignmentHistory::record($record, $request->user()->id, null);
            $this->audit($request, $record, 'created');

            return $record;
        });

        \App\Support\DestinationMilestones::sync($record);
        return to_route('apprentices.show', $record)->with('status', 'Apprentice record saved.');
    }

    public function show(Request $request, Apprentice $apprentice): View
    {
        Gate::authorize('view', $apprentice);
        $listUrl = ApprenticeList::returnUrl($request);
        $monthly = LearningAccess::training($request->user(), $apprentice)
            ? MonthlyScorecard::where('apprentice_id', $apprentice->id)->whereNotNull('development')->orderByDesc('month')->first()
            : null;

        $trainingAccess = LearningAccess::training($request->user(), $apprentice);
        $receipts = $trainingAccess ? \App\Support\RegisterTraining::receipts($apprentice->id) : [];
        $coverage = $trainingAccess
            ? \App\Support\RegisterTraining::coverage($apprentice, $receipts, \App\Support\DeliveryPlan::approved($apprentice))
            : [];
        return view('apprentices.show', [
            'overview83' => \App\Support\Workday83::apprentice($request->user(),$apprentice),
            'listUrl' => $listUrl,
            'otjHeadline' => $trainingAccess ? OtjPosition::headline(OtjPosition::forApprentice($apprentice)) : null,
            'apprentice' => $apprentice->load(['tutor', 'programmeRecord', 'employerRecord', 'groupRecord', 'mentor', 'careersAdviser', 'mathsTutor', 'englishTutor']),
            'developmentMonth' => $monthly?->month,
            'trainingReceipts' => $receipts,
            'trainingCoverage' => $coverage,
            'latestDevelopment' => $monthly ? MonthlyDevelopment::read($monthly) : null,
        ]);
    }

    public function edit(Request $request, Apprentice $apprentice): View
    {
        Gate::authorize('update', $apprentice);

        if ($request->filled('link_employer')) {
            abort_if(\App\Models\ProgrammeOutcome::archived()->where('apprentice_id', $apprentice->id)->exists(), 404);
            $target = $request->validate(['link_employer'=>['required','integer','min:1']]);
            $employer = Employer::where('active', true)->findOrFail($target['link_employer']);
            return view('setup68.link-employer', compact('apprentice', 'employer'));
        }

        return view('apprentices.form', ['apprentice' => $apprentice, 'tutors' => $this->tutors($apprentice), 'linkedOptions' => $this->linkedOptions($apprentice)]);
    }

    public function update(SaveApprenticeRequest $request, Apprentice $apprentice): RedirectResponse
    {
        DB::transaction(function () use ($request, $apprentice) {
            $locked = Apprentice::whereKey($apprentice->id)->lockForUpdate()->firstOrFail();
            if ($locked->version !== (int) $request->validated('version')) {
                throw ValidationException::withMessages(['version' => 'This record changed while you were editing. Reopen it before making further changes.']);
            }
            $beforeLinks = $locked->only(AssignmentHistory::KEYS);
            $locked->fill($request->safe()->except(['version',...\App\Support\ApprenticeAlerts::INPUTS]));
            if ($locked->isDirty(['employer_id', 'employer'])) {
                throw ValidationException::withMessages(['employer_id' => 'Use Change employer to save the link and its effective date together.']);
            }
            if ($locked->isDirty('programme_id') && LearningTask::where('apprentice_id', $locked->id)->exists() && ! TaskCatalogue::historyMatches($locked)) {
                throw ValidationException::withMessages(['programme_id' => 'Assigned programme task history cannot be transferred to another programme automatically. Keep the current mapping until a controlled programme transfer is available.']);
            }
            if($request->boolean('change_alert_preferences')) { \App\Support\ApprenticeAlerts::apply($locked,$request->user(),$request->session()->get('auth_version'),$request->validated()); }
            $locked->version++;
            $locked->save();
            AssignmentHistory::record($locked, $request->user()->id, $beforeLinks);
            $this->audit($request, $locked, 'updated');
        });

        \App\Support\DestinationMilestones::sync($apprentice);
        return to_route('apprentices.show', $apprentice)->with('status', 'Changes saved.');
    }

    private function tutors(?Apprentice $apprentice = null): Collection
    {
        return User::where(fn ($q) => $q->where('role', 'tutor')->where('active', true))
            ->when($apprentice?->tutor_id, fn ($q) => $q->orWhere('id', $apprentice->tutor_id))
            ->orderBy('name')->get();
    }

    private function linkedOptions(?Apprentice $apprentice = null): array
    {
        $options = [];
        foreach (['programme_id' => Programme::class, 'employer_id' => Employer::class, 'tutor_group_id' => TutorGroup::class] as $field => $model) {
            $options[$field] = DirectoryCatalog::options($model::query(), $apprentice?->$field);
        }
        foreach (['mentor_id' => 'mentor', 'careers_adviser_id' => 'careers_adviser', 'maths_tutor_id' => 'maths_tutor', 'english_tutor_id' => 'english_tutor'] as $field => $role) {
            $subject = $field === 'maths_tutor_id' ? 'maths' : ($field === 'english_tutor_id' ? 'english' : null);
            $query = isset(\App\Support\StaffDuties::LABELS[$role])
                ? \App\Support\StaffDuties::query(User::query(), $role)
                : User::where('role', $role)->when($subject, fn ($q) => $q->whereIn('subject_scope', [$subject, 'both']));
            $options[$field] = DirectoryCatalog::options($query, $apprentice?->$field);
        }

        return $options;
    }

    private function audit(Request $request, Apprentice $record, string $event): void
    {
        DB::table('audit_events')->insert(['user_id' => $request->user()->id, 'apprentice_id' => $record->id, 'event' => $event, 'created_at' => now()]);
    }
}
