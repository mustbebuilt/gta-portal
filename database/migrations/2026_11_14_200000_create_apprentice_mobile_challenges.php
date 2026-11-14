<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('apprentice_mobile_challenges',function(Blueprint $t):void {
  $t->uuid('id')->primary();$t->foreignId('user_id')->constrained()->restrictOnDelete();$t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
  $t->char('phone_hash',64)->index();$t->char('session_hash',64);$t->char('config_hash',64);$t->unsignedInteger('preference_revision');$t->unsignedInteger('auth_version');
  $t->string('code_hash')->nullable();$t->string('state',20);$t->unsignedInteger('attempts')->default(0);$t->string('provider_id',64)->nullable();$t->timestamp('expires_at');$t->timestamps();$t->index(['user_id','created_at']);
 });}
 public function down():void {Schema::dropIfExists('apprentice_mobile_challenges');}
};
