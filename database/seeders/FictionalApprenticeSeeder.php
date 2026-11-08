<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ActionPlan;
use App\Models\ActionPlanItem;
use App\Models\Apprentice;
use App\Models\ApprenticeFunctionalSkill;
use App\Models\ApprenticeMandatoryQual;
use App\Models\ApprenticeTask;
use App\Models\Employer;
use App\Models\Standard;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class FictionalApprenticeSeeder extends Seeder
{
    public function run(): void
    {
        $defaultPassword = Hash::make('password');

        // 1. Seed Administrators
        User::firstOrCreate(['email' => 'admin.lead@gta-portal.test'], [
            'first_name' => 'GTA',
            'last_name' => 'Administrator',
            'username' => 'admin_lead',
            'role' => UserRole::Admin,
            'password' => $defaultPassword,
        ]);

        User::firstOrCreate(['email' => 'admin.audit@gta-portal.test'], [
            'first_name' => 'Sarah',
            'last_name' => 'Auditor',
            'username' => 'admin_sarah',
            'role' => UserRole::Admin,
            'password' => $defaultPassword,
        ]);

        // 2. Seed Main Tutors (Alex & Sam)
        $tutorAlex = User::firstOrCreate(['email' => 'tutor.alex@gta-portal.test'], [
            'first_name' => 'Alex',
            'last_name' => 'Taylor',
            'username' => 'tutor_alex',
            'role' => UserRole::Trainer,
            'password' => $defaultPassword,
        ]);

        $tutorSam = User::firstOrCreate(['email' => 'tutor.sam@gta-portal.test'], [
            'first_name' => 'Sam',
            'last_name' => 'Morgan',
            'username' => 'tutor_sam',
            'role' => UserRole::Trainer,
            'password' => $defaultPassword,
        ]);

        // 3. Seed Progress Mentors (Jordan & Casey)
        User::firstOrCreate(['email' => 'mentor.jordan@gta-portal.test'], [
            'first_name' => 'Jordan',
            'last_name' => 'Reviewer',
            'username' => 'mentor_jordan',
            'role' => UserRole::Mentor,
            'password' => $defaultPassword,
        ]);

        // 4. Seed Employers (Garages A & B)
        User::firstOrCreate(['email' => 'employer.a@gta-portal.test'], [
            'first_name' => 'David',
            'last_name' => 'Workshop Manager',
            'username' => 'garage_a_mgr',
            'role' => UserRole::Employer,
            'password' => $defaultPassword,
        ]);

        $employerA = Employer::firstOrCreate(['employer_name' => 'Example Garage A (Autocare Services)'], [
            'contact_name' => 'David Workshop Manager',
            'contact_email' => 'employer.a@gta-portal.test',
            'postcode' => 'B1 1AA',
        ]);

        $standard = Standard::where('standard_code', 'ST0482')->first();

        // 5. Seed Fictional Apprentices (Learner One & Learner Two)
        $learnerOneUser = User::firstOrCreate(['email' => 'learner.one@gta-portal.test'], [
            'first_name' => 'Test',
            'last_name' => 'Learner One',
            'username' => 'learner_one',
            'role' => UserRole::Apprentice,
            'password' => $defaultPassword,
        ]);

        $apprenticeOne = Apprentice::firstOrCreate(['user_id' => $learnerOneUser->id], [
            'employer_id' => $employerA->employer_id,
            'trainer_id' => $tutorAlex->id,
            'standard_id' => $standard?->standard_id,
            'start_date' => Carbon::now()->subMonths(6)->toDateString(),
            'planned_end_date' => Carbon::now()->addMonths(24)->toDateString(),
            'otj_target_hours' => 663.00,
            'otj_hours_completed' => 142.50,
            'status' => 'Live',
        ]);

        $learnerTwoUser = User::firstOrCreate(['email' => 'learner.two@gta-portal.test'], [
            'first_name' => 'Test',
            'last_name' => 'Learner Two',
            'username' => 'learner_two',
            'role' => UserRole::Apprentice,
            'password' => $defaultPassword,
        ]);

        $apprenticeTwo = Apprentice::firstOrCreate(['user_id' => $learnerTwoUser->id], [
            'employer_id' => $employerA->employer_id,
            'trainer_id' => $tutorSam->id,
            'standard_id' => $standard?->standard_id,
            'start_date' => Carbon::now()->subMonths(3)->toDateString(),
            'planned_end_date' => Carbon::now()->addMonths(27)->toDateString(),
            'otj_target_hours' => 663.00,
            'otj_hours_completed' => 74.00,
            'status' => 'Live',
        ]);

        // 6. Seed all 50 tasks for Learner One from fixture.json
        $fixturePath = base_path('../GTA_Portal_v1.2.6_Complete_Developer_Pack/source-project/source/fixture.json');
        if (File::exists($fixturePath)) {
            $fixture = json_decode(File::get($fixturePath), true);
            $tasks = $fixture['tasks'] ?? [];

            foreach ($tasks as $task) {
                $status = $task['status'] ?? 'Not Started';
                $submittedAt = null;

                // Simulate Block 1 Task 4 as Submitted 10 days ago (Triggers 7-day Overdue SLA)
                if ($task['block'] === 1 && $task['task'] === 4) {
                    $status = 'Submitted';
                    $submittedAt = Carbon::now()->subDays(10);
                }

                ApprenticeTask::firstOrCreate(
                    [
                        'apprentice_id' => $apprenticeOne->apprentice_id,
                        'ablk_id' => $task['block'],
                        'task_num' => $task['task'],
                    ],
                    [
                        'task_type' => $task['title'],
                        'theme' => $task['theme'] ?? null,
                        'status' => $status,
                        'progress' => $status === 'Completed' ? 100.00 : ($status === 'Submitted' ? 50.00 : 0.00),
                        'submitted_at' => $submittedAt,
                    ]
                );
            }

            // 7. Seed Functional Skills (Maths & English Topics)
            $fsTopics = $fixture['fs'] ?? [];
            foreach ($fsTopics as $fs) {
                ApprenticeFunctionalSkill::firstOrCreate(
                    [
                        'apprentice_id' => $apprenticeOne->apprentice_id,
                        'subject' => $fs['subject'],
                        'topic' => $fs['topic'],
                    ],
                    [
                        'area' => $fs['area'] ?? 'Functional Skills',
                        'planned_hours' => (float) ($fs['hours'] ?? 0.0),
                        'status' => $fs['status'] ?? 'Not Started',
                        'completion_date' => ($fs['status'] ?? '') === 'Completed' ? Carbon::now()->subMonths(1) : null,
                    ]
                );
            }

            // 8. Seed Mandatory Qualifications (Refrigerant Handling & EV)
            $quals = $fixture['quals'] ?? [];
            foreach ($quals as $q) {
                ApprenticeMandatoryQual::firstOrCreate(
                    [
                        'apprentice_id' => $apprenticeOne->apprentice_id,
                        'qualification_name' => $q['name'],
                    ],
                    [
                        'awarding_body' => 'IMI',
                        'status' => $q['status'] ?? 'Not Started',
                        'custom_start_date' => Carbon::now()->subMonths(2),
                        'target_date' => Carbon::now()->addMonths(10),
                    ]
                );
            }
        }

        // 9. Seed SMART Action Plans & Reminders from action-reminders.js
        $actionPlanOne = ActionPlan::firstOrCreate([
            'apprentice_id' => $apprenticeOne->apprentice_id,
            'created_by_user_id' => $tutorAlex->id,
        ]);

        $actionPlanTwo = ActionPlan::firstOrCreate([
            'apprentice_id' => $apprenticeTwo->apprentice_id,
            'created_by_user_id' => $tutorSam->id,
        ]);

        // Reminders for Learner One
        ActionPlanItem::firstOrCreate([
            'action_plan_id' => $actionPlanOne->action_plan_id,
            'action_description' => 'Complete the practice questions',
            'owner_role' => 'Apprentice',
            'target_date' => Carbon::today()->addDays(7),
            'status' => 'To do',
        ]);

        ActionPlanItem::firstOrCreate([
            'action_plan_id' => $actionPlanOne->action_plan_id,
            'action_description' => 'Check the agreed practice answers',
            'owner_role' => 'Tutor',
            'target_date' => Carbon::today()->subDay(), // Overdue by 1 day!
            'status' => 'To do',
        ]);

        ActionPlanItem::firstOrCreate([
            'action_plan_id' => $actionPlanOne->action_plan_id,
            'action_description' => 'Provide two supervised workplace practice activities',
            'owner_role' => 'Employer / workplace mentor',
            'target_date' => Carbon::today(), // Due today!
            'status' => 'To do',
        ]);

        // Reminders for Learner Two
        ActionPlanItem::firstOrCreate([
            'action_plan_id' => $actionPlanTwo->action_plan_id,
            'action_description' => 'Confirm the agreed review arrangement',
            'owner_role' => 'Learning & Progress Mentor',
            'target_date' => Carbon::today(),
            'status' => 'To do',
        ]);

        ActionPlanItem::firstOrCreate([
            'action_plan_id' => $actionPlanTwo->action_plan_id,
            'action_description' => 'Complete the agreed homework questions',
            'owner_role' => 'Apprentice',
            'target_date' => Carbon::today()->subDay(), // Overdue!
            'status' => 'To do',
        ]);

        $this->command->info('Fictional apprentices, tasks, functional skills, quals, and SMART action reminders seeded successfully.');
    }
}
