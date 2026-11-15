<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::create('restriction_recovery_runs',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->string('state',24);$t->unsignedInteger('version');$t->string('source_hash',64);$t->string('details_hash',64);$t->longText('details');$t->longText('approval')->nullable();$t->longText('applied')->nullable();$t->timestamps();});}
 public function down():void{Schema::dropIfExists('restriction_recovery_runs');}
};
