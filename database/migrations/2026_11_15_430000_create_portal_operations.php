<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
 Schema::create('account_lifecycle_proposals',function(Blueprint $t):void {$t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('target_id')->constrained('users')->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->string('action',20);$t->string('state',20);$t->unsignedInteger('version');$t->char('source_hash',64);$t->longText('details');$t->longText('approval')->nullable();$t->longText('applied')->nullable();$t->timestamps();});
 Schema::create('account_lifecycle_states',function(Blueprint $t):void {$t->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();$t->unsignedInteger('version');$t->string('status',20);$t->date('review_on');$t->longText('proof');$t->timestamps();});
 Schema::create('cohort_forecasts',function(Blueprint $t):void {$t->foreignId('apprentice_id')->primary()->constrained('apprentices')->restrictOnDelete();$t->unsignedInteger('version');$t->longText('details');$t->char('source_hash',64);$t->timestamps();});
 Schema::create('cohort_snapshots',function(Blueprint $t):void {$t->uuid('id')->primary();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->longText('body');$t->char('body_hash',64);$t->timestamps();});
 Schema::create('operational_followups',function(Blueprint $t):void {$t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('apprentice_id')->constrained('apprentices')->restrictOnDelete();$t->foreignId('owner_id')->constrained('users')->restrictOnDelete();$t->char('source_key',64);$t->string('source_kind',30);$t->string('status',20);$t->unsignedInteger('version');$t->char('open_key',64)->nullable()->unique();$t->longText('details');$t->timestamps();});
 Schema::create('portal_operations_events',function(Blueprint $t):void {$t->id();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('subject_type',24);$t->string('subject_key',100);$t->string('event',48);$t->longText('payload');$t->timestamp('created_at');});
 }
 public function down():void {foreach(['portal_operations_events','operational_followups','cohort_snapshots','cohort_forecasts','account_lifecycle_states','account_lifecycle_proposals']as$t){Schema::dropIfExists($t);}}
};
