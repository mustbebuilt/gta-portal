<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('timetable_change_batches',function(Blueprint $t):void {$t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->foreignId('tutor_group_id')->constrained('tutor_groups')->restrictOnDelete();$t->string('mode',16);$t->string('state',16)->default('Pending');$t->unsignedInteger('version')->default(1);$t->longText('source');$t->char('source_hash',64);$t->longText('receipt')->nullable();$t->longText('review_note')->nullable();$t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamp('reviewed_at')->nullable();$t->timestamp('expires_at');$t->timestamps();});
  Schema::create('timetable_change_events',function(Blueprint $t):void {$t->id();$t->foreignUuid('batch_id')->constrained('timetable_change_batches')->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',32);$t->unsignedInteger('record_version');$t->longText('payload');$t->timestamp('created_at');});
  Schema::create('register_followup_cases',function(Blueprint $t):void {$t->uuid('id')->primary();$t->foreignId('training_session_id')->unique()->constrained('training_sessions')->restrictOnDelete();$t->foreignId('owner_id')->constrained('users')->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->string('state',16)->default('Open');$t->unsignedInteger('version')->default(1);$t->longText('details');$t->char('details_hash',64);$t->char('source_hash',64);$t->longText('proof')->nullable();$t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamp('decided_at')->nullable();$t->date('due_on');$t->timestamps();});
  Schema::create('register_followup_events',function(Blueprint $t):void {$t->id();$t->foreignUuid('case_id')->constrained('register_followup_cases')->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',32);$t->longText('payload');$t->timestamp('created_at');});
 }
 public function down():void {Schema::dropIfExists('register_followup_events');Schema::dropIfExists('register_followup_cases');Schema::dropIfExists('timetable_change_events');Schema::dropIfExists('timetable_change_batches');}
};
