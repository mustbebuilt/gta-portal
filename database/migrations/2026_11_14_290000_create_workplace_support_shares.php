<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('workplace_support_shares',function(Blueprint $t):void {
   $t->uuid('id')->primary();$t->uuid('request_token')->unique();
   $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
   $t->foreignId('plan_id')->constrained('support_plans')->restrictOnDelete();
   $t->foreignId('grant_id')->constrained('employer_apprentice_grants')->restrictOnDelete();
   $t->foreignId('learner_id')->constrained('users')->restrictOnDelete();
   $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
   $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
   $t->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();
   $t->string('state',32)->default('Draft');$t->unsignedInteger('version')->default(1);
   $t->longText('payload');$t->char('payload_hash',64);$t->longText('source');$t->char('source_hash',64);
   $t->longText('permission')->nullable();$t->timestamp('expires_at');
   $t->timestamp('checked_at')->nullable();$t->timestamp('decided_at')->nullable();
   $t->timestamp('acknowledged_at')->nullable();$t->timestamp('withdrawn_at')->nullable();
   $t->timestamps();$t->index(['recipient_id','state']);$t->index(['learner_id','state']);
  });
  Schema::create('workplace_support_share_events',function(Blueprint $t):void {
   $t->id();$t->foreignUuid('share_id')->constrained('workplace_support_shares')->restrictOnDelete();
   $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',40);
   $t->unsignedInteger('share_version');$t->timestamp('created_at');
  });
 }
 public function down():void {Schema::dropIfExists('workplace_support_share_events');Schema::dropIfExists('workplace_support_shares');}
};
