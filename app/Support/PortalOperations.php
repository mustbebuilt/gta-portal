<?php
namespace App\Support;
use App\Models\User;
use Illuminate\Support\Facades\{DB,Crypt};
class PortalOperations {
 public static function actor(User $actor,int $auth,bool $manager=false):User {$u=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();abort_unless($u->active&&!AccountLifecycle::blocked($u)&&$u->auth_version===$auth&&(!$manager||LearningAccess::manager($u)),403);return $u;}
 public static function event(User $u,string $type,string $key,string $event,array $payload):void {DB::table('portal_operations_events')->insert(['actor_id'=>$u->id,'subject_type'=>$type,'subject_key'=>$key,'event'=>$event,'payload'=>Crypt::encryptString(json_encode($payload,JSON_THROW_ON_ERROR)),'created_at'=>now()]);}
 public static function reference(string $value):bool {return mb_strlen(trim($value))>=5&&mb_strlen($value)<=2000;}
}
