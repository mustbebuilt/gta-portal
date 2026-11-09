<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Functional Skills (Maths & English Topics)
        if (!Schema::hasTable('apprentice_functional_skills')) {
            Schema::create('apprentice_functional_skills', function (Blueprint $table) {
                $table->increments('afs_id');
                $table->unsignedInteger('apprentice_id');
                $table->string('subject', 50); // e.g. 'Maths: Level 1', 'Maths: Level 2', 'English: Level 2'
                $table->string('topic', 150);   // e.g. 'Addition and Subtraction (including decimals)'
                $table->string('area', 100);    // e.g. 'Using Numbers', 'Common Measures, Shape and Space'
                $table->decimal('planned_hours', 5, 1)->default(0.0); // 0.0 = Secure / No training needed
                $table->enum('status', ['Not Started', 'In Progress', 'Completed'])->default('Not Started');
                $table->date('completion_date')->nullable();
                $table->timestamps();

                $table->foreign('apprentice_id')->references('apprentice_id')->on('apprentice')->cascadeOnDelete();
            });
        }

        // Mandatory Qualifications (e.g. Refrigerant Handling, Electric/Hybrid EV Maintenance)
        if (!Schema::hasTable('apprentice_mandatory_quals')) {
            Schema::create('apprentice_mandatory_quals', function (Blueprint $table) {
                $table->increments('id');
                $table->unsignedInteger('apprentice_id');
                $table->string('qualification_name', 255);
                $table->string('awarding_body', 100)->default('IMI');
                $table->enum('status', ['Not Started', 'Working towards', 'Awaiting confirmation', 'Achieved'])->default('Not Started');
                $table->date('custom_start_date')->nullable();
                $table->date('target_date')->nullable(); // 12-calendar-month standard target
                $table->date('achieved_date')->nullable();
                $table->timestamps();

                $table->foreign('apprentice_id')->references('apprentice_id')->on('apprentice')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('apprentice_mandatory_quals');
        Schema::dropIfExists('apprentice_functional_skills');
    }
};
