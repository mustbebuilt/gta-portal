<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('timetable_group_briefs',function(Blueprint $t):void {$t->foreignId('group_id')->primary()->constrained('tutor_groups')->restrictOnDelete();$t->unsignedInteger('version')->default(1);$t->foreignId('updated_by')->constrained('users')->restrictOnDelete();$t->longText('details');$t->char('details_hash',64);$t->timestamps();});
  Schema::create('timetable_flexible_days',function(Blueprint $t):void {$t->id();$t->foreignId('staff_id')->constrained('users')->restrictOnDelete();$t->date('work_date');$t->string('start_time',5);$t->string('end_time',5);$t->foreignId('owner_id')->constrained('users')->restrictOnDelete();$t->string('state',16);$t->unsignedInteger('version')->default(1);$t->longText('source');$t->char('source_hash',64);$t->timestamps();$t->unique(['staff_id','work_date']);});
  Schema::create('timetable_cover_allocations',function(Blueprint $t):void {$t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('entry_id')->constrained('timetable_plan_entries')->restrictOnDelete();$t->foreignId('active_entry_id')->nullable()->unique()->constrained('timetable_plan_entries')->restrictOnDelete();$t->foreignId('original_staff_id')->constrained('users')->restrictOnDelete();$t->foreignId('cover_staff_id')->constrained('users')->restrictOnDelete();$t->foreignId('flexible_day_id')->constrained('timetable_flexible_days')->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->string('state',16);$t->unsignedInteger('version')->default(1);$t->longText('source');$t->char('source_hash',64);$t->longText('check_proof')->nullable();$t->longText('publication')->nullable();$t->longText('closure')->nullable();$t->timestamps();});
  Schema::create('timetable_duty_events',function(Blueprint $t):void {$t->id();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',32);$t->string('subject_type',16);$t->string('subject_key',64);$t->unsignedInteger('record_version');$t->longText('payload');$t->timestamp('created_at');});
 }
 public function down():void {foreach(['timetable_duty_events','timetable_cover_allocations','timetable_flexible_days','timetable_group_briefs']as$table){Schema::dropIfExists($table);}}
};
