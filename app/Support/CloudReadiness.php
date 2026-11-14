<?php

namespace App\Support;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;

class CloudReadiness
{
    // Read-only diagnostic. Never echoes environment values, credentials or database errors.
    public static function inspect(bool $database=false): array
    {
        $checks=[];
        $add=function(string $name,bool $pass,string $action) use(&$checks): void {$checks[]=['check'=>$name,'status'=>$pass?'pass':'blocked','action'=>$pass?'Configuration present; actual staging acceptance still required.':$action];};
        $add('php',version_compare(PHP_VERSION,'8.4.1','>='),'Select PHP 8.4.1+; the tested runtime is PHP 8.4.27.');
        $add('hosted_environment',app()->environment('staging'),'Use a separate Cloud staging environment; this local checkout is not a Cloud test.');
        $add('debug_disabled',config('app.debug')===false,'Set APP_DEBUG=false.');
        $key=(string)config('app.key');$rawKey=str_starts_with($key,'base64:')?base64_decode(substr($key,7),true):$key;$add('application_key',is_string($rawKey) && \Illuminate\Encryption\Encrypter::supported($rawKey,(string)config('app.cipher')),'Use the environment application key. Preserve any existing key; do not generate over an existing application.');
        $url=parse_url((string)config('app.url'));$add('https_url',($url['scheme']??null)==='https' && !empty($url['host']),'Set APP_URL to the actual HTTPS staging hostname.');
        $web=app(Kernel::class)->getMiddlewareGroups()['web']??[];
        $add('hosted_web_gate',in_array(\App\Http\Middleware\LocalTestOnly::class,$web,true) && StagingAccess::errors()===[],'Use APP_ENV=staging, explicitly enable staging access and satisfy its HTTPS/key/session/cache profile. Production access remains blocked; actual Cloud acceptance is separate.');
        $driver=config('database.default');$add('database_driver',in_array($driver,['mysql','mariadb'],true),'Confirm the target database. This release targets MySQL/MariaDB; SQLite tests do not verify it.');
        $add('pdo_mysql',extension_loaded('pdo_mysql'),'Enable the PDO MySQL extension in the selected Cloud PHP runtime.');
        $add('shared_sessions',in_array(config('session.driver'),['database','redis'],true),'Use database or Redis sessions, with matching provisioned resources/tables.');
        $add('secure_sessions',config('session.secure')===true && config('session.encrypt')===true,'Enable secure encrypted session cookies for the HTTPS environment.');
        $add('shared_cache',in_array(config('cache.default'),['database','redis'],true),'Use a shared database/Redis cache for rate limits and scheduler locks.');
        try{$disk=PrivateEvidence::diskName();$diskDriver=config('filesystems.disks.'.$disk.'.driver');$diskOk=$diskDriver==='s3';}catch(\Throwable){$diskOk=false;}
        $add('durable_private_evidence',$diskOk,'Attach a private S3-compatible bucket and configure/pin the permitted evidence disk; migrate and reconcile any earlier files.');
        $add('s3_adapter',class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class),'Restore the supplied locked S3 dependency with composer install; verify private bucket configuration and actual staging access.');
        $probe=['check'=>'database_connection_and_migrations','status'=>'not_checked','action'=>'Run with --database in the authorised staging environment for read-only checks.'];
        $schema=ApplicationSchema::unavailable();
        if($database) {
            if(!app()->environment('staging')) {
                $probe=['check'=>'database_connection_and_migrations','status'=>'blocked','action'=>'Database probing is restricted to the staging environment. No connection was opened.'];
                $schema=ApplicationSchema::unavailable('staging_required');
            } elseif(!in_array($driver,['mysql','mariadb'],true)) {
                $probe=['check'=>'database_connection_and_migrations','status'=>'blocked','action'=>'Choose the target MySQL/MariaDB connection before probing; no fallback SQLite connection was opened.'];
                $schema=ApplicationSchema::unavailable('configured_driver_not_supported');
            } else {
                try {
                    $connection=DB::connection();
                    if(!in_array($connection->getDriverName(),['mysql','mariadb'],true)) {
                        $probe=['check'=>'database_connection_and_migrations','status'=>'blocked','action'=>'The actual connection driver is not MySQL/MariaDB. The schema probe was refused.'];
                        $schema=ApplicationSchema::unavailable('actual_driver_not_supported');
                    } else {
                        $connection->select('select 1');$repo=app('migration.repository');
                        if(!$repo->repositoryExists()) { $ok=false; } else {
                            $files=app('migrator')->getMigrationFiles([database_path('migrations')]);
                            $ok=count(array_diff(array_keys($files),$repo->getRan()))===0;
                        }
                        $probe=['check'=>'database_connection_and_migrations','status'=>$ok?'pass':'blocked',
                            'action'=>$ok?'Connection and migration names checked read-only; inspect the separate required-schema check.':'Migration repository missing or migrations pending. Review staging migrations; none were run.'];
                        $schema=ApplicationSchema::inspect($connection);
                    }
                } catch (\Throwable) {
                    $probe=['check'=>'database_connection_and_migrations','status'=>'blocked','action'=>'Staging connection/metadata probe failed. Check credentials/network/privileges privately; no database change was attempted.'];
                    $schema=ApplicationSchema::unavailable('database_probe_failed');
                }
            }
        }
        $checks[]=$probe;
        $checks[]=['check'=>'required_application_schema','status'=>$schema['status'],
            'action'=>match($schema['reason']) {
                'required_names_present'=>'Required reference table/column names are present. Types, constraints, indexes, contents and full access are not certified.',
                'required_names_missing'=>'Required reference tables/columns are missing. Review the reported schema names and target mapping; no repair or migration was attempted.',
                'not_requested'=>'Run --database in staging to check required table/column names without changing records.',
                default=>'Schema presence was not established. Resolve the staging connection/metadata blocker privately; do not treat this as a schema pass.',
            }];
        $blocked=count(array_filter($checks,fn($c)=>$c['status']==='blocked'));
        return ['target'=>'Laravel Cloud staging','result'=>$blocked?'blocked':'configuration_checks_only','blocking_checks'=>$blocked,'checks'=>$checks,'schema_presence'=>$schema,'database_probe_requested'=>$database,'writes_performed'=>false,'deployment_performed'=>false,'limits'=>['This is a diagnostic, not deployment approval or actual Cloud acceptance.','Schema presence does not verify column types, indexes, foreign keys, data, migration contents or historical correctness.','Staging gate and one-time empty-database administrator bootstrap are implemented; actual Cloud access, storage, first real account, schema mapping, backups and target-engine/browser acceptance remain unverified.','Do not point this standalone application at an existing portal database without reviewed schema/source-ID mapping.']];
    }
}
