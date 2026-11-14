<?php

namespace Tests\Feature;

use App\Models\{Apprentice, SupportPlan, SupportPlanReminder, User};
use App\Notifications\PortalWorkPrompt;
use App\Support\{ReminderDelivery, SupportPlanReminders};
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ConfiguresStaging;
use Tests\TestCase;

class SupportPlanReminderMailTest extends TestCase
{
    use DatabaseMigrations, ConfiguresStaging;

    private function fixture(): array
    {
        $admin=User::factory()->create(['role'=>'admin']);$owner=User::factory()->create(['role'=>'inclusion_member','email'=>'support@example.test']);
        $a=Apprentice::factory()->create();$p=SupportPlan::create(['apprentice_id'=>$a->id,'concern_ids'=>[],'draft'=>[]]);
        $p->status='Active';$p->active_plan=['approved_at'=>now()->subDays(10)->toIso8601String(),
            'fields'=>['review'=>today()->subDay()->toDateString(),'support'=>'PRIVATE-PLAN-CONTENT']];$p->save();
        SupportPlanReminders::assign($p,$admin,1,1,$owner->id);SupportPlanReminders::generate(true);
        $this->configureStaging();config(['reminder_delivery.enabled'=>true,'reminder_delivery.recipients'=>[$owner->email],
            'mail.default'=>'smtp','mail.mailers.smtp'=>['transport'=>'smtp','scheme'=>'smtps','url'=>null,'host'=>'smtp.example.test','port'=>465,'timeout'=>10],
            'mail.from.address'=>'portal@example.test']);
        return [$p->fresh(),$admin,$owner,SupportPlanReminder::firstOrFail()];
    }

    private function reviewed($admin,$owner)
    {
        $s=ReminderDelivery::candidate($owner)['snapshot'];$this->assertNotNull($s);
        $row=ReminderDelivery::prepare($admin,1,$owner->id,ReminderDelivery::hash($s));
        ReminderDelivery::transition($row,$admin,1,1,'review');return $row->fresh();
    }

    public function test_existing_explicit_mail_pilot_supports_generic_coordinator_prompt_once_without_review_completion(): void
    {
        [$p,$admin,$owner,$r]=$this->fixture();$before=$p->getAttributes();Notification::fake();
        $s=ReminderDelivery::candidate($owner)['snapshot'];$this->assertSame('support_plan',$s['items'][0]['kind']);
        $this->assertStringNotContainsString('PRIVATE-PLAN-CONTENT',json_encode($s));
        $row=$this->reviewed($admin,$owner);Notification::assertNothingSent();
        $this->assertSame('accepted',ReminderDelivery::dispatch($row->id)['result']);
        Notification::assertSentToTimes($owner,PortalWorkPrompt::class,1);
        $this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);
        $this->assertSame($before,$p->fresh()->getAttributes());$this->assertNull($r->fresh()->seen_at);
    }

    public function test_seen_or_changed_coordinator_stales_prepared_delivery_without_sending(): void
    {
        [$p,$admin,$owner,$r]=$this->fixture();$row=$this->reviewed($admin,$owner);Notification::fake();
        SupportPlanReminders::seen($r,$owner,1);
        $this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);
        $this->assertSame('stale',$row->fresh()->state);Notification::assertNothingSent();
    }

    public function test_independent_mail_flag_off_and_unverified_owner_block_email_but_not_in_portal_source(): void
    {
        [$p,$admin,$owner,$r]=$this->fixture();config(['reminder_delivery.enabled'=>false]);
        $this->assertNull(ReminderDelivery::candidate($owner)['snapshot']);
        $this->assertTrue(SupportPlanReminders::current($r,$owner));
        config(['reminder_delivery.enabled'=>true]);$owner->email_verified_at=null;$owner->save();
        $this->assertNull(ReminderDelivery::candidate($owner)['snapshot']);
    }

    public function test_outdated_dismissed_support_prompt_is_excluded_from_candidate(): void
    {
        [$p,$admin,$owner,$r]=$this->fixture();$p->status='Closed';$p->save();
        SupportPlanReminders::acknowledge($r,$owner,1,true);
        $this->assertNull(ReminderDelivery::candidate($owner)['snapshot']);
        $this->assertNotNull($r->fresh()->dismissed_at);
    }

    public function test_coordinator_reassignment_or_scope_loss_stales_reviewed_mail(): void
    {
        [$p,$admin,$owner,$r]=$this->fixture();$row=$this->reviewed($admin,$owner);Notification::fake();
        $other=User::factory()->create(['role'=>'inclusion_member']);
        SupportPlanReminders::assign($p,$admin,1,$p->version,$other->id);
        $this->assertSame('not_dispatchable',ReminderDelivery::dispatch($row->id)['result']);
        $this->assertSame('stale',$row->fresh()->state);Notification::assertNothingSent();
    }
}
