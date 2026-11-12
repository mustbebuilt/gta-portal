<?php

use App\Models\Apprentice;
use App\Models\Employer;
use App\Models\Programme;
use App\Models\TutorGroup;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('gta:demo', function () {
    if (! app()->environment('local')) {
        $this->error('Fictional demo setup is available only in the local environment.');

        return 1;
    }
    if (User::exists() || Apprentice::exists() || Employer::exists() || Programme::exists() || TutorGroup::exists()) {
        $this->error('Records already exist. Setup stopped without changing them.');

        return 1;
    }
    $password = Str::password(24);
    DB::transaction(function () use ($password) {
        $make = fn ($name, $email, $role) => tap(new User(['name' => $name, 'email' => $email, 'password' => $password]), function ($user) use ($role) {
            $user->role = $role;
            $user->save();
        });
        $make('Demo Administrator', 'admin@example.test', 'admin');
        $make('Demo CEO', 'ceo@example.test', 'ceo');
        $make('Demo Inclusion Lead', 'inclusion-lead@example.test', 'inclusion_lead');
        $make('Demo Inclusion Team Member', 'inclusion@example.test', 'inclusion_member');
        $tutor = $make('Demo Tutor', 'tutor@example.test', 'tutor');
        $learner = $make('Demo Apprentice Account', 'apprentice@example.test', 'apprentice');
        $mentor = $make('Demo Progress Mentor', 'mentor@example.test', 'mentor');
        $careers = $make('Demo Careers Adviser', 'careers@example.test', 'careers_adviser');
        $maths = $make('Demo Maths Tutor', 'maths@example.test', 'functional_skills_tutor');
        $maths->subject_scope = 'maths';
        $maths->save();
        $english = $make('Demo English Tutor', 'english@example.test', 'functional_skills_tutor');
        $english->subject_scope = 'english';
        $english->save();
        $employer = Employer::create(['reference' => 'DEMO-EMP', 'name' => 'Demo Workshop']);
        $programme = Programme::create(['standard_code' => 'DEMO-ONLY', 'version' => 'fictional', 'name' => 'Fictional demonstration programme', 'level' => 2]);
        $group = TutorGroup::create(['reference' => 'DEMO-GROUP', 'name' => 'Demo Tutor Group', 'programme_id' => $programme->id, 'tutor_id' => $tutor->id]);
        $first = Apprentice::create(['reference' => 'DEMO-001', 'first_name' => 'Demo', 'last_name' => 'Apprentice One', 'programme' => 'Autocare Technician Level 2 — fictional allocation', 'employer' => 'Demo Workshop', 'tutor_id' => $tutor->id, 'employer_id' => $employer->id, 'programme_id' => $programme->id, 'tutor_group_id' => $group->id, 'mentor_id' => $mentor->id, 'careers_adviser_id' => $careers->id, 'maths_tutor_id' => $maths->id, 'english_tutor_id' => $english->id]);
        $first->user_id = $learner->id;
        $first->save();
        Apprentice::create(['reference' => 'DEMO-002', 'first_name' => 'Demo', 'last_name' => 'Apprentice Two']);
    });
    $this->info('Created two fictional apprentices, ten local accounts and linked demonstration directories. No messages sent.');
    $this->line('Administrator: admin@example.test');
    $this->line('Tutor: tutor@example.test');
    $this->line('Apprentice: apprentice@example.test');
    $this->line('Maths and English: maths@example.test, english@example.test');
    $this->line('Careers: careers@example.test; Inclusion: inclusion-lead@example.test, inclusion@example.test; CEO: ceo@example.test');
    $this->line('Mentor record (review module pending): mentor@example.test');
    $this->line('Local test password for these accounts: '.$password);
    $this->warn('Keep this password locally. Never use this database for real apprentice information.');

    return 0;
})->purpose('Create fictional records in an empty local database');

Artisan::command('gta:local-password {email}', function () {
    if (! app()->environment('local')) {
        $this->error('Password reset is restricted to this local fictional test copy.');

        return 1;
    }
    $user = User::where('email', $this->argument('email'))->first();
    if (! $user) {
        $this->error('No matching local account. No changes made.');

        return 1;
    }
    $password = Str::password(24);
    $user->password = $password;
    $user->record_version++;
    $user->save();
    $this->line('New local test password: '.$password);
    $this->warn('Keep it locally. No message was sent; role and active status are unchanged.');

    return 0;
})->purpose('Generate a random password for an existing fictional local account only');
