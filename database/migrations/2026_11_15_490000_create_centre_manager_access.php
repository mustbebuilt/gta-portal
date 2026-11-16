<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('centre_manager_grants',function(Blueprint $t){$t->foreignId('user_id')->primary()->constrained('users')->restrictOnDelete();$t->unsignedInteger('version');$t->boolean('active');$t->date('expires_on');$t->boolean('ceo_locked');$t->string('details_hash',64);$t->longText('details');$t->foreignId('updated_by')->constrained('users')->restrictOnDelete();$t->timestamps();});
  Schema::create('centre_manager_access_requests',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->foreignId('target_user_id')->constrained('users')->restrictOnDelete();$t->string('state',24);$t->unsignedInteger('version');$t->string('source_hash',64);$t->string('details_hash',64);$t->longText('details');$t->longText('approval')->nullable();$t->longText('applied')->nullable();$t->timestamps();});
  Schema::create('centre_manager_followups',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('apprentice_id')->constrained('apprentices')->restrictOnDelete();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->foreignId('owner_id')->constrained('users')->restrictOnDelete();$t->string('owner_slot',24);$t->string('area',32);$t->string('flag',48);$t->string('status',24);$t->unsignedInteger('version');$t->string('open_key',64)->nullable()->unique();$t->date('due_on');$t->unsignedInteger('grant_version')->nullable();$t->string('source_hash',64);$t->string('details_hash',64);$t->longText('details');$t->timestamps();});
 }
 public function down():void {Schema::dropIfExists('centre_manager_followups');Schema::dropIfExists('centre_manager_access_requests');Schema::dropIfExists('centre_manager_grants');}
};
