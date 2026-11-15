<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up():void {
  Schema::create('timetable_governance_state',function(Blueprint $t):void {$t->unsignedTinyInteger('id')->primary();$t->unsignedInteger('revision')->default(1);$t->boolean('editing_paused')->default(false);$t->longText('details')->nullable();$t->timestamps();});
  Schema::create('timetable_governance_roles',function(Blueprint $t):void {$t->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();$t->string('role',20);$t->boolean('restricted')->default(false);$t->boolean('ceo_locked')->default(false);$t->unsignedInteger('version')->default(1);$t->foreignId('changed_by')->constrained('users')->restrictOnDelete();$t->longText('history');$t->timestamps();});
  Schema::create('timetable_allocation_controls',function(Blueprint $t):void {$t->foreignUuid('plan_id')->primary()->constrained('timetable_plans')->restrictOnDelete();$t->unsignedInteger('version')->default(1);$t->boolean('restricted')->default(false);$t->boolean('ceo_locked')->default(false);$t->foreignId('changed_by')->constrained('users')->restrictOnDelete();$t->longText('details');$t->timestamps();});
  Schema::create('timetable_governance_events',function(Blueprint $t):void {$t->id();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',32);$t->string('subject_type',16);$t->string('subject_key',64);$t->unsignedInteger('record_version');$t->longText('payload');$t->timestamp('created_at');});

  Schema::create('timetable_staff_sections',function(Blueprint $t):void {$t->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();$t->string('section',12);$t->unsignedInteger('version')->default(1);$t->foreignId('changed_by')->constrained('users')->restrictOnDelete();$t->longText('details');$t->timestamps();});
  DB::table('timetable_governance_state')->insert(['id'=>1,'revision'=>1,'editing_paused'=>false,'created_at'=>now(),'updated_at'=>now()]);
 }
 public function down():void {foreach(['timetable_staff_sections','timetable_governance_events','timetable_allocation_controls','timetable_governance_roles','timetable_governance_state']as$t){Schema::dropIfExists($t);}}
};
