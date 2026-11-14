<?php
namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\ProgressReviewDraft;
use App\Models\SignedReviewRecord;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseCompatibilityTest extends TestCase
{
    use LazilyRefreshDatabase;
    public function test_legacy_review_index_normalisation_keeps_source_rows_and_is_idempotent(): void
    {
        $a=Apprentice::factory()->create();$u=User::factory()->create();$review=ProgressReviewDraft::create(['apprentice_id'=>$a->id,'author_id'=>$u->id,'fields'=>['summary'=>'Fictional retained index-migration source'],'source_snapshot'=>['value'=>'Unchanged source']]);
        $record=SignedReviewRecord::create(['apprentice_id'=>$a->id,'progress_review_draft_id'=>$review->id,'review_version'=>1,'meeting_date'=>today(),'snapshot'=>['retained'=>'Fictional original source'],'snapshot_hash'=>str_repeat('a',64),'file'=>['disk'=>'legacy-private','path'=>'retained/source.pdf'],'prepared_by'=>$u->id,'history'=>[]]);
        $before=$record->fresh()->getAttributes();$reviewBefore=$review->fresh()->getAttributes();
        if(DB::connection()->getDriverName()==='sqlite'){
            Schema::table('signed_review_records',function(Blueprint $t): void {$t->dropIndex('review_parent_withdrawn_idx');$t->index(['progress_review_draft_id','withdrawn_at'],'signed_review_records_progress_review_draft_id_withdrawn_at_index');});
        }
        $migration=require database_path('migrations/2026_11_13_210000_normalize_signed_review_index.php');$migration->up();$migration->up();
        $this->assertTrue(Schema::hasIndex('signed_review_records','review_parent_withdrawn_idx'));$this->assertFalse(Schema::hasIndex('signed_review_records','signed_review_records_progress_review_draft_id_withdrawn_at_index'));
        $this->assertSame($before,$record->fresh()->getAttributes());$this->assertSame($reviewBefore,$review->fresh()->getAttributes());
        foreach(Schema::getIndexes('signed_review_records') as $index){$this->assertLessThanOrEqual(64,strlen($index['name']));}
    }
}
