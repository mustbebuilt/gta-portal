<?php

namespace Database\Seeders;

use App\Models\Block;
use App\Models\Standard;
use Illuminate\Database\Seeder;

class CurriculumSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Apprenticeship Standard (Autocare Technician)
        $standard = Standard::firstOrCreate(['standard_code' => 'ST0482'], [
            'title' => 'Autocare Technician',
            'level' => 2,
            'duration_months' => 30,
            'total_otj_target' => 663.00,
        ]);

        // 2. Exact 10 Blocks + Gateway/EPA from GTA v1.2.6 Developer Pack
        $blockDefinitions = [
            1 => [
                'title' => 'Induction, Safety and Core Workshop Skills',
                'primary_themes' => 'Workshop safety, tools, PPE, vehicle lifting and basic inspection fundamentals',
                'start_date' => '2025-07-14',
                'end_date' => '2025-09-08',
                'nominal_hours' => 30,
                'configured' => true,
            ],
            2 => [
                'title' => 'Inspection, Servicing and Customer Interaction',
                'primary_themes' => 'Routine servicing, fluid checks, filter replacement, customer check-in and handover',
                'start_date' => '2025-09-16',
                'end_date' => '2025-11-27',
                'nominal_hours' => 30,
                'configured' => true,
            ],
            3 => [
                'title' => 'Workshop Processes and Environmental Awareness',
                'primary_themes' => 'Waste disposal, hazardous substances (COSHH), recycling and environmental regulations',
                'start_date' => '2025-12-05',
                'end_date' => '2026-02-15',
                'nominal_hours' => 30,
                'configured' => true,
            ],
            4 => [
                'title' => 'Emerging Technology and Driveline Systems',
                'primary_themes' => 'Electric & hybrid vehicle systems awareness, high-voltage safety, transmission & driveline',
                'start_date' => '2026-02-23',
                'end_date' => '2026-05-06',
                'nominal_hours' => 30,
                'configured' => true,
            ],
            5 => [
                'title' => 'Diagnostic Thinking and Complex Repairs',
                'primary_themes' => 'Braking systems, ABS, steering, suspension fault diagnosis and rectification',
                'start_date' => '2026-05-14',
                'end_date' => '2026-07-25',
                'nominal_hours' => 30,
                'configured' => true,
            ],
            6 => [
                'title' => 'Systems Integration and Advanced Diagnostics',
                'primary_themes' => 'Engine management, sensor testing, wiring diagrams, scan tool diagnostics',
                'start_date' => '2026-08-02',
                'end_date' => '2026-10-16',
                'nominal_hours' => 30,
                'configured' => true,
            ],
            7 => [
                'title' => 'Advanced Workshop Practice and Communication',
                'primary_themes' => 'Complex mechanical repairs, service reporting, mentoring peers, quality checks',
                'start_date' => '2026-10-24',
                'end_date' => '2027-01-04',
                'nominal_hours' => 30,
                'configured' => true,
            ],
            8 => [
                'title' => 'Advanced Diagnostics and Workshop Management',
                'primary_themes' => 'Advanced electrical fault finding, workshop job allocation, MOT preparation',
                'start_date' => '2027-01-12',
                'end_date' => '2027-03-25',
                'nominal_hours' => 30,
                'configured' => true,
            ],
            9 => [
                'title' => 'Emerging Technology, EV and Hydrogen',
                'primary_themes' => 'ADAS calibration principles, oscilloscope diagnostics, EV battery service & hydrogen safety',
                'start_date' => '2027-04-03',
                'end_date' => '2027-07-08',
                'nominal_hours' => 30,
                'configured' => true,
            ],
            10 => [
                'title' => 'EPA Preparation and Final Assessment Readiness',
                'primary_themes' => 'Showcase portfolio completion, mock professional discussion, synoptic practical assessments',
                'start_date' => '2027-07-16',
                'end_date' => '2027-12-02',
                'nominal_hours' => 30,
                'configured' => true,
            ],
            11 => [
                'title' => 'Gateway readiness · Blocks 11–12',
                'primary_themes' => 'Gateway review sign-off, apprentice, employer and GTA tripartite declaration',
                'start_date' => '2027-12-10',
                'end_date' => '2027-12-26',
                'nominal_hours' => 0,
                'configured' => false,
            ],
            12 => [
                'title' => 'End-Point Assessment (EPA)',
                'primary_themes' => 'External independent EPA practical observation and professional discussion',
                'start_date' => '2028-01-03',
                'end_date' => '2028-01-03',
                'nominal_hours' => 0,
                'configured' => false,
            ],
        ];

        foreach ($blockDefinitions as $blockNum => $def) {
            Block::updateOrCreate(
                [
                    'standard_id' => $standard->standard_id,
                    'block_num' => $blockNum,
                ],
                [
                    'title' => $def['title'],
                    'primary_themes' => $def['primary_themes'],
                    'nominal_hours' => $def['nominal_hours'],
                    'start_date' => $def['start_date'],
                    'end_date' => $def['end_date'],
                    'configured' => $def['configured'],
                ]
            );
        }

        $this->command->info('Standard ST0482 and 12 Blocks successfully seeded matching v1.2.6.');
    }
}
