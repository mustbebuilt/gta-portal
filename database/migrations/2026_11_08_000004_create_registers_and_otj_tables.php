<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->increments('attendance_session_id');
            $table->unsignedInteger('tutor_group_id')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->dateTime('session_datetime');
            $table->string('topic', 255)->nullable();
            $table->timestamps();

            $table->foreign('tutor_group_id')->references('tutor_group_id')->on('tutor_groups')->nullOnDelete();
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->increments('attendance_record_id');
            $table->unsignedInteger('attendance_session_id');
            $table->unsignedInteger('apprentice_id');
            $table->foreignId('marked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['Present', 'Late', 'Authorised Absence', 'Unauthorised Absence']);
            $table->char('authorised_code', 1)->nullable(); // L, W, S, H, E
            $table->text('comment')->nullable();
            $table->dateTime('arrival_datetime')->nullable();
            $table->timestamps();

            $table->foreign('attendance_session_id')->references('attendance_session_id')->on('attendance_sessions')->cascadeOnDelete();
            $table->foreign('apprentice_id')->references('apprentice_id')->on('apprentice')->cascadeOnDelete();
        });

        // OTJ Ledger (time_chunks: 1 chunk = 15 minutes / 0.25 hours)
        Schema::create('otj', function (Blueprint $table) {
            $table->increments('otj_id');
            $table->foreignId('uid')->constrained('users')->cascadeOnDelete(); // Apprentice user_id
            $table->unsignedTinyInteger('time_chunks');                        // e.g. 4 = 1 hour, 24 = 6 hours
            $table->date('date_of_otj');
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->enum('otj_type', [
                'Role Playing',
                'Simulation Exercise',
                'Online-Learning',
                'Manufacture or Equipment Training',
                'Practical Training (Shadowing)',
                'Other'
            ]);
            $table->text('otj_activity_description')->nullable();
            $table->boolean('otj_trainer_sign_off')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otj');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
    }
};

