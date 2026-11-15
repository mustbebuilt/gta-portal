<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {Schema::create('timetable_delivery_records',function(Blueprint $t):void {$t->foreignId('training_session_id')->primary()->constrained('training_sessions')->restrictOnDelete();$t->foreignId('staff_id')->constrained('users')->restrictOnDelete();$t->unsignedInteger('version');$t->longText('details');$t->char('details_hash',64);$t->timestamps();});}
 public function down():void {Schema::dropIfExists('timetable_delivery_records');}
};
