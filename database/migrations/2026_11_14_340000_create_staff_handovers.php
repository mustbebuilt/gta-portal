<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('staff_handover_batches',function(Blueprint $t):void {$t->uuid('id')->primary();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->foreignId('target_user_id')->constrained('users')->restrictOnDelete();$t->foreignId('from_user_id')->nullable()->constrained('users')->restrictOnDelete();$t->string('slot',32);$t->string('state',16)->default('Pending');$t->unsignedInteger('version')->default(1);$t->longText('source');$t->char('source_hash',64);$t->longText('receipt')->nullable();$t->longText('review_note')->nullable();$t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamp('reviewed_at')->nullable();$t->timestamp('expires_at');$t->timestamps();$t->index(['target_user_id','state']);});
  Schema::create('staff_handover_acknowledgements',function(Blueprint $t):void {$t->id();$t->foreignUuid('batch_id')->constrained('staff_handover_batches')->restrictOnDelete();$t->foreignId('user_id')->constrained('users')->restrictOnDelete();$t->char('scope_hash',64);$t->unsignedInteger('version')->default(1);$t->timestamp('acknowledged_at')->nullable();$t->longText('proof')->nullable();$t->timestamps();$t->unique(['batch_id','user_id']);});
  Schema::create('staff_handover_events',function(Blueprint $t):void {$t->id();$t->foreignUuid('batch_id')->constrained('staff_handover_batches')->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',40);$t->unsignedInteger('record_version');$t->longText('payload');$t->timestamp('created_at');});
 }
 public function down():void {Schema::dropIfExists('staff_handover_events');Schema::dropIfExists('staff_handover_acknowledgements');Schema::dropIfExists('staff_handover_batches');}
};
