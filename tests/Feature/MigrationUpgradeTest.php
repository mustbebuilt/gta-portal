<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MigrationUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_preserves_old_text_dates_and_unmapped_identity(): void
    {
        $files = glob(database_path('migrations/*.php'));
        $upgrades = array_values(array_filter($files, fn ($file) => basename($file) >= '2026_11_11_202128_add_directory_links_to_apprentices.php'));
        sort($upgrades);
        foreach (array_reverse($upgrades) as $file) {
            $migration = require $file;
            $migration->down();
        }
        DB::table('apprentices')->insert(['reference' => 'OLD-001', 'first_name' => 'Fictional', 'last_name' => 'Old Record', 'programme' => 'Paper training plan', 'employer' => 'Paper employer', 'group_name' => 'Paper group', 'start_date' => '2026-01-05', 'version' => 7, 'created_at' => now(), 'updated_at' => now()]);
        $before = (array) DB::table('apprentices')->where('reference', 'OLD-001')->first();
        foreach ($upgrades as $file) {
            $migration = require $file;
            $migration->up();
        }
        $after = (array) DB::table('apprentices')->where('reference', 'OLD-001')->first();
        foreach ($before as $key => $value) {
            $this->assertSame($value, $after[$key], $key);
        }foreach (['programme_id', 'employer_id', 'tutor_group_id', 'mentor_id', 'careers_adviser_id', 'maths_tutor_id', 'english_tutor_id'] as $key) {
            $this->assertNull($after[$key], $key);
        }
    }
}
