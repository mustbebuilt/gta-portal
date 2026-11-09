<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employers', function (Blueprint $table) {
            $table->increments('employer_id');
            $table->string('employer_name', 200);
            $table->string('company_reg_number', 50)->nullable();
            $table->string('address_line1', 255)->nullable();
            $table->string('postcode', 20)->nullable();
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_email', 150)->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->timestamps();
        });

        Schema::create('apprentice', function (Blueprint $table) {
            $table->increments('apprentice_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('employer_id')->nullable();
            $table->foreignId('trainer_id')->nullable()->constrained('users')->nullOnDelete(); // Main Tutor
            $table->unsignedInteger('standard_id')->nullable();
            $table->date('start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->date('actual_end_date')->nullable();
            $table->decimal('otj_target_hours', 8, 2)->default(663.00);
            $table->decimal('otj_hours_req_to_date', 8, 2)->default(0.00);
            $table->decimal('otj_hours_completed', 8, 2)->default(0.00);
            $table->enum('status', ['Live', 'Completed', 'Withdrawn', 'Break in Learning'])->default('Live');
            $table->timestamps();

            $table->foreign('employer_id')->references('employer_id')->on('employers')->nullOnDelete();
            $table->foreign('standard_id')->references('standard_id')->on('standards')->nullOnDelete();
        });

        Schema::create('tutor_groups', function (Blueprint $table) {
            $table->increments('tutor_group_id');
            $table->string('group_name', 100);
            $table->string('group_code', 50)->nullable();
            $table->foreignId('tutor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('apprentice_tutor_groups', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('apprentice_id');
            $table->unsignedInteger('tutor_group_id');
            $table->timestamps();

            $table->foreign('apprentice_id')->references('apprentice_id')->on('apprentice')->cascadeOnDelete();
            $table->foreign('tutor_group_id')->references('tutor_group_id')->on('tutor_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apprentice_tutor_groups');
        Schema::dropIfExists('tutor_groups');
        Schema::dropIfExists('apprentice');
        Schema::dropIfExists('employers');
    }
};

