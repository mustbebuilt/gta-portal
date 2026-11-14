<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('apprentice_experience_entries',function(Blueprint $t):void {
   $t->id();$t->uuid('request_token')->unique();$t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
   $t->foreignId('requester_id')->constrained('users')->restrictOnDelete();$t->string('kind',32);$t->string('state',20)->default('New');
   $t->string('title',160);$t->text('detail');$t->date('happened_on')->nullable();$t->text('response')->nullable();
   $t->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();$t->unsignedInteger('version')->default(1);$t->json('history');$t->timestamps();
   $t->index(['apprentice_id','kind','state'],'experience_profile_kind_state');$t->index(['requester_id','created_at']);
  });
  Schema::table('task_evidence',fn(Blueprint $t)=>$t->text('description')->nullable());
 }
 public function down(): void {
  Schema::table('task_evidence',fn(Blueprint $t)=>$t->dropColumn('description'));
  Schema::dropIfExists('apprentice_experience_entries');
 }
};
