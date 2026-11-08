<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('progress_reviews', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('apprentice_id');
            $table->foreignId('trainer_id')->constrained('users');
            $table->unsignedSmallInteger('review_sequence'); // 1 = 10-week, 2+ = 70-day intervals
            $table->date('due_date');
            $table->date('meeting_date')->nullable();
            $table->text('progress_summary')->nullable();
            $table->enum('status', ['Scheduled', 'Conducted', 'Signed', 'Overdue'])->default('Scheduled');
            $table->timestamps();

            $table->foreign('apprentice_id')->references('apprentice_id')->on('apprentice')->cascadeOnDelete();
        });

        Schema::create('review_signatures', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('review_id');
            $table->foreignId('user_id')->constrained('users');
            $table->enum('party_role', ['apprentice', 'employer', 'mentor']);
            $table->string('signatory_name', 150);
            $table->timestamp('signed_at');
            $table->string('ip_address', 45);
            $table->string('document_snapshot_hash', 64);
            $table->timestamps();

            $table->foreign('review_id')->references('id')->on('progress_reviews')->cascadeOnDelete();
            $table->unique(['review_id', 'party_role']);
        });

        Schema::create('case_studies', function (Blueprint $table) {
            $table->increments('id');
            $table->foreignId('author_user_id')->constrained('users');
            $table->string('title', 255);
            $table->enum('category', [
                'Additional learning support',
                'Attendance and engagement',
                'Employment and retention',
                'Maths and English',
                'Teaching and assessment',
                'Employer partnership',
                'Careers and progression',
                'Practical barriers and wellbeing',
                'Wider development',
                'Staff development and improvement',
                'Other'
            ]);
            $table->text('challenge');
            $table->text('starting_point');
            $table->text('response');
            $table->text('before_state');
            $table->date('before_date');
            $table->text('after_state');
            $table->date('after_date');
            $table->text('evidence');
            $table->string('outcome');
            $table->enum('status', ['Draft', 'Pending Approval', 'Approved', 'Rejected'])->default('Draft');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_studies');
        Schema::dropIfExists('review_signatures');
        Schema::dropIfExists('progress_reviews');
    }
};

