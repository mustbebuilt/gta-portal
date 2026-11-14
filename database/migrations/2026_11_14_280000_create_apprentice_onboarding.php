<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('apprentice_invitation_bindings',function(Blueprint $t):void {
   $t->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
   $t->foreignId('apprentice_id')->unique()->constrained()->restrictOnDelete();
   $t->char('identity_hash',64);$t->timestamp('created_at');
  });
  Schema::create('apprentice_onboarding_events',function(Blueprint $t):void {
   $t->id();$t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
   $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
   $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
   $t->string('event',40);$t->text('identity_reference');$t->timestamp('created_at');
  });
  Schema::create('apprentice_start_checks',function(Blueprint $t):void {
   $t->id();$t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
   $t->foreignId('user_id')->constrained()->restrictOnDelete();$t->string('item',40);
   $t->unsignedInteger('version')->default(1);$t->timestamp('confirmed_at')->nullable();$t->timestamps();
   $t->unique(['apprentice_id','user_id','item']);
  });
  Schema::create('apprentice_start_check_events',function(Blueprint $t):void {
   $t->id();$t->foreignId('check_id')->constrained('apprentice_start_checks')->restrictOnDelete();
   $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->boolean('confirmed');
   $t->unsignedInteger('check_version');$t->timestamp('created_at');
  });
 }
 public function down():void {
  Schema::dropIfExists('apprentice_start_check_events');Schema::dropIfExists('apprentice_start_checks');
  Schema::dropIfExists('apprentice_onboarding_events');Schema::dropIfExists('apprentice_invitation_bindings');
 }
};
