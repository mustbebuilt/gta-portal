<?php

namespace Tests\Feature;

use App\Support\Rpl68;
use App\Support\RplPlanDates69;
use Tests\TestCase;

final class RplPlanDates69Test extends TestCase
{
    public function test_all_four_programmes_use_their_matrix_and_approved_calendar(): void
    {
        foreach (self::fixtures() as [$code, $version, $hours, $count]) {
            $policy = ['hours_per_training_week' => 6, 'training_weeks_per_year' => 46.4, 'epa_months' => 1];
            config(['gta_rpl_dates' => ["$code:$version" => $policy]]);
            $template = self::template($code, $version, $hours, $count);
            $rpl = ['mode' => 'applies', 'adjustments' => ['K1' => 20, 'S1' => 30, 'B1' => 50]];
            $result = Rpl68::calculate($template, $rpl);
            $this->assertEqualsWithDelta($hours - $hours / $count, $result['revised_hours_unrounded'], 0.00000001);
            $this->assertSame([], $result['unresolved']);
            $this->assertSame($policy, RplPlanDates69::policy($template));
            $original = RplPlanDates69::calculate($policy, '2027-01-11', $hours);
            $revised = RplPlanDates69::calculate($policy, '2027-01-11', $result['revised_hours_unrounded']);
            $this->assertLessThan($original['practical_end'], $revised['practical_end']);
            $this->assertLessThan($original['programme_end'], $revised['programme_end']);
        }
    }

    public function test_agreed_dates_and_schedule_must_match_the_current_revision(): void
    {
        $policy = ['hours_per_training_week' => 6, 'training_weeks_per_year' => 46.4, 'epa_months' => 1];
        config(['gta_rpl_dates' => ['ST0499:1.3' => $policy]]);
        $template = self::template('ST0499', '1.3', 660, 115);
        $start = '2027-01-11';
        $programme = ['template_id' => 3, 'template_hash' => 'test-source', 'start_date' => $start];
        $rpl = ['mode' => 'applies', 'adjustments' => ['K1' => 20, 'S1' => 30, 'B1' => 50]];
        $calc = Rpl68::calculate($template, $rpl);
        $proposed = RplPlanDates69::review($template, $programme, $rpl, $calc, []);
        $this->assertSame(['practical_end' => '2029-05-17', 'programme_end' => '2029-06-17'], $proposed['proposed']);
        $this->assertNull($proposed['approved']);

        $record = [...$proposed['proposed'], 'source' => 'Agreed plan v1', 'agreed_hours' => 654.26,
            'binding' => RplPlanDates69::binding($programme, $rpl, $policy)];
        $this->assertNull(RplPlanDates69::review($template, $programme, $rpl, $calc, $record)['approved']);
        $lines = [];
        $date = new \DateTimeImmutable($start);
        for ($i = 0; $i < 10; $i++) {
            $from = $date->modify('+'.($i * 40).' days')->format('Y-m-d');
            $to = $date->modify('+'.($i * 40 + 39).' days')->format('Y-m-d');
            $lines[] = "$from,$to";
        }
        $lines[] = $date->modify('+400 days')->format('Y-m-d').',2029-05-17';
        $lines[] = '2029-05-18,2029-06-17';
        $record['schedule'] = implode("\n", $lines);
        $review = RplPlanDates69::review($template, $programme, $rpl, $calc, $record);
        $this->assertSame('Agreed plan v1', $review['approved']['source']);
        $wrongHours = [...$record, 'agreed_hours' => 640];
        $this->assertNull(RplPlanDates69::review($template, $programme, $rpl, $calc, $wrongHours)['approved']);
        $this->assertSame('2029-06-17', $review['approved']['blocks'][11]['end']);
        $programme['start_date'] = '2027-01-12';
        $this->assertNull(RplPlanDates69::review($template, $programme, $rpl, $calc, $record)['approved']);
    }

    public function test_epa_window_clamps_at_month_end_and_agreed_pause_counts_both_days(): void
    {
        $policy = ['hours_per_training_week' => 6, 'training_weeks_per_year' => 46.4, 'epa_months' => 1];
        $this->assertSame('2028-02-29', RplPlanDates69::calculate($policy, '2028-01-30', 1)['programme_end']);
        $withPause = RplPlanDates69::calculate($policy, '2027-01-11', 594, [
            'pause_start' => '2027-02-01', 'pause_end' => '2027-02-14',
        ]);
        $this->assertSame(['practical_end' => '2029-03-13', 'programme_end' => '2029-04-13'], $withPause);
    }

    private static function fixtures(): array
    {
        // Test values exercise different volumes and never seed controlled templates.
        return [['ST0499', '1.3', 660, 115], ['ST0068', '1.5', 800, 72],
            ['ST0403', '1.3', 760, 44], ['ST0448', '1.2', 840, 56]];
    }

    private static function template(string $code, string $version, int $hours, int $count): array
    {
        $blocks = [];
        for ($n = 1; $n <= 12; $n++) {
            $blocks[] = ['number' => $n, 'title' => 'Block '.$n, 'hours' => $n <= 10 ? $hours / 10 : 0,
                'start_day' => ($n - 1) * 30, 'end_day' => $n * 30 - 1];
        }
        $ksbs = [];
        for ($i = 0; $i < $count; $i++) {
            $ksbs[] = ['code' => 'KSB'[$i % 3].(intdiv($i, 3) + 1), 'description' => 'Test requirement',
                'blocks' => $i === 0 ? [1, 2] : [1], ...($i === 0 ? ['allocation' => [1 => 0.5, 2 => 0.5]] : [])];
        }
        return ['standard_code' => $code, 'standard_version' => $version, 'full_hours' => $hours, 'ksbs' => $ksbs,
            'blocks' => $blocks, 'source_ref' => 'test', 'ksb_ref' => 'test',
            'qualifications_ref' => 'test', 'release_ref' => 'test'];
    }
}
