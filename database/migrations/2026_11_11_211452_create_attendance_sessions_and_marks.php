<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_sessions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tutor_group_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('name', 250);
            $t->date('session_date');
            $t->string('start_time', 5);
            $t->string('end_time', 5);
            $t->text('planned_learning');
            $t->text('delivered_learning')->nullable();
            $t->json('breaks');
            $t->json('members');
            $t->string('state', 20)->default('Planned');
            $t->text('cancellation_reason')->nullable();
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('attendance_marks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('training_session_id')->constrained()->restrictOnDelete();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->string('code', 1)->nullable();
            $t->string('arrival', 5)->nullable();
            $t->string('departure', 5)->nullable();
            $t->unsignedInteger('otj_minutes')->nullable();
            $t->json('parts')->nullable();
            $t->text('comment')->nullable();
            $t->text('adjustment')->nullable();
            $t->boolean('otj_confirmed')->default(false);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['training_session_id', 'apprentice_id'], 'unique_session_attendance');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_marks');
        Schema::dropIfExists('training_sessions');
    }
};
