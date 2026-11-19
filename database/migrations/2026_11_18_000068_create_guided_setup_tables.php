<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up():void {
  Schema::create('programme_setup_templates68',function(Blueprint $t){$t->id();$t->foreignId('programme_id')->unique()->constrained('programmes')->restrictOnDelete();$t->json('template');$t->char('source_hash',64);$t->unsignedInteger('version')->default(1);$t->boolean('active')->default(false);$t->timestamps();});
  Schema::create('setup_template_events68',function(Blueprint $t){$t->id();$t->foreignId('template_id')->constrained('programme_setup_templates68')->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->unsignedInteger('version');$t->char('source_hash',64);$t->timestamp('created_at');});
  Schema::create('apprentice_setup_drafts68',function(Blueprint $t){$t->uuid('id')->primary();$t->foreignId('owner_id')->constrained('users')->restrictOnDelete();$t->foreignId('apprentice_id')->nullable()->unique()->constrained('apprentices')->restrictOnDelete();$t->longText('payload');$t->longText('history');$t->unsignedInteger('version')->default(1);$t->string('state',20)->default('Draft');$t->timestamps();});
  Schema::create('employer_requests68',function(Blueprint $t){$t->uuid('token')->primary();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->foreignId('employer_id')->constrained('employers')->restrictOnDelete();$t->char('payload_hash',64);$t->longText('duplicate_reason')->nullable();$t->timestamp('created_at');});
  Schema::create('setup68_locks',function(Blueprint $t){$t->string('key',30)->primary();});DB::table('setup68_locks')->insert(['key'=>'commit']);
  Schema::table('apprentices',fn(Blueprint $t)=>$t->string('inclusion_status68',12)->nullable());
  Schema::table('training_plan_workspaces',fn(Blueprint $t)=>$t->longText('setup_rpl')->nullable());
 }
 public function down():void {Schema::table('apprentices',fn(Blueprint $t)=>$t->dropColumn('inclusion_status68')); Schema::table('training_plan_workspaces',fn(Blueprint $t)=>$t->dropColumn('setup_rpl'));Schema::dropIfExists('apprentice_setup_drafts68');Schema::dropIfExists('setup_template_events68');Schema::dropIfExists('programme_setup_templates68');Schema::dropIfExists('setup68_locks');Schema::dropIfExists('employer_requests68');}
};
