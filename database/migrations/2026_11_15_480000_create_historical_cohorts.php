<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('historical_cohorts',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->string('state',24);$t->unsignedInteger('version');$t->string('source_hash',64);$t->string('details_hash',64);$t->longText('details');$t->longText('proof');$t->timestamp('approved_at')->nullable();$t->timestamps();});
  Schema::create('historical_cohort_rows',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('cohort_id');$t->foreign('cohort_id')->references('id')->on('historical_cohorts')->restrictOnDelete();$t->foreignId('apprentice_id')->constrained('apprentices')->restrictOnDelete();$t->foreignId('historical_tutor_id')->nullable()->constrained('users')->restrictOnDelete();$t->unsignedInteger('version');$t->string('source_hash',64);$t->string('details_hash',64);$t->longText('details');$t->timestamps();$t->unique(['cohort_id','apprentice_id']);});
 }
 public function down():void {Schema::dropIfExists('historical_cohort_rows');Schema::dropIfExists('historical_cohorts');}
};
