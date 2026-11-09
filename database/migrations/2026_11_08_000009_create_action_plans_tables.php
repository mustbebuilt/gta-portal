<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('action_plans')) {
            Schema::create('action_plans', function (Blueprint $table) {
                $table->increments('action_plan_id');
                $table->unsignedInteger('apprentice_id');
                $table->foreignId('created_by_user_id')->constrained('users');
                $table->timestamps();

                $table->foreign('apprentice_id')->references('apprentice_id')->on('apprentice')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('action_plan_items')) {
            Schema::create('action_plan_items', function (Blueprint $table) {
                $table->increments('action_item_id');
                $table->unsignedInteger('action_plan_id');
                $table->text('action_description');
                $table->enum('owner_role', [
                    'Apprentice',
                    'Tutor',
                    'Employer / workplace mentor',
                    'Learning & Progress Mentor'
                ]);
                $table->date('target_date');
                $table->enum('status', ['To do', 'Completed'])->default('To do');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->foreign('action_plan_id')->references('action_plan_id')->on('action_plans')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('action_plan_items');
        Schema::dropIfExists('action_plans');
    }
};
