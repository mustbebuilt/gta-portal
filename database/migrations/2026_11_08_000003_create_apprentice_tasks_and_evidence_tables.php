<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('apprentice_tasks', function (Blueprint $table) {
            $table->increments('at_id');
            $table->unsignedInteger('apprentice_id');
            $table->unsignedInteger('ablk_id');
            $table->unsignedTinyInteger('task_num'); // 1 to 5
            $table->string('task_type', 150);
            $table->string('theme', 255)->nullable();
            $table->date('due_date')->nullable();
            $table->enum('status', ['Not Started', 'In Progress', 'Submitted', 'Completed', 'Referred'])->default('Not Started');
            $table->decimal('progress', 5, 2)->default(0.00);
            $table->date('completion_date')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('feedback_trainer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('feedback_date')->nullable();
            $table->text('feedback')->nullable();
            $table->unsignedTinyInteger('knowledge_score')->nullable(); // For Task 1 paper marks
            $table->timestamps();

            $table->foreign('apprentice_id')->references('apprentice_id')->on('apprentice')->cascadeOnDelete();
        });

        Schema::create('apprentice_task_uploads', function (Blueprint $table) {
            $table->increments('atu_id');
            $table->unsignedInteger('at_id');
            $table->unsignedInteger('apprentice_id');
            $table->string('original_filename', 255);
            $table->string('stored_path', 500);
            $table->string('mime_type', 100);
            $table->unsignedInteger('file_size_bytes');
            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'Superseded'])->default('Pending');
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->foreign('at_id')->references('at_id')->on('apprentice_tasks')->cascadeOnDelete();
            $table->foreign('apprentice_id')->references('apprentice_id')->on('apprentice')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apprentice_task_uploads');
        Schema::dropIfExists('apprentice_tasks');
    }
};

