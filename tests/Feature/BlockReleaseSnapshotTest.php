<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\LearningBlockControl;
use App\Models\LearningTask;
use App\Support\BlockRelease;
use App\Support\TaskCatalogue;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BlockReleaseSnapshotTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_questionnaire_person_lookup_keeps_its_columns_with_a_portable_name(): void
    {
        $indexes=\Illuminate\Support\Facades\Schema::getIndexes('portal_questionnaire_assignments');
        $index=collect($indexes)->firstWhere('name','pq_assignment_person_state_idx');
        $this->assertNotNull($index);
        $this->assertSame(['apprentice_id','recipient_id','state'],$index['columns']);
        $this->assertLessThanOrEqual(64,strlen($index['name']));
    }

    private function source(string $key = 'autocare'): array
    {
        $a = Apprentice::factory()->create();
        $out = collect();
        foreach (TaskCatalogue::tasks($key) as $t) {
            $out->push(LearningTask::factory()->create(['apprentice_id' => $a->id, 'reference' => $t['id'], 'block' => $t['block'], 'template' => [...TaskCatalogue::template($t['id'], $key), 'catalogueId' => $key], 'status' => $t['block'] <= 2 ? 'Approved' : 'Draft']));
        }LearningBlockControl::create(['apprentice_id' => $a->id, 'enabled' => true, 'exceptions' => [], 'history' => []]);

        return [$a, $out];
    }

    public function test_batch_matches_existing_rules_for_all_four_catalogues_and_only_two_reads(): void
    {
        $all = collect();
        foreach (array_keys(TaskCatalogue::PROGRAMMES) as $key) {
            [$a,$tasks] = $this->source($key);
            $all = $all->merge($tasks);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $states = BlockRelease::states($all);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(2, $queries);
        $this->assertCount(200, $states);
        foreach ($all as $t) {
            $this->assertSame(BlockRelease::state($t), $states[$t->id]);
            $this->assertSame($t->block <= 3, $states[$t->id]['open']);
        }
    }

    public function test_partial_page_uses_complete_database_prerequisites_and_next_call_sees_correction(): void
    {
        [$a,$tasks] = $this->source();
        $third = $tasks->firstWhere('block', 3);
        $subset = collect([$third]);
        $this->assertTrue(BlockRelease::states($subset)[$third->id]['open']);
        $tasks->firstWhere('block', 1)->forceFill(['status' => 'Referred'])->save();
        $this->assertFalse(BlockRelease::states($subset)[$third->id]['open']);
        $this->assertSame('Complete all five source tasks in Block 1 first', BlockRelease::states($subset)[$third->id]['label']);
        $this->expectException(ValidationException::class);
        BlockRelease::check($third);
    }

    public function test_missing_mixed_source_and_duplicate_reference_cannot_release_the_next_block(): void
    {
        foreach (['missing', 'different-catalogue', 'source-pending', 'duplicate'] as $variant) {
            [$a,$tasks] = $this->source();
            $prior = $tasks->firstWhere('block', 1);
            $third = $tasks->firstWhere('block', 3);
            if ($variant === 'missing') {
                $prior->delete();
            } elseif ($variant === 'duplicate') {
                LearningTask::whereKey($prior->id)->delete();
                $other = $tasks->where('block', 1)->values()[1];
                LearningTask::factory()->create(['apprentice_id' => $a->id, 'block' => 1, 'reference' => $other->reference.'-unexpected', 'template' => $other->template, 'status' => 'Approved']);
            } else {
                $data = $prior->template;
                $data[$variant === 'source-pending' ? 'kind' : 'catalogueId'] = $variant === 'source-pending' ? 'source-pending' : 'heavy-vehicle';
                $prior->update(['template' => $data]);
            }
            $this->assertFalse(BlockRelease::states(collect([$third]))[$third->id]['open']);
            $this->assertSame(BlockRelease::state($third), BlockRelease::states(collect([$third]))[$third->id]);
        }
    }

    public function test_missing_disabled_exception_and_outside_training_blocks_keep_existing_order(): void
    {
        [$a,$tasks] = $this->source();
        $third = $tasks->firstWhere('block', 3);
        $control = LearningBlockControl::where('apprentice_id', $a->id)->firstOrFail();
        $last = LearningTask::factory()->create(['apprentice_id' => $a->id, 'block' => 12, 'reference' => 'outside-training-block']);
        $this->assertFalse(BlockRelease::states(collect([$last]))[$last->id]['open']);
        $control->update(['exceptions' => ['12' => ['reason' => 'Fictional explicit exception']]]);
        $this->assertTrue(BlockRelease::states(collect([$last]))[$last->id]['open']);
        $control->update(['enabled' => false]);
        $this->assertSame(['open' => true, 'label' => 'Sequence control not enabled'], BlockRelease::states(collect([$last]))[$last->id]);
        $control->delete();
        $this->assertSame(BlockRelease::state($third), BlockRelease::states(collect([$third]))[$third->id]);
        $this->assertSame([], BlockRelease::states(collect()));
    }
}
