<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('learning_tasks',fn(Blueprint $t)=>$t->json('deadline_history')->nullable()); }
    public function down(): void { Schema::table('learning_tasks',fn(Blueprint $t)=>$t->dropColumn('deadline_history')); }
};
