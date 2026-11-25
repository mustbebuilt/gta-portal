<?php

namespace App\Support;

use App\Models\{Apprentice, ProgrammeOutcome, TrainingTopic, User};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\Rule;

final class FunctionalSkillsSetup82
{
    public static function catalogue(): array
    {
        return json_decode(file_get_contents(resource_path('data/topic-catalogue.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function defaults(User $actor): array
    {
        return array_fill_keys(['maths', 'english'], ['state' => 'pending', 'owner_id' => $actor->id,
            'next_action' => 'Arrange initial assessment and diagnostic with the subject tutor.', 'topics' => []]);
    }

    public static function topicNames(array $entry): array
    {
        $legacy = match ([$entry['subject'], $entry['topic']]) {
            ['Maths: Level 2', 'Data Tables (Two-way Tables)'] => ['Data tables (Two way tables)'],
            ['English: Level 1', 'Understanding'], ['English: Level 2', 'The Exam'] => ['Speaking and Listening'],
            default => [],
        };

        return [$entry['topic'], ...$legacy];
    }

    public static function current(?int $apprenticeId): array
    {
        return $apprenticeId ? (ProgrammeOutcome::where('apprentice_id', $apprenticeId)->first()?->context['functional_skills_setup82'] ?? []) : [];
    }

    public static function validate(array $input, array $subjects = ['maths', 'english']): array
    {
        $rules = [];
        foreach ($subjects as $subject) {
            if (!in_array($subject, ['maths','english'], true)) Rpl68::fail('Choose maths or English.');
            $rules[$subject] = ['required', 'array:state,owner_id,next_action,initial_assessment,diagnostic,topics'];
            $rules["$subject.state"] = ['required', Rule::in(['pending', 'recorded'])];
            $rules["$subject.owner_id"] = ['required', 'integer', Rule::exists('users', 'id')->where(fn ($q) => $q->where('active', true)->whereIn('role', ['admin', 'ceo', 'tutor', 'functional_skills_tutor']))];
            $rules["$subject.next_action"] = ['required', 'string', 'max:500'];
            foreach (['initial_assessment', 'diagnostic'] as $field) {
                $rules["$subject.$field"] = ['nullable', "required_if:$subject.state,recorded", 'string', 'max:500'];
            }
            $rules["$subject.topics"] = ['nullable', 'array', 'max:100'];
            $rules["$subject.topics.*"] = ['nullable', 'numeric', 'min:0', 'max:9999'];
        }
        $values = Validator::make($input, $rules)->validate();
        $catalogue = self::catalogue();
        foreach ($values as $subject => &$data) {
            $data['topics'] = array_filter($data['topics'] ?? [], fn ($hours) => $hours !== null && $hours !== '');
            foreach ($data['topics'] as $id => $hours) {
                $source = $catalogue[$id] ?? null;
                if (!$source || (str_starts_with($source['subject'], 'Maths') ? 'maths' : 'english') !== $subject) {
                    Rpl68::fail('Choose topics from the correct subject catalogue.');
                }
            }
        }
        return $values;
    }

    // Called inside the setup/English-maths transaction, under the apprentice lock.
    // Keep assessment information in the existing outcome record, and topics in TrainingTopic.
    public static function persist(Apprentice $apprentice, User $actor, array $values, int $expectedVersion): void
    {
        $values = self::validate($values, array_keys($values));
        $outcome = ProgrammeOutcome::where('apprentice_id', $apprentice->id)->lockForUpdate()->first();
        if (($outcome?->version ?? 0) !== $expectedVersion) Rpl68::fail('English/maths information changed. Reopen Functional Skills and save the current information.');
        $outcome ??= new ProgrammeOutcome(['apprentice_id' => $apprentice->id]);
        $saved = [];
        foreach ($values as $subject => $data) {
            foreach ($data['topics'] as $id => $hours) {
                $source = self::catalogue()[$id];
                $key = ['apprentice_id' => $apprentice->id, 'subject' => $subject,
                    'level' => explode(': ', $source['subject'])[1], 'topic' => $source['topic']];
                $topic = TrainingTopic::where(collect($key)->except('topic')->all())
                    ->whereIn('topic', self::topicNames($source))->orderBy('id')->lockForUpdate()->first();
                if ($topic && abs((float) $topic->planned_hours - (float) $hours) > 0.001) Rpl68::fail('This topic already has different planned hours. Keep its existing record and reconcile it in English/maths.');
                if (!$topic) {
                    $topic = new TrainingTopic([...$key, 'area' => $source['area'], 'planned_hours' => $hours]);
                    $topic->status = (float) $hours === 0.0 ? 'Secure' : 'Not Started';
                    $topic->save();
                }
            }
            unset($data['topics']); // The topic table is authoritative; do not keep a second hours record.
            $saved[$subject] = $data;
        }
        $context = $outcome->context ?? [];
        $outcome->history = [...($outcome->history ?? []), ['before' => $context, 'by' => $actor->id, 'at' => now()->toIso8601String(), 'event' => 'functional_skills_setup82']];
        $outcome->context = [...$context, 'functional_skills_setup82' => array_replace($context['functional_skills_setup82'] ?? [], $saved)];
        if ($outcome->exists) $outcome->version++;
        $outcome->save();
        DB::table('learning_events')->insert(['user_id' => $actor->id, 'apprentice_id' => $apprentice->id, 'kind' => 'outcome_context', 'record_id' => $outcome->id, 'event' => 'functional_skills_setup82', 'created_at' => now()]);
    }
}
