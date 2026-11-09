<?php

namespace Database\Seeders;

use App\Models\CareerDiscussion;
use App\Models\CareerResource;
use App\Models\StaffDevelopmentRecord;
use App\Models\StaffDevelopmentTarget;
use App\Models\Apprentice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class CareerAndStaffSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Seed Career Resources from json
        $resourcesPath = resource_path('data/career-resources.json');
        if (File::exists($resourcesPath)) {
            $data = json_decode(File::get($resourcesPath), true);
            foreach ($data['resources'] ?? [] as $res) {
                CareerResource::firstOrCreate(['title' => $res['title']], [
                    'category' => $res['category'] ?? 'motor',
                    'description' => $res['description'] ?? '',
                    'link_url' => $res['url'] ?? null,
                    'target_stage' => $res['audience'] ?? 'All Apprentices',
                ]);
            }
        }

        // 2. Seed a sample Career Discussion for Learner One
        $learnerOne = Apprentice::first();
        $adminUser = User::where('role', \App\Enums\UserRole::Admin)->first();

        if ($learnerOne && $adminUser) {
            CareerDiscussion::firstOrCreate([
                'apprentice_id' => $learnerOne->apprentice_id,
                'discussion_type' => 'progression',
            ], [
                'adviser_id' => $adminUser->id,
                'discussion_date' => Carbon::now()->subWeeks(2)->toDateString(),
                'goals' => 'Wants to qualify as an Autocare Technician and progress to Level 3 Light Vehicle Service and Maintenance, specifically focusing on Hybrid and EV high-voltage diagnostic systems.',
                'options_discussed' => 'Discussed Level 3 progression routes, IMI Electric/Hybrid qualifications, MOT tester course eligibility criteria (requires 3 years diagnostic experience post-EPA), and GTA workshop specialist masterclasses.',
                'advice_given' => 'Maintain 100% on-track status on Block 9 and 10 coursework. Focus on electrical wiring and oscilloscope diagnostics during workshop days.',
                'outcome' => 'Next step agreed',
                'referral_to' => 'GTA Level 3 Programme Lead',
                'next_steps' => 'Review progress at Gateway review; apprentice will compile diagnostic logbook entries for EV routine maintenance.',
            ]);
        }

        // 3. Seed Staff Development for Tutor Alex
        $tutorAlex = User::where('email', 'tutor.alex@gta-portal.test')->first();
        if ($tutorAlex && $adminUser) {
            StaffDevelopmentRecord::firstOrCreate([
                'user_id' => $tutorAlex->id,
                'title' => 'Annual Advanced ADAS & Radar Calibration Masterclass',
            ], [
                'manager_id' => $adminUser->id,
                'category' => 'cpd',
                'record_date' => Carbon::now()->subMonths(1)->toDateString(),
                'feedback' => 'Completed 2-day certified manufacturer training on radar/lidar target alignment and dynamic windscreen camera calibrations.',
                'evidence_ref' => 'CERT-ADAS-2026-GTA',
                'is_shared' => true,
                'private_notes' => 'Alex demonstrated exceptional mastery in front radar alignment. Recommended to lead the new apprentice ADAS workshop block.',
                'status' => 'Completed',
            ]);

            StaffDevelopmentRecord::firstOrCreate([
                'user_id' => $tutorAlex->id,
                'title' => 'Peer Teaching Observation: Hybrid Inverter Safety & Lock-Off',
            ], [
                'manager_id' => $adminUser->id,
                'category' => 'observation',
                'record_date' => Carbon::now()->subWeeks(3)->toDateString(),
                'feedback' => 'High quality workshop observation. Excellent learner engagement during the high-voltage CAT IV multi-meter proving procedure. Clear emphasis on safety boundaries and zero-energy verification.',
                'evidence_ref' => 'OBS-HYBRID-TUTOR-ALEX',
                'is_shared' => true,
                'private_notes' => 'Exemplary practice. Learner questions were fielded thoroughly with practical demonstrations.',
                'status' => 'Completed',
            ]);

            StaffDevelopmentTarget::firstOrCreate([
                'user_id' => $tutorAlex->id,
                'target' => 'Complete Level 4 Award in Internal Quality Assurance of Assessment Processes and Practice (IQA)',
            ], [
                'measure' => 'Successfully submit 3 monitored assessment sampling plans and pass final portfolio review.',
                'reason' => 'Enable Alex to conduct formal IQA sampling for Block 8-10 EPA portfolio submissions.',
                'support_provided' => 'GTA course funding provided; 4 hours protected CPD time per month allocated.',
                'deadline' => Carbon::now()->addMonths(4)->toDateString(),
                'status' => 'In Progress',
            ]);
        }
    }
}

