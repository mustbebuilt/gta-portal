<?php
namespace Tests\Feature;

use App\Models\{Apprentice,ProgrammeOutcome,ProgressReviewDraft,SignedReviewRecord,TutorGroup,User};
use App\Support\{ReviewedImports,ReviewFollowup};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReviewFollowupTest extends TestCase
{
    use LazilyRefreshDatabase;
    protected function setUp(): void {parent::setUp();$this->travelTo(\Carbon\Carbon::parse('2026-11-13 12:00:00'));}
    private function actor(string $role='admin'): User {return User::factory()->create(['role'=>$role]);}
    // Synthetic hash-bound metadata exercises read classification. Existing SignedReviewRecordTest
    // separately proves upload/freeze/independent-check workflows; this fixture is not a real signature.
    private function source(int $days=80,string $status='Active',bool $known=true,?User $tutor=null,?User $mentor=null): array
    {
        $tutor??=$this->actor('tutor');$mentor??=$this->actor('mentor');$a=Apprentice::factory()->create(['tutor_id'=>$tutor->id,'mentor_id'=>$mentor->id,'start_date'=>today()->subYear()]);$author=$this->actor();$r=ProgressReviewDraft::factory()->create(['apprentice_id'=>$a->id,'author_id'=>$tutor->id,'fields'=>['private'=>'DO NOT PRINT PRIVATE REVIEW TEXT'],'source_snapshot'=>['private'=>'PRIVATE SNAPSHOT']]);$date=today()->subDays($days)->toDateString();$hash=str_repeat('a',64);$snapshot=['apprentice_id'=>$a->id,'review_id'=>$r->id,'review_version'=>1,'file_sha256'=>$hash,'fields'=>['reviewDate'=>$date,'private'=>'PRIVATE SIGNER INFORMATION']];
        $record=SignedReviewRecord::create(['apprentice_id'=>$a->id,'progress_review_draft_id'=>$r->id,'review_version'=>1,'meeting_date'=>$date,'snapshot'=>$snapshot,'snapshot_hash'=>ReviewedImports::hash($snapshot),'file'=>['sha256'=>$hash],'prepared_by'=>$author->id,'checked_by'=>$this->actor()->id,'checked_at'=>now(),'history'=>[]]);ProgrammeOutcome::create(['apprentice_id'=>$a->id,'context'=>['status'=>$status,'sourceRef'=>$known?'Fictional enrolment reference':'']]);return [$a,$r,$record,$tutor,$mentor];
    }
    public function test_checked_due_today_upcoming_unknown_and_paused_are_distinct_and_private_details_stay_out(): void
    {
        $this->source(80);$this->source(70);$this->source(60);$this->source(80,'Active',false);$this->source(80,'Completed');$r=$this->actingAs($this->actor())->get('/reviews')->assertOk()->assertDontSee('DO NOT PRINT PRIVATE REVIEW TEXT')->assertDontSee('PRIVATE SIGNER INFORMATION')->assertDontSee('PRIVATE SNAPSHOT');$this->assertSame(['all'=>5,'overdue'=>1,'today'=>1,'checks'=>1,'upcoming'=>1,'paused'=>1,'due'=>2,'assignments'=>0],$r->viewData('reviewCounts'));$this->assertDatabaseCount('review_reminders',0);
    }
    public function test_no_signed_history_is_a_check_and_a_ready_draft_does_not_reset_the_reference(): void
    {
        [$a,$draft,$record]=$this->source();$record->withdrawn_at=now();$record->save();$draft->state='Ready for signatures';$draft->save();$r=$this->actingAs($this->actor())->get('/reviews?view=checks')->assertOk()->assertSee('Ready for signatures')->assertSee('does not reset the tracked review date');$this->assertSame(1,$r->viewData('reviewCounts')['checks']);$this->assertSame(0,$r->viewData('reviewCounts')['due']);
    }
    public function test_current_tutor_and_mentor_cannot_expand_scope_and_foreign_apprentice_filter_is_404(): void
    {
        [$a,,,$tutor,$mentor]=$this->source();[$foreign]=$this->source();foreach([$tutor,$mentor] as $u){$this->actingAs($u)->get('/reviews')->assertOk()->assertSee($a->reference)->assertDontSee($foreign->reference);$this->get('/reviews?apprentice='.$foreign->id)->assertNotFound();}$this->assertDatabaseCount('review_reminders',0);
    }
    public function test_filters_search_and_pagination_keep_the_same_scoped_counts(): void
    {
        $tutor=$this->actor('tutor');$group=TutorGroup::factory()->create();for($i=0;$i<27;$i++){Apprentice::factory()->create(['tutor_id'=>$tutor->id,'tutor_group_id'=>$group->id]);}$this->actingAs($tutor);$r=$this->get('/reviews?view=checks&group='.$group->id.'&page=99')->assertOk();$this->assertSame(27,$r->viewData('reviewCounts')['all']);$this->assertSame(2,$r->viewData('reviewRows')->currentPage());$this->assertCount(2,$r->viewData('reviewRows')->items());$this->assertStringContainsString('view=checks',$r->viewData('reviewRows')->previousPageUrl());$this->get('/reviews?view=checks&search=NO-SUCH-REFERENCE')->assertOk()->assertViewHas('reviewCounts',fn($c)=>$c['all']===0);
    }
    public function test_dashboard_summary_preserves_apprentice_scope_links_and_is_hidden_from_apprentices(): void
    {
        [$a,,,$tutor]=$this->source();$this->source();$r=$this->actingAs($tutor)->get('/dashboard?apprentice='.$a->id)->assertOk();$this->assertSame(1,$r->viewData('reviewSummary')['counts']['overdue']);$r->assertSee(route('reviews.index',['apprentice'=>$a->id,'view'=>'overdue']));$learner=$this->actor('apprentice');$a->user_id=$learner->id;$a->save();$r=$this->actingAs($learner)->get('/dashboard')->assertOk()->assertDontSee('id="dashboard-reviews-title"',false);$this->assertNull($r->viewData('reviewSummary'));$this->get('/reviews')->assertForbidden();
    }
    public function test_latest_draft_link_uses_updated_revision_and_get_preserves_frozen_records(): void
    {
        [$a,$draft,$record]=$this->source();$new=ProgressReviewDraft::factory()->create(['apprentice_id'=>$a->id,'author_id'=>$draft->author_id,'fields'=>['private'=>'SECRET NEW DRAFT']]);$new->updated_at=now()->addMinute();$new->save();$before=$record->fresh()->getAttributes();$r=$this->actingAs($this->actor())->get('/reviews')->assertOk()->assertSee(route('reviews.edit',[$a,$new]),false)->assertDontSee('SECRET NEW DRAFT');$this->assertSame($new->id,$r->viewData('reviewRows')->first()['latest']->id);$this->assertSame($before,$record->fresh()->getAttributes());$this->assertDatabaseCount('signed_review_events',0);$this->assertDatabaseCount('review_reminders',0);
    }
    public function test_integrity_problem_prevents_a_known_date_being_presented_as_due(): void
    {
        [$a,$r,$record]=$this->source();$record->snapshot_hash=str_repeat('b',64);$record->save();$response=$this->actingAs($this->actor())->get('/reviews?view=due')->assertOk();$this->assertSame(0,$response->viewData('reviewRows')->total());$this->assertSame(1,$response->viewData('reviewCounts')['checks']);
    }
    public function test_due_assignment_checks_are_an_overlapping_flag_and_global_control_is_explicit(): void
    {
        [$a]=$this->source();$a->mentor_id=null;$a->save();$r=$this->actingAs($this->actor())->get('/reviews?view=assignments')->assertOk()->assertSee('all eligible sources regardless of the filters above');$this->assertSame(1,$r->viewData('reviewCounts')['overdue']);$this->assertSame(1,$r->viewData('reviewCounts')['assignments']);$this->assertSame(1,$r->viewData('reviewRows')->total());$this->assertDatabaseCount('review_reminders',0);
    }
    public function test_invalid_query_values_and_unsupported_roles_do_not_expand_access(): void
    {
        $this->actingAs($this->actor())->getJson('/reviews?view=made-up')->assertUnprocessable()->assertJsonValidationErrors('view');$this->getJson('/reviews?search[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('search');$this->getJson('/reviews?apprentice[]=1')->assertUnprocessable()->assertJsonValidationErrors('apprentice');$this->actingAs($this->actor('employer'))->get('/reviews')->assertForbidden();
    }
}
