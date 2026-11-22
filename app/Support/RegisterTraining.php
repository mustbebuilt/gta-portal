<?php

namespace App\Support;

use App\Models\RegisterTrainingRecord;
use App\Models\Apprentice;
use App\Models\TrainingSession;
use Illuminate\Validation\ValidationException;

final class RegisterTraining
{
    public const AREAS = ['vocational'=>'Vocational training','functional_skills'=>'English and maths',
        'themes'=>'Equality, diversity, British Values and Prevent'];

    public static function details(array $submitted, array $members, array $options = []): array
    {
        $items = [];
        foreach (array_unique($submitted['subjects'] ?? []) as $id) {
            $option = $options[$id] ?? null;
            if (!$option) {
                throw ValidationException::withMessages(['training.subjects'=>'A selected topic is outside the approved plans for this roster. Reload the register.']);
            }
            $items[] = ['subjectId'=>$id, 'label'=>$option['label'], 'area'=>$option['area'], 'coverage'=>'delivered'];
        }
        foreach (self::AREAS as $area => $label) {
            foreach (array_unique(array_filter(array_map('trim', preg_split('/\R/u', $submitted[$area] ?? '')))) as $title) {
                $items[] = ['subjectId'=>'custom:'.hash('sha256',$area.'|'.$title),
                    'label'=>$title,'area'=>$area,'coverage'=>'delivered'];
            }
        }
        $exceptions = [];
        foreach ($submitted['exceptions'] ?? [] as $id => $areas) {
            if (!in_array((int) $id, $members, true)) {
                throw ValidationException::withMessages(['training'=>'An exception is outside this session roster.']);
            }
            $entered = array_filter($areas);
            if (array_diff_key($entered, array_flip(array_column($items,'area')))) {
                throw ValidationException::withMessages(['training'=>'Record delivered training for the area before saving an exception.']);
            }
            if ($entered) {
                $exceptions[$id] = $entered;
            }
        }

        $topicExceptions = [];
        foreach ($submitted['topic_exceptions'] ?? [] as $id => $topics) {
            if (!in_array((int) $id, $members, true)) {
                throw ValidationException::withMessages(['training.topic_exceptions'=>'An exception is outside this session roster.']);
            }
            $entered = array_filter($topics);
            if (array_diff_key($entered, array_flip(array_column($items, 'subjectId')))) {
                throw ValidationException::withMessages(['training.topic_exceptions'=>'Select the delivered topic before recording its exception.']);
            }
            if ($entered) {
                $topicExceptions[$id] = $entered;
            }
        }
        return $topicExceptions ? compact('items','exceptions','topicExceptions') : compact('items','exceptions');
    }

    public static function save(?RegisterTrainingRecord $record, TrainingSession $session, array $details, int $actor, ?string $reason): void
    {
        if (!$record && !$details['items']) {
            return;
        }
        $record ??= new RegisterTrainingRecord;
        if ($record->exists && $record->details === $details && $record->register_version === $session->version) {
            return;
        }
        $record->forceFill(['training_session_id'=>$session->id,'recorded_by'=>$actor,'register_version'=>$session->version,
            'version'=>($record->version ?? 0)+1,'details'=>$details,
            'history'=>[...($record->history ?? []), ['before'=>$record->getOriginal('details'),'by'=>$actor,'at'=>now()->toIso8601String(),'reason'=>$reason]]]);
        $record->save();
    }

    public static function options(iterable $members, array $saved = []): array
    {
        $topics = [];
        foreach ($members as $member) {
            $plan = DeliveryPlan::approved($member);
            foreach ($plan ? PlanCurriculum::requirements($plan) : [] as $topic) {
                $topics[$topic['subjectId']] = $topic;
            }
        }
        foreach ($saved['items'] ?? [] as $item) {
            if (!str_starts_with($item['subjectId'], 'custom:')) {
                $topics[$item['subjectId']] ??= $item;
            }
        }
        return $topics;
    }

    public static function coverage(Apprentice $apprentice, array $receipts, ?array $plan, ?string $date = null): array
    {
        $requirements = $plan ? PlanCurriculum::requirements($plan) : [];
        $secure = \App\Models\TrainingTopic::where('apprentice_id', $apprentice->id)->where('planned_hours', 0)
            ->get()->map(fn ($topic) => PlanCurriculum::keyForTopic($topic))->all();
        $requirements = array_filter($requirements, fn ($topic) => !in_array($topic['subjectId'], $secure, true));

        return TrainingCoverage::journey($requirements, $receipts, $date ?? today()->toDateString());
    }

    public static function receipts(int $apprenticeId, ?string $through = null): array
    {
        $sessions = TrainingSession::whereHas('marks', fn ($marks) => $marks->where('apprentice_id',$apprenticeId)->whereIn('code',['O','T']))
            ->where('state','!=','Cancelled')->whereDate('session_date','<=',$through ?? today())
            ->with(['group','marks'=>fn ($marks) => $marks->where('apprentice_id',$apprenticeId)])->get();
        $records = RegisterTrainingRecord::whereIn('training_session_id',$sessions->modelKeys())->get()->keyBy('training_session_id');
        $sources = [];
        foreach ($sessions as $session) {
            $record = $records->get($session->id);
            if (!$record || !$session->ended() || !in_array($apprenticeId, RegisterFollowup::roster($session)['ids'], true)) {
                continue;
            }
            $items = $record->details['items'];
            $exceptions = $record->details['exceptions'][$apprenticeId] ?? [];
            $topicExceptions = $record->details['topicExceptions'][$apprenticeId] ?? [];
            $exception = fn ($item) => $topicExceptions[$item['subjectId']] ?? $exceptions[$item['area']] ?? null;
            $marks = $session->marks->map(fn ($mark) => ['id'=>$apprenticeId,'code'=>$mark->code,
                'missed'=>array_column(array_filter($items,fn ($item) => $exception($item) === 'missed'),'subjectId'),
                'partial'=>array_column(array_filter($items,fn ($item) => $exception($item) === 'partial'),'subjectId')])->all();
            $sources[] = ['id'=>$session->id,'date'=>$session->session_date->toDateString(),'group'=>$session->group?->name,
                'tutor'=>$record->recorded_by,'items'=>$items,'marks'=>$marks,'confirmed'=>true,
                'register_version'=>$session->version,'delivery_register_version'=>$record->register_version];
        }

        return TrainingCoverage::receipts((string) $apprenticeId,$sources,$through ?? today()->toDateString());
    }
}
