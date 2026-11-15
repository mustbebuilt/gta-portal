<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB,Crypt};
return new class extends Migration {
 public function up():void {
 Schema::create('portal_privacy_notices',function(Blueprint $t):void {$t->uuid('id')->primary();$t->string('label',100)->unique();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->string('state',16);$t->longText('body');$t->char('body_hash',64);$t->longText('publication')->nullable();$t->timestamps();});
 Schema::create('portal_privacy_state',function(Blueprint $t):void {$t->unsignedTinyInteger('id')->primary();$t->unsignedInteger('revision');$t->foreignUuid('current_notice_id')->nullable()->constrained('portal_privacy_notices')->restrictOnDelete();$t->boolean('enabled')->default(false);$t->longText('proof');$t->timestamps();});
 Schema::create('portal_privacy_acknowledgements',function(Blueprint $t):void {$t->id();$t->foreignId('user_id')->constrained('users')->restrictOnDelete();$t->foreignUuid('notice_id')->constrained('portal_privacy_notices')->restrictOnDelete();$t->char('body_hash',64);$t->longText('proof');$t->timestamp('acknowledged_at');$t->unique(['user_id','notice_id']);});
 Schema::create('portal_privacy_cases',function(Blueprint $t):void {$t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('user_id')->constrained('users')->restrictOnDelete();$t->foreignId('open_user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();$t->foreignUuid('notice_id')->constrained('portal_privacy_notices')->restrictOnDelete();$t->string('state',24);$t->unsignedInteger('version');$t->date('review_on')->nullable();$t->longText('details');$t->timestamps();});
 Schema::create('portal_privacy_events',function(Blueprint $t):void {$t->id();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',40);$t->string('subject_key',100);$t->longText('payload');$t->timestamp('created_at');});
 DB::table('portal_privacy_state')->insert(['id'=>1,'revision'=>1,'current_notice_id'=>null,'enabled'=>false,'proof'=>Crypt::encryptString(json_encode(['id'=>1,'revision'=>1,'current_notice_id'=>null,'enabled'=>false])),'created_at'=>now(),'updated_at'=>now()]);
 }
 public function down():void {foreach(['portal_privacy_events','portal_privacy_cases','portal_privacy_acknowledgements','portal_privacy_state','portal_privacy_notices']as$t){Schema::dropIfExists($t);}}
};
