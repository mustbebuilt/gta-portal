<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('staging_acceptance_runs',function(Blueprint $t):void {
   $t->uuid('id')->primary();$t->foreignUuid('parent_id')->nullable()->constrained('staging_acceptance_runs')->restrictOnDelete();
   $t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->string('state',16)->default('Open');$t->unsignedInteger('version')->default(1);
   $t->longText('details');$t->char('details_hash',64);$t->longText('source_snapshot');$t->char('source_hash',64);$t->longText('receipt')->nullable();
   $t->foreignId('accepted_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamp('accepted_at')->nullable();$t->timestamps();
  });
  Schema::create('staging_acceptance_checks',function(Blueprint $t):void {
   $t->id();$t->foreignUuid('run_id')->constrained('staging_acceptance_runs')->restrictOnDelete();$t->string('scenario_id',64);
   $t->string('outcome',16);$t->string('review_state',16)->default('Awaiting check');$t->timestamp('tested_at');
   $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();$t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
   $t->longText('details');$t->char('details_hash',64);$t->text('review_note')->nullable();$t->longText('review_proof')->nullable();
   $t->unsignedInteger('version')->default(1);$t->timestamp('reviewed_at')->nullable();$t->timestamps();$t->unique(['run_id','scenario_id']);
  });
  Schema::create('staging_acceptance_issues',function(Blueprint $t):void {
   $t->uuid('id')->primary();$t->foreignUuid('run_id')->constrained('staging_acceptance_runs')->restrictOnDelete();
   $t->foreignUuid('parent_id')->nullable()->constrained('staging_acceptance_issues')->restrictOnDelete();$t->string('scenario_id',64)->nullable();
   $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();$t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
   $t->string('severity',16);$t->string('state',20)->default('Open');$t->date('due_on')->nullable();$t->longText('details');$t->char('details_hash',64);
   $t->longText('proposal')->nullable();$t->longText('review_proof')->nullable();$t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
   $t->timestamp('reviewed_at')->nullable();$t->unsignedInteger('version')->default(1);$t->timestamps();
  });
  Schema::create('staging_acceptance_events',function(Blueprint $t):void {
   $t->id();$t->foreignUuid('run_id')->constrained('staging_acceptance_runs')->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
   $t->string('event',40);$t->unsignedInteger('record_version');$t->longText('payload');$t->timestamp('created_at');
  });
 }
 public function down():void {Schema::withoutForeignKeyConstraints(function():void {Schema::dropIfExists('staging_acceptance_events');Schema::dropIfExists('staging_acceptance_issues');Schema::dropIfExists('staging_acceptance_checks');Schema::dropIfExists('staging_acceptance_runs');});}
};
