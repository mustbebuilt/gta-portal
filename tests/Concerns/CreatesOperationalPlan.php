<?php

namespace Tests\Concerns;

use App\Models\Apprentice;
use App\Models\IndividualDeliveryPlan;
use App\Models\Programme;
use App\Models\User;

trait CreatesOperationalPlan
{
    private function newActor(string $role): User
    {
        $u=User::factory()->create();$u->role=$role;$u->save();return $u;
    }

    private function approvedFixture(): array
    {
        $admin=$this->newActor('admin');$ceo=$this->newActor('ceo');$tutor=$this->newActor('tutor');$learner=$this->newActor('apprentice');$adviser=$this->newActor('careers_adviser');
        $p=Programme::factory()->create(['standard_code'=>'ST0499','version'=>'1.3']);
        $a=Apprentice::factory()->create(['programme_id'=>$p->id,'start_date'=>today()->subDays(200),'employer'=>'Fictional plan employer','tutor_id'=>$tutor->id,'careers_adviser_id'=>$adviser->id]);
        $a->user_id=$learner->id;$a->save();
        $blocks=collect(range(0,11))->map(fn($n)=>['start'=>$a->start_date->copy()->addDays($n*20)->toDateString(),'end'=>$a->start_date->copy()->addDays($n*20+19)->toDateString(),'hours'=>$n===11?0:10])->all();
        $url='/apprentices/'.$a->id.'/delivery-plan';
        $this->actingAs($admin)->post($url.'/calendar',['version'=>0,'blocks'=>$blocks])->assertSessionHasNoErrors();
        $this->post($url.'/details',['version'=>1,'source_ref'=>'Fictional reviewed plan','ksb_ref'=>'Fictional reviewed mapping','qualifications_ref'=>'Fictional qualification requirements','release_ref'=>'Fictional employer release','target_hours'=>110,'basis'=>'Full training retained'])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post($url.'/approve',['version'=>2,'source_checked'=>1,'hours_checked'=>1,'curriculum_checked'=>1,'release_checked'=>1])->assertSessionHasNoErrors();
        return [$a,$admin,$ceo,$tutor,$learner,$adviser,IndividualDeliveryPlan::where('apprentice_id',$a->id)->firstOrFail()];
    }
}
