<?php

namespace Tests\Feature;

use App\Models\Apprentice;
use App\Models\Programme;
use App\Models\ProgrammeOutcome;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AchieversAndLeaversReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_confirmed_achievement_and_withdrawal_are_counted_from_their_own_dates(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-20 18:00'));
        $admin = User::factory()->create(['role'=>'admin']);
        $tutor = User::factory()->create(['role'=>'tutor']);
        $first = Programme::factory()->create();
        $second = Programme::factory()->create();
        $record = function (string $name, Programme $p, array $context, ?string $planned = '2026-11-15'): void {
            $a = Apprentice::factory()->create([
                'first_name'=>$name, 'start_date'=>'2025-10-01',
                'planned_end_date'=>$planned, 'programme_id'=>$p->id,
            ]);
            ProgrammeOutcome::create(['apprentice_id'=>$a->id, 'context'=>$context + ['sourceRef'=>'synthetic source']]);
        };

        $record('PassedInNovember',$first,['status'=>'Completed','achievement'=>'Achieved','endDate'=>'2026-10-28','achievementDate'=>'2026-11-05']);
        $early = Apprentice::factory()->create([
            'first_name' => 'LeftEarly', 'start_date' => '2025-10-01',
            'planned_end_date' => '2026-11-15', 'programme_id' => $first->id,
        ]);
        $this->actingAs($admin)->get(route('apprentices.close-down', $early))
            ->assertOk()->assertSee('Record a withdrawal');
        $this->post(route('retention.save', $early), [
            'version' => 0, 'context' => [
                'status' => 'Withdrawn', 'endDate' => '2026-11-04',
                'sourceRef' => 'Synthetic withdrawal source',
                'reason' => 'Employment ended', 'reasonBasis' => 'Apprentice confirmed', 'exitEmployment' => 'Not employed',
            ],
        ])->assertSessionHasNoErrors();
        $record('LeftOnDate',$first,['status'=>'Withdrawn','endDate'=>'2026-11-15']);
        $record('LeftAfterDate',$first,['status'=>'Withdrawn','endDate'=>'2026-11-16']);
        $record('LeftNoPlan',$first,['status'=>'Withdrawn','endDate'=>'2026-11-03'],null);
        $record('CompletedWithoutAchievement',$first,['status'=>'Completed','achievement'=>'Not achieved','endDate'=>'2026-11-05']);
        $record('AwaitingResult',$first,['status'=>'Completed','achievement'=>'Awaiting result','endDate'=>'2026-11-05']);
        $record('StillActive',$first,['status'=>'Active']);
        $record('OnBreak',$first,['status'=>'Break in learning']);
        $record('IgnoredTransfer',$first,['status'=>'Transferred','endDate'=>'2026-11-05']);
        $record('PreviousOctober',$first,['status'=>'Withdrawn','endDate'=>'2026-10-05']);
        $record('CheckUndated',$first,['status'=>'Withdrawn']);
        $record('OtherProgramme',$second,['status'=>'Withdrawn','endDate'=>'2026-11-05']);

        $path = '/reports/achievers-and-leavers?from=2026-11-01&to=2026-11-20&programme='.$first->id;
        $page = $this->actingAs($admin)->get($path)->assertOk()
            ->assertSee('PassedInNovember')->assertSee('LeftEarly')->assertDontSee('LeftOnDate')->assertDontSee('LeftAfterDate')
            ->assertSee('Check planned end date')->assertSee('Outcome source checks')->assertSee('Undated')
            ->assertDontSee('CompletedWithoutAchievement')->assertDontSee('AwaitingResult')->assertDontSee('StillActive')->assertDontSee('OnBreak')
            ->assertDontSee('IgnoredTransfer')->assertDontSee('OtherProgramme')->assertDontSee('PreviousOctober');
        $this->assertCount(1,$page->viewData('achievers'));
        $earlyLeavers = $page->viewData('earlyLeavers');
        $this->assertSame(['LeftEarly'],
            $earlyLeavers->pluck('apprentice.first_name')->values()->all());
        $this->assertSame('Not employed', $earlyLeavers->first()['exit_employment']);
        $this->assertSame('Unknown', $page->viewData('achievers')->first()['exit_employment']);
        $checks = $page->viewData('checks');
        $this->assertCount(2, $checks);
        $this->assertSame('Check planned end date', $checks->firstWhere('apprentice.first_name', 'LeftNoPlan')['issue']);
        $this->assertSame('Check withdrawal source or date', $checks->firstWhere('apprentice.first_name', 'CheckUndated')['issue']);
        $retention = $this->get(route('retention.report', ['programme_id' => $first->id]))->assertOk();
        $this->assertSame(2, $retention->viewData('groups')->first()['early_leavers']);
        $this->assertSame(6, $retention->viewData('groups')->first()['withdrawn']);
        $rows = $retention->viewData('rows')->getCollection();
        $this->assertSame('Not employed', $rows->firstWhere('apprentice.first_name', 'LeftEarly')['exit_employment']);
        $this->assertSame('Early leaver', $rows->firstWhere('apprentice.first_name', 'LeftEarly')['withdrawal_timing']);
        $this->assertSame('Withdrawn on or after planned end', $rows->firstWhere('apprentice.first_name', 'LeftOnDate')['withdrawal_timing']);
        $this->assertSame('Withdrawn on or after planned end', $rows->firstWhere('apprentice.first_name', 'LeftAfterDate')['withdrawal_timing']);
        $this->actingAs($tutor)->get($path)->assertForbidden();
    }
}
