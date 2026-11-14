<?php
namespace Tests\Support;

use Illuminate\Support\Facades\DB;

class AuditFault
{
    // Test-only native database error, not application exception injection.
    public static function install(string $name,string $table,string $message,?string $event=null): void
    {
        foreach([$name,$table] as $identifier){if(!preg_match('/^[a-z_][a-z0-9_]*$/',$identifier)){throw new \InvalidArgumentException('Invalid fixture identifier');}}
        $quoted=DB::connection()->getPdo()->quote($message);
        if(DB::connection()->getDriverName()==='sqlite'){
            $when=$event===null?'':" WHEN NEW.event = ".DB::connection()->getPdo()->quote($event);
            DB::statement("CREATE TRIGGER $name BEFORE INSERT ON $table$when BEGIN SELECT RAISE(ABORT,$quoted); END");
        }elseif(in_array(DB::connection()->getDriverName(),['mysql','mariadb'],true)){
            $signal="SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT=$quoted;";
            if($event!==null){$signal='IF NEW.`event` = '.DB::connection()->getPdo()->quote($event).' THEN '.$signal.' END IF;';}
            DB::unprepared("CREATE TRIGGER `$name` BEFORE INSERT ON `$table` FOR EACH ROW BEGIN $signal END");
        }else{throw new \RuntimeException('Unsupported native audit-fixture engine.');}
    }
}
