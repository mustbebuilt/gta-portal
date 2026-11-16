<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::create('timetable_optimisation_runs',function(Blueprint $t){$t->uuid('id')->primary();$t->uuid('request_token')->unique();$t->foreignId('created_by')->constrained('users')->restrictOnDelete();$t->string('state',24);$t->unsignedInteger('version');$t->string('source_hash',64);$t->string('details_hash',64);$t->longText('details');$t->longText('receipt')->nullable();$t->string('receipt_hash',64)->nullable();$t->timestamps();});}
 public function down():void{Schema::dropIfExists('timetable_optimisation_runs');}
};
