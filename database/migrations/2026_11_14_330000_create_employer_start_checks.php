<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('employer_start_checks',function(Blueprint $t):void {$t->id();$t->foreignId('user_id')->constrained('users')->restrictOnDelete();$t->string('item',64);$t->char('scope_hash',64);$t->unsignedInteger('version')->default(1);$t->timestamp('confirmed_at')->nullable();$t->timestamps();$t->unique(['user_id','item']);});
  Schema::create('employer_start_events',function(Blueprint $t):void {$t->id();$t->foreignId('check_id')->nullable()->constrained('employer_start_checks')->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',40);$t->longText('payload');$t->timestamp('created_at');});
 }
 public function down():void {Schema::dropIfExists('employer_start_events');Schema::dropIfExists('employer_start_checks');}
};
