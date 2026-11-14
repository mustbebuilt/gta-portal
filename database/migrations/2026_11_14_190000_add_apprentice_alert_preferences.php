<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::table('apprentices',function(Blueprint $t):void {
  $t->string('alert_channel',10)->nullable();$t->boolean('alerts_enabled')->nullable();$t->unsignedInteger('alert_revision')->default(0);$t->json('alert_history')->nullable();$t->text('alert_mobile')->nullable();$t->timestamp('alert_mobile_verified_at')->nullable();
 });}
 public function down():void {Schema::table('apprentices',fn(Blueprint $t)=>$t->dropColumn(['alert_channel','alerts_enabled','alert_revision','alert_history','alert_mobile','alert_mobile_verified_at']));}
};
