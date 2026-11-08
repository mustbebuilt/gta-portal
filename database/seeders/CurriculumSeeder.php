<?php

namespace Database\Seeders;

use App\Models\Block;
use App\Models\Standard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        $packPath = base_path('../GTA_Portal_v1.2.6_Complete_Developer_Pack/source-project/source');
        $catalogFile = $packPath . '/autocare-catalogue.json';

        $catalog = File::exists($catalogFile)
            ? json_decode(File::get($catalogFile), true)
            : [];

        // 1. Seed Apprenticeship Standard (Autocare Technician)
        $standard = Standard::firstOrCreate(['standard_code' => 'ST0482'], [
            'title' => 'Autocare Technician',
            'level' => 2,
            'duration_months' => 30,
            'total_otj_target' => 663.00,
        ]);

        // 2. Seed 10 Blocks from catalogue
        for ($i = 1; $i <= 10; $i++) {
            $blockKey = "block_{$i}";
            $blockData = $catalog['blocks'][$blockKey] ?? [];

            Block::firstOrCreate(
                [
                    'standard_id' => $standard->standard_id,
                    'block_num' => $i,
                ],
                [
                    'title' => $blockData['title'] ?? "Block {$i}: Core Technical Training",
                    'primary_themes' => $blockData['themes'] ?? "Technical maintenance and inspection skills for Block {$i}",
                    'nominal_hours' => 30,
                ]
            );
        }

        $this->command->info('Standard ST0482 and 10 Blocks successfully seeded.');
    }
}

