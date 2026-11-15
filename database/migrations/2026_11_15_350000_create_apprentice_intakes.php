<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('apprentice_intake_batches',function(Blueprint $t):void {$t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->string('state',16)->default('Pending');$t->unsignedInteger('version')->default(1);$t->longText('source');$t->char('source_hash',64);$t->longText('receipt')->nullable();$t->longText('review_note')->nullable();$t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamp('reviewed_at')->nullable();$t->timestamp('expires_at');$t->timestamps();});
  Schema::create('apprentice_intake_events',function(Blueprint $t):void {$t->id();$t->foreignUuid('batch_id')->constrained('apprentice_intake_batches')->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',40);$t->unsignedInteger('record_version');$t->longText('payload');$t->timestamp('created_at');});
  Schema::create('apprentice_setup_cases',function(Blueprint $t):void {$t->uuid('id')->primary();$t->foreignId('apprentice_id')->constrained('apprentices')->restrictOnDelete();$t->string('issue',32);$t->string('state',16)->default('Open');$t->unsignedInteger('version')->default(1);$t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();$t->date('due_on');$t->longText('details');$t->char('details_hash',64);$t->char('source_hash',64);$t->longText('proof')->nullable();$t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamp('decided_at')->nullable();$t->timestamps();$t->unique(['apprentice_id','issue']);});
  Schema::create('apprentice_setup_case_events',function(Blueprint $t):void {$t->id();$t->foreignUuid('case_id')->constrained('apprentice_setup_cases')->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',32);$t->longText('payload');$t->timestamp('created_at');});

 }
 public function down():void {Schema::dropIfExists('apprentice_setup_case_events');Schema::dropIfExists('apprentice_setup_cases');Schema::dropIfExists('apprentice_intake_events');Schema::dropIfExists('apprentice_intake_batches');}
};
