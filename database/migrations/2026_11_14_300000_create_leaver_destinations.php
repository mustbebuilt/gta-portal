<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('destination_contacts',function(Blueprint $t):void {
   $t->id();$t->uuid('request_token')->unique();$t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
   $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();$t->date('contacted_on');
   $t->string('method',20);$t->string('result',20);$t->date('next_followup_on')->nullable();
   $t->longText('detail');$t->longText('source');$t->char('source_hash',64);
   $t->timestamp('voided_at')->nullable();$t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
   $t->text('void_reason')->nullable();$t->unsignedInteger('version')->default(1);$t->timestamps();
  });
  Schema::create('destination_proposals',function(Blueprint $t):void {
   $t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
   $t->foreignId('outcome_id')->constrained('programme_outcomes')->restrictOnDelete();
   $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();$t->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();
   $t->string('state',20)->default('Pending');$t->unsignedInteger('version')->default(1);
   $t->longText('payload');$t->char('payload_hash',64);$t->longText('source');$t->char('source_hash',64);
   $t->longText('receipt')->nullable();$t->text('review_note')->nullable();$t->timestamp('checked_at')->nullable();
   $t->timestamp('withdrawn_at')->nullable();$t->timestamps();$t->index(['apprentice_id','state']);
  });
  Schema::create('destination_events',function(Blueprint $t):void {
   $t->id();$t->foreignId('apprentice_id')->constrained()->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
   $t->foreignId('contact_id')->nullable()->constrained('destination_contacts')->restrictOnDelete();
   $t->foreignUuid('proposal_id')->nullable()->constrained('destination_proposals')->restrictOnDelete();
   $t->string('event',40);$t->unsignedInteger('record_version');$t->timestamp('created_at');
  });
 }
 public function down():void {Schema::dropIfExists('destination_events');Schema::dropIfExists('destination_proposals');Schema::dropIfExists('destination_contacts');}
};
