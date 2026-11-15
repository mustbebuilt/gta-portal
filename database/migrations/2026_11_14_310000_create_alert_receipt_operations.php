<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('alert_receipt_batches',function(Blueprint $t):void {
   $t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
   $t->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();$t->string('state',20)->default('Preview');$t->unsignedInteger('version')->default(1);
   $t->longText('rows');$t->char('rows_hash',64);$t->text('source_reference');$t->text('review_note')->nullable();$t->timestamp('checked_at')->nullable();$t->timestamp('withdrawn_at')->nullable();$t->timestamps();
  });
  Schema::create('alert_receipt_records',function(Blueprint $t):void {
   $t->uuid('id')->primary();$t->foreignUuid('batch_id')->constrained('alert_receipt_batches')->restrictOnDelete();
   $t->foreignUuid('delivery_id')->constrained('apprentice_alert_deliveries')->restrictOnDelete();$t->string('outcome',16);$t->timestamp('occurred_at');
   $t->string('evidence_kind',24);$t->text('source_reference');$t->char('attempt_hash',64);$t->char('fingerprint',64)->index();$t->char('slot_key',64)->nullable()->unique();
   $t->timestamp('withdrawn_at')->nullable();$t->timestamp('created_at');$t->index(['delivery_id','occurred_at']);
  });
  Schema::create('alert_followup_cases',function(Blueprint $t):void {
   $t->id();$t->foreignUuid('delivery_id')->unique()->constrained('apprentice_alert_deliveries')->restrictOnDelete();
   $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();$t->date('due_on')->nullable();$t->string('state',20)->default('Open');
   $t->unsignedInteger('version')->default(1);$t->longText('proposal')->nullable();$t->longText('resolution')->nullable();
   $t->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();$t->timestamp('closed_at')->nullable();$t->timestamps();
  });
  Schema::create('alert_receipt_events',function(Blueprint $t):void {
   $t->id();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->foreignUuid('batch_id')->nullable()->constrained('alert_receipt_batches')->restrictOnDelete();
   $t->foreignUuid('delivery_id')->nullable()->constrained('apprentice_alert_deliveries')->restrictOnDelete();$t->foreignId('case_id')->nullable()->constrained('alert_followup_cases')->restrictOnDelete();
   $t->string('event',40);$t->unsignedInteger('record_version');$t->text('private_note')->nullable();$t->timestamp('created_at');
  });
 }
 public function down():void {Schema::dropIfExists('alert_receipt_events');Schema::dropIfExists('alert_followup_cases');Schema::dropIfExists('alert_receipt_records');Schema::dropIfExists('alert_receipt_batches');}
};
