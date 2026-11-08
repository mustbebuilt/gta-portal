<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_plans', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('apprentice_id');
            $table->unsignedSmallInteger('plan_number')->default(1);
            $table->enum('plan_type', ['full', 'light_touch'])->default('full');
            $table->enum('status', ['draft', 'active', 'closed', 'cancelled'])->default('active');
            $table->string('primary_support_need', 255);
            $table->text('authorized_teaching_adjustments'); // Tutors can see this to adapt lessons
            $table->text('confidential_case_notes')->nullable(); // Strictly confidential; never exposed to employer
            $table->date('referral_date')->nullable();
            $table->foreignId('plan_lead_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('info_sharing_consent')->default(true);
            $table->timestamps();

            $table->foreign('apprentice_id')->references('apprentice_id')->on('apprentice')->cascadeOnDelete();
        });

        Schema::create('support_actions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('support_plan_id');
            $table->text('action_description');
            $table->string('responsible_role', 100)->default('ALS Lead');
            $table->date('target_date')->nullable();
            $table->boolean('completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('support_plan_id')->references('id')->on('support_plans')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_actions');
        Schema::dropIfExists('support_plans');
    }
};

