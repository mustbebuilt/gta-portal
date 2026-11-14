<?php

namespace Tests\Feature;

use App\Models\{AccountSecondFactor, Apprentice, CareerDiscussion, StaffDuty, User};
use App\Support\{StaffDuties, SignedReviews};
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\{Crypt, DB};
use Tests\TestCase;

class StaffDutiesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function fixture(): array
    {
        $admin=User::factory()->create(['role'=>'admin']);$staff=User::factory()->create(['role'=>'tutor']);
        $own=Apprentice::factory()->create(['tutor_id'=>$staff->id,'reference'=>'OWN-MAIN-TUTOR']);
        $career=Apprentice::factory()->create(['careers_adviser_id'=>$staff->id,'reference'=>'ASSIGNED-CAREERS']);
        $mentor=Apprentice::factory()->create(['mentor_id'=>$staff->id,'reference'=>'ASSIGNED-MENTOR']);
        $other=Apprentice::factory()->create(['reference'=>'UNRELATED-APPRENTICE']);
        return [$admin,$staff,$own,$career,$mentor,$other];
    }

    private function grant(User $staff,User $admin,array $flags): void
    {
        StaffDuties::save($staff,$admin,$admin->auth_version,$staff->fresh()->record_version,StaffDuty::find($staff->id)?->version??0,$flags);
    }

    private function discussion(): array
    {
        return ['type'=>'progression','discussion_date'=>today()->toDateString(),'goals'=>'Fictional goal','options'=>'Fictional options',
            'advice'=>'PRIVATE-CAREERS-ADVICE','outcome'=>'Next step agreed','next_step'=>'Fictional next step'];
    }

    public function test_default_additional_duties_are_absent_and_primary_role_rights_are_preserved(): void
    {
        [$admin,$staff]=$this->fixture();
        $this->assertFalse(StaffDuties::has($staff,'careers_adviser'));$this->assertFalse(StaffDuties::has($staff,'mentor'));
        $career=User::factory()->create(['role'=>'careers_adviser']);$mentor=User::factory()->create(['role'=>'mentor']);
        $this->assertTrue(StaffDuties::has($career,'careers_adviser'));$this->assertTrue(StaffDuties::has($mentor,'mentor'));
        $this->assertDatabaseCount('staff_duties',0);
    }

    public function test_versioned_grant_keeps_one_account_and_primary_role_and_records_before_after(): void
    {
        [$admin,$staff,$own,$career,$mentor]=$this->fixture();$count=User::count();$password=$staff->password;
        $this->actingAs($admin)->get('/directories/staff/'.$staff->id)->assertOk()->assertSee('Additional duties on this account');
        $this->post('/staff/'.$staff->id.'/duties',['record_version'=>1,'duty_version'=>0,'duties'=>['careers_adviser'=>1,'mentor'=>1]])
            ->assertRedirect('/directories/staff/'.$staff->id)->assertSessionHasNoErrors();
        $this->assertSame($count,User::count());$this->assertSame('tutor',$staff->fresh()->role);$this->assertSame($password,$staff->fresh()->password);
        $this->assertSame(2,$staff->fresh()->auth_version);$this->assertSame(2,$staff->fresh()->record_version);
        $row=StaffDuty::findOrFail($staff->id);$this->assertTrue($row->careers_adviser);$this->assertTrue($row->mentor);
        $this->assertSame(['careers_adviser'=>false,'mentor'=>false],$row->history[0]['before']);
        $this->assertSame($admin->id,$row->history[0]['by']);
        $this->assertDatabaseHas('account_access_events',['user_id'=>$staff->id,'event'=>'staff_duties_changed']);
        $this->assertSame($staff->id,$career->fresh()->careers_adviser_id);$this->assertSame($staff->id,$mentor->fresh()->mentor_id);
    }

    public function test_only_management_can_change_duties_and_privileged_or_learner_roles_cannot_be_targets(): void
    {
        [$admin,$staff]=$this->fixture();
        foreach(['tutor','mentor','careers_adviser','manager','apprentice','employer']as$role){
            $u=User::factory()->create(['role'=>$role]);$this->actingAs($u)->post('/staff/'.$staff->id.'/duties',
                ['record_version'=>1,'duty_version'=>0,'duties'=>['mentor'=>1]])->assertForbidden();
        }
        foreach(['admin','ceo','apprentice','employer','unassigned']as$role){
            $u=User::factory()->create(['role'=>$role]);$this->actingAs($admin)->post('/staff/'.$u->id.'/duties',
                ['record_version'=>1,'duty_version'=>0,'duties'=>['mentor'=>1]])->assertForbidden();
        }
        $this->assertDatabaseCount('staff_duties',0);
    }

    public function test_stale_versions_unknown_duty_keys_and_inactive_grants_are_rejected(): void
    {
        [$admin,$staff]=$this->fixture();$this->grant($staff,$admin,['mentor'=>true]);
        $this->actingAs($admin)->post('/staff/'.$staff->id.'/duties',['record_version'=>1,'duty_version'=>0,'duties'=>[]])->assertSessionHasErrors('duties');
        $this->post('/staff/'.$staff->id.'/duties',['record_version'=>2,'duty_version'=>1,'duties'=>['admin'=>1]])->assertSessionHasErrors('duties');
        $staff=$staff->fresh();$staff->active=false;$staff->save();
        $this->post('/staff/'.$staff->id.'/duties',['record_version'=>2,'duty_version'=>1,'duties'=>['careers_adviser'=>1]])->assertSessionHasErrors('duties');
        $this->assertFalse(StaffDuties::has($staff,'mentor'));
    }

    public function test_assignments_and_primary_tutor_scope_are_separate_from_careers_and_mentor_grants(): void
    {
        [$admin,$staff,$own,$career,$mentor,$other]=$this->fixture();$this->grant($staff,$admin,['careers_adviser'=>true,'mentor'=>true]);
        $response=$this->actingAs($staff->fresh())->get('/careers')->assertOk();
        $this->assertSame([$career->id],$response->viewData('apprentices')->getCollection()->pluck('id')->all());
        $this->get('/apprentices/'.$career->id.'/careers')->assertOk();$this->get('/apprentices/'.$other->id.'/careers')->assertForbidden();
        $this->get('/reviews')->assertOk()->assertSee('OWN-MAIN-TUTOR')->assertSee('ASSIGNED-MENTOR')->assertDontSee('ASSIGNED-CAREERS')->assertDontSee('UNRELATED-APPRENTICE');
        $this->get('/apprentices/'.$mentor->id.'/reviews/new')->assertOk()->assertDontSee('Open original Action Plan')->assertDontSee('Open individual delivery plan');
        $this->get('/apprentices/'.$other->id.'/reviews/new')->assertForbidden();
        $this->get('/apprentices/'.$mentor->id.'/learning')->assertForbidden();
        $this->get('/apprentices/'.$own->id.'/learning')->assertOk();
        $this->get('/apprentices/'.$career->id.'/support')->assertOk()->assertDontSee('PRIVATE-CAREERS-ADVICE');
        $this->get('/apprentices/'.$career->id.'/support-plans/new')->assertForbidden();
        $this->get('/directories/staff')->assertForbidden();
        $this->assertFalse(\App\Support\SupportAccess::team($staff->fresh()));
    }

    public function test_additional_careers_adviser_can_record_advice_with_original_attribution_and_revoke_without_rewriting_it(): void
    {
        [$admin,$staff,$own,$career]=$this->fixture();$this->grant($staff,$admin,['careers_adviser'=>true]);
        $this->actingAs($staff->fresh())->post('/apprentices/'.$career->id.'/careers',$this->discussion())->assertSessionHasNoErrors();
        $d=CareerDiscussion::firstOrFail();$this->assertSame($staff->id,$d->adviser_id);$before=$d->fresh()->getAttributes();
        $this->grant($staff,$admin,[]);
        $this->get('/apprentices/'.$career->id.'/careers')->assertRedirect('/login');
        $this->actingAs($staff->fresh())->get('/apprentices/'.$career->id.'/careers')->assertForbidden();
        $this->post('/apprentices/'.$career->id.'/careers',$this->discussion())->assertForbidden();
        $this->assertSame($before,$d->fresh()->getAttributes());$this->assertSame($staff->id,$career->fresh()->careers_adviser_id);
        $this->get('/apprentices/'.$own->id.'/learning')->assertOk();
    }

    public function test_revocation_and_regrant_change_binding_without_reusing_old_grants(): void
    {
        [$admin,$staff]=$this->fixture();$this->grant($staff,$admin,['mentor'=>true]);$first=StaffDuties::binding($staff,'mentor');
        $this->grant($staff,$admin,[]);$this->assertFalse(StaffDuties::has($staff,'mentor'));
        $this->grant($staff,$admin,['mentor'=>true]);$this->assertNotSame($first,StaffDuties::binding($staff,'mentor'));
        $this->assertSame(3,StaffDuty::findOrFail($staff->id)->version);
    }

    public function test_mfa_and_password_are_preserved_while_old_authenticated_sessions_are_invalidated(): void
    {
        [$admin,$staff]=$this->fixture();$factor=AccountSecondFactor::create(['user_id'=>$staff->id,'secret'=>Crypt::encryptString('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),'confirmed_at'=>now(),'recovery_hashes'=>[]]);
        $before=$factor->fresh()->getAttributes();$this->actingAs($staff)->withSession(['mfa_version'=>1]);
        $this->grant($staff,$admin,['mentor'=>true]);
        $this->get('/help')->assertRedirect('/login');
        $this->assertSame($before,$factor->fresh()->getAttributes());
    }

    public function test_same_flags_do_not_bump_versions_or_invalidate_again(): void
    {
        [$admin,$staff]=$this->fixture();$this->grant($staff,$admin,['mentor'=>true]);$version=$staff->fresh()->auth_version;
        $this->grant($staff,$admin,['mentor'=>true]);$this->assertSame($version,$staff->fresh()->auth_version);
        $this->assertCount(1,StaffDuty::findOrFail($staff->id)->history);
    }

    public function test_new_eligible_assignments_validate_while_non_granted_staff_remain_unselectable(): void
    {
        [$admin,$staff,$own,$career,$mentor,$other]=$this->fixture();$ungranted=User::factory()->create(['role'=>'tutor']);
        $this->grant($staff,$admin,['careers_adviser'=>true,'mentor'=>true]);
        $this->actingAs($admin)->get('/apprentices/create')->assertOk();
        $payload=['reference'=>'DUTY-ASSIGN-NEW','first_name'=>'Fictional','last_name'=>'Assignment','careers_adviser_id'=>$staff->id,'mentor_id'=>$staff->id];
        $this->post('/apprentices',$payload)->assertSessionHasNoErrors();$row=Apprentice::where('reference','DUTY-ASSIGN-NEW')->firstOrFail();
        $this->assertSame($staff->id,$row->careers_adviser_id);$this->assertSame($staff->id,$row->mentor_id);
        $this->post('/apprentices',[...$payload,'reference'=>'DUTY-INVALID','careers_adviser_id'=>$ungranted->id,'mentor_id'=>$ungranted->id])->assertSessionHasErrors(['careers_adviser_id','mentor_id']);
    }
}
