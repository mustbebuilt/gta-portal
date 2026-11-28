<?php

namespace App\Http\Controllers;

use App\Models\TrainingSession;
use App\Models\TutorGroup;
use App\Support\LearningAccess;
use App\Support\WeeklyRegisters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WeeklyRegisterController extends Controller
{
    public function configure(Request $r, TutorGroup $group): RedirectResponse
    {
        abort_unless(LearningAccess::manager($r->user()), 403);
        $data = $r->validate([
            'record_version' => ['required', 'integer'],
            'first_day' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i', 'after:start'],
            'planned_learning' => ['required', 'string', 'max:10000'],
            'breaks_checked' => ['accepted'],
            'break_start' => ['nullable', 'required_with:break_end', 'date_format:H:i', 'after_or_equal:start'],
            'break_end' => ['nullable', 'required_with:break_start', 'date_format:H:i', 'after:break_start', 'before_or_equal:end'],
        ]);
        DB::transaction(function () use ($r, $group, $data): void {
            \App\Support\TeachingAllocationChecks::lock();
            $group = TutorGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $this->version($group, $data['record_version']);
            abort_unless($group->active, 422);
            if (TrainingSession::where('tutor_group_id', $group->id)->whereNotNull('regular_week')
                ->where('session_date', '>=', $data['first_day'])->where('state', '!=', 'Cancelled')->exists()) {
                throw ValidationException::withMessages(['first_day' => 'Move or cancel previously opened future registers before changing their schedule.']);
            }
            $closed = $group->weekly_register['closed_on'] ?? null;
            if ($closed && $data['first_day'] <= $closed) {
                throw ValidationException::withMessages(['first_day' => 'A new weekly schedule must start after the last active day.']);
            }
            $previous = array_filter(
                $group->weekly_register['revisions'] ?? [],
                fn (array $revision): bool => $revision['first_day'] < $data['first_day'] && (!$closed || $revision['first_day'] <= $closed)
            );
            if ($closed) {
                $previous = array_map(static fn (array $revision): array => [
                    ...$revision, 'last_day' => min($closed, $revision['last_day'] ?? $closed),
                ], $previous);
            }
            $revision = [
                'first_day' => $data['first_day'],
                'start' => $data['start'],
                'end' => $data['end'],
                'planned_learning' => $data['planned_learning'],
                'breaks' => isset($data['break_start']) ? [['start'=>$data['break_start'],'end'=>$data['break_end']]] : [],
                'configured_by' => $r->user()->id,
                'configured_at' => now()->toIso8601String(),
            ];
            $members = \App\Models\Apprentice::where('tutor_group_id', $group->id)->pluck('id')->all();
            \App\Support\TeachingAllocationChecks::checkDates($group, $members,
                \App\Support\TeachingAllocationChecks::weeks($data['first_day']), 'first_day');
            $group->weekly_register = ['revisions' => [...$previous, $revision]];
            $group->record_version++;
            $group->save();
        }, 3);

        return to_route('attendance.index', ['group'=>$group->id])->with('status', 'Weekly register schedule saved.');
    }

    public function open(Request $r, TutorGroup $group): RedirectResponse
    {
        $data = $r->validate(['date'=>['required','date_format:Y-m-d'],'week'=>['required','date_format:Y-m-d']]);
        $session = WeeklyRegisters::open($r->user(), (int) $r->session()->get('auth_version'), $group, $data);

        return to_route('attendance.show', $session);
    }

    public function close(Request $r, TutorGroup $group): RedirectResponse
    {
        abort_unless(LearningAccess::manager($r->user()), 403);
        $data = $r->validate(['record_version'=>['required','integer'],'closed_on'=>['required','date_format:Y-m-d','after_or_equal:today'],'reason'=>['required','string','max:2000']]);
        DB::transaction(function () use ($r, $group, $data): void {
            $group = TutorGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            $this->version($group, $data['record_version']);
            abort_unless($group->weekly_register, 422);
            $future = TrainingSession::where('tutor_group_id', $group->id)->whereNotNull('regular_week')
                ->where('state','!=','Cancelled')->where('session_date','>',$data['closed_on'])->lockForUpdate()->get();
            foreach ($future as $session) {
                if ($session->marks()->exists() || filled($session->delivered_learning)) {
                    throw ValidationException::withMessages(['closed_on'=>'An affected register has saved information. Review it before closing the schedule.']);
                }
                $session->state = 'Cancelled';
                $session->cancellation_reason = $data['reason'];
                $session->history = [...($session->history ?? []), ['event'=>'weekly_schedule_closed','at'=>now()->toIso8601String(),'by'=>$r->user()->id,'reason'=>$data['reason']]];
                $session->version++;
                $session->save();
            }
            $group->weekly_register = [...$group->weekly_register, 'closed_on'=>$data['closed_on'], 'closed_by'=>$r->user()->id, 'closure_reason'=>$data['reason']];
            $group->record_version++;
            $group->save();
        });

        return to_route('attendance.index', ['group'=>$group->id])->with('status', 'Weekly schedule closed after the last active day.');
    }

    private function version(TutorGroup $group, int $version): void
    {
        if ($group->record_version !== $version) {
            throw ValidationException::withMessages(['record_version'=>'The group changed. Reopen it before saving.']);
        }
    }
}
