<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('apprentice_entry_contexts',function(Blueprint $t):void {
   $t->id(); $t->foreignId('apprentice_id')->unique()->constrained()->restrictOnDelete();
   $t->foreignId('entry_employer_id')->nullable()->constrained('employers')->restrictOnDelete();
   foreach(['recruitment_route','employment_route','family_owned','family_owner_manager','family_supervisor','relationship','already_employed','work_experience','prior_apprenticeship','career_match'] as $field){$t->string($field,100)->nullable();}
   $t->date('employment_start_date')->nullable(); $t->string('referral_source',160)->nullable(); $t->string('notes',500)->nullable();
   $t->string('stage',20)->default('Application');$t->date('confirmed_on')->nullable();
   $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();$t->unsignedInteger('version')->default(1);$t->timestamps();
  });
  Schema::create('entry_context_events',function(Blueprint $t):void {
   $t->id();$t->foreignId('apprentice_id')->constrained()->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
   $t->string('kind',20);$t->date('effective_on')->nullable();$t->unsignedInteger('revision');$t->longText('payload');$t->timestamps();
   $t->index(['apprentice_id','kind','id'],'entry_person_kind_idx');
  });
  Schema::create('destination_milestones',function(Blueprint $t):void {
   $t->id();$t->foreignId('apprentice_id')->constrained()->restrictOnDelete();$t->date('exit_on');$t->unsignedTinyInteger('months');$t->date('due_on');
   $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();$t->string('state',20)->default('Open');$t->date('retry_on')->nullable();
   $t->unsignedInteger('version')->default(1);$t->timestamps();$t->unique(['apprentice_id','exit_on','months'],'dest_person_exit_month_uniq');$t->index(['state','due_on','owner_id'],'dest_state_due_owner_idx');
  });
  Schema::create('destination_milestone_checks',function(Blueprint $t):void {
   $t->id();$t->foreignId('milestone_id')->constrained('destination_milestones')->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
   $t->date('observed_on');$t->string('result',20);$t->string('destination',50);$t->string('basis',20);$t->string('continuity',30);
   $t->date('continuous_from')->nullable();$t->date('retry_on')->nullable();$t->longText('source');$t->timestamps();$t->index(['milestone_id','id'],'dest_check_history_idx');
  });
  Schema::create('retention_report_exports',function(Blueprint $t):void {$t->id();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->unsignedInteger('cohort_count');$t->json('filters');$t->timestamp('created_at');});
  Schema::table('support_plans',function(Blueprint $t):void {$t->timestamp('closed_at')->nullable();});
  Schema::create('retention_admin_assignments',function(Blueprint $t):void {
   $t->id();$t->foreignId('apprentice_id')->unique()->constrained()->restrictOnDelete();$t->foreignId('owner_id')->constrained('users')->restrictOnDelete();$t->date('due_on');$t->unsignedInteger('version')->default(1);$t->timestamps();
  });
 }
 public function down(): void {Schema::table('support_plans',fn(Blueprint $t)=>$t->dropColumn('closed_at')); foreach(['retention_report_exports','retention_admin_assignments','destination_milestone_checks','destination_milestones','entry_context_events','apprentice_entry_contexts'] as $table){Schema::dropIfExists($table);}}
};
