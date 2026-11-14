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
        $programme = Programme::create(['standard_code' => 'ST0499', 'version' => '1.3', 'name' => 'Autocare Technician Level 2 — fictional demonstration', 'level' => 2]);
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
    DB::transaction(function () use ($user, $password): void {
        $fresh = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
        \App\Support\AccountAccess::setPassword($fresh, $password, null, 'local_command');
    });
    $this->line('New local test password: '.$password);
    $this->warn('Keep it locally. No message was sent; role and active status are unchanged.');

    return 0;
})->purpose('Generate a random password for an existing fictional local account only');

Artisan::command('gta:evidence-check', function () {
    try {
        $current = \App\Support\PrivateEvidence::diskName();
        $legacy = \App\Support\PrivateEvidence::diskName([]);
        foreach (array_unique([$current, $legacy, ...config('evidence.private_disks', [])]) as $disk) {
            \App\Support\PrivateEvidence::diskName(['disk' => $disk]);
            $driver = config('filesystems.disks.'.$disk.'.driver');
            if ($driver === 's3' && ! class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)) {
                throw new \RuntimeException('The configured S3 evidence disk requires the Flysystem AWS S3 adapter in this application.');
            }
        }
        $this->info('Private evidence configuration passed.');
        $this->line('New uploads: '.$current.'; legacy files without disk metadata: '.$legacy.'.');
        $this->line('No network or bucket-visibility check was performed. Test actual storage and authorised downloads in staging.');
        return 0;
    } catch (\Throwable $error) {
        $this->error($error->getMessage());
        return 1;
    }
})->purpose('Check evidence disk configuration without accessing private files or credentials');

Artisan::command('gta:marking-reminders {--record : Record deduplicated in-portal reminders}', function () {
    $result = \App\Support\MarkingReminders::generate((bool) $this->option('record'));
    $this->line(json_encode(['mode' => $this->option('record') ? 'record' : 'preview', ...$result, 'email_sent' => false], JSON_THROW_ON_ERROR));
    return 0;
})->purpose('Preview or record current overdue marking reminders; never sends email');

Artisan::command('gta:portal-reminders {--record : Persist deduplicated in-portal marking, careers and review prompts}', function () {
    $record=(bool)$this->option('record');
    $run=function() use($record): array {
        $counts=['marking'=>\App\Support\MarkingReminders::generate($record),'careers'=>\App\Support\CareersPrompts::generate($record),'reviews'=>\App\Support\ReviewReminders::generate($record)];
        if($record){DB::table('portal_reminder_runs')->insert(['counts'=>json_encode($counts,JSON_THROW_ON_ERROR),'created_at'=>now()]);}
        return $counts;
    };
    $counts=$record ? DB::transaction($run) : $run();
    $this->line(json_encode(['mode'=>$record?'record':'preview','counts'=>$counts,'external_messages_sent'=>false],JSON_THROW_ON_ERROR));
    return 0;
})->purpose('Preview or record source-bound marking/careers/review prompts without external messages');

\Illuminate\Support\Facades\Schedule::command('gta:portal-reminders --record')
    ->dailyAt('09:00')->timezone(config('app.timezone'))->withoutOverlapping(30)
    ->when(fn()=>config('portal_reminders.scheduled',false));

Artisan::command('gta:cloud-check {--database : Probe the configured staging database read-only}', function () {
    $result=\App\Support\CloudReadiness::inspect((bool)$this->option('database'));
    $this->line(json_encode($result,JSON_THROW_ON_ERROR));
    return $result['blocking_checks'] ? 1 : 0;
})->purpose('Report Cloud configuration blockers without changing records, files or environment values');

Artisan::command('gta:initial-admin {--create : Create once on an empty staging application} {--name= : Administrator display name} {--email= : Administrator email} {--password-stdin : Read bounded JSON credentials from private standard input}', function () {
    try {
        if(!app()->environment('staging')){$this->error('Initial administrator setup is restricted to staging.');return 1;}
        $preview=\App\Support\InitialAdmin::preview();
        if(!$this->option('create')){$this->line(json_encode(['mode'=>'preview',...$preview],JSON_THROW_ON_ERROR));return $preview['available']?0:1;}
        if(!$preview['available']){$this->error('Initial setup is disabled, already used or the application is not empty. No account created.');return 1;}
        $name=$this->option('name');$email=$this->option('email');
        if(!$name || !$email){$this->error('Supply --name and --email. No account created.');return 1;}
        if($this->option('password-stdin')){
            $stream=fopen('php://stdin','rb');try{$input=stream_get_contents($stream,2049);}finally{fclose($stream);}
            $credentials=\App\Support\InitialAdmin::credentialsFromJson($input);
            $password=$credentials['password'];$confirmation=$credentials['password_confirmation'];
        }else{
            if(!$this->input->isInteractive()){$this->error('Use a private interactive terminal or private JSON standard input. Password arguments are not accepted.');return 1;}
            $password=$this->secret('Administrator password',false);$confirmation=$this->secret('Confirm administrator password',false);
        }
        $user=\App\Support\InitialAdmin::create((string)$name,(string)$email,(string)$password,(string)$confirmation);
        $this->info('Initial staging administrator created. Account ID '.$user->id.'. No email sent; verification status was not inferred. Disable GTA_ALLOW_INITIAL_ADMIN after setup.');
        return 0;
    }catch(\Throwable){$this->error('No account created. Check the staging configuration, empty application, input and audit/migration state privately. No password is printed.');return 1;}
})->purpose('Preview or create the first staging administrator without exposing credentials or overwriting existing accounts');

Artisan::command('gta:evidence-storage {--probe : Explicitly write, hash-read, anonymously check and delete a fictional temporary S3 object}', function () {
    $result=$this->option('probe')?\App\Support\EvidenceStorage::probe():\App\Support\EvidenceStorage::inspect();
    $this->line(json_encode($result,JSON_THROW_ON_ERROR));
    return in_array($result['result'],['configuration_present','passed'],true)?0:1;
})->purpose('Inspect evidence configuration by default; explicit nonproduction probe uses synthetic bytes and creates no learner records');

Artisan::command('gta:evidence-inventory {--verify-files : Explicitly read and SHA-256/size-check registered private files} {--details : Include internal record locators and issue codes, excluding paths, filenames and notes}', function () {
    try{$result=\App\Support\EvidenceInventory::run((bool)$this->option('verify-files'),(bool)$this->option('details'));}
    catch(\Throwable){$result=['result'=>'inventory_failed','writes_performed'=>false,'migration_authorised'=>false,'action'=>'Check the database/schema and private server configuration; no credential or source-document details are printed.'];}
    $this->line(json_encode($result,JSON_THROW_ON_ERROR));
    return in_array($result['result'],['references_scanned','verified_referenced_objects'],true)?0:1;
})->purpose('Inventory known upload references without moving files or changing records; explicit verification reads private bytes');

Artisan::command('gta:evidence-copy-plan {--source=} {--target=} {--record : Persist an exact preview in staging} {--expected-proposal=} {--reference=}', function () {
    try{
        $source=(string)$this->option('source');$target=(string)$this->option('target');
        if($this->option('record')){$plan=\App\Support\EvidenceCopies::prepare($source,$target,(string)$this->option('expected-proposal'),(string)$this->option('reference'));$result=\App\Support\EvidenceCopies::summary($plan);}
        else{$preview=\App\Support\EvidenceCopies::proposal($source,$target);$result=collect($preview)->except('private_manifest')->all()+['mode'=>'preview','file_reads'=>false,'writes_performed'=>false,'cutover_approved'=>false];}
        $this->line(json_encode($result,JSON_THROW_ON_ERROR));return 0;
    }catch(\Throwable){$this->line(json_encode(['result'=>'plan_needs_checks','action'=>'Recheck the inventory, approved distinct disks, current preview hash and staging reference. No files copied.']));return 1;}
})->purpose('Preview counts/hash without file I/O; explicitly record a protected staging manifest and technical audit');

Artisan::command('gta:evidence-copy {plan} {--copy : Explicitly execute a once-only staging copy plan} {--expected-version=} {--expected-hash=}', function () {
    try{
        $plan=\App\Models\EvidenceCopyPlan::findOrFail($this->argument('plan'));
        if($this->option('copy')){$plan=\App\Support\EvidenceCopies::execute($plan,(int)$this->option('expected-version'),(string)$this->option('expected-hash'));}
        $result=\App\Support\EvidenceCopies::summary($plan);$this->line(json_encode($result,JSON_THROW_ON_ERROR));
        return $result['integrity']==='intact' && (!$this->option('copy') || $plan->state==='Verified staging copies')?0:1;
    }catch(\Throwable){$this->line(json_encode(['result'=>'copy_needs_checks','action'=>'Inspect the retained plan/state/audit privately. Staging keys may remain after a failed/interrupted copy. Originals and application links are not changed.']));return 1;}
})->purpose('Inspect a protected plan by default; explicit opt-in staging execution copies without overwriting or cutting over');
