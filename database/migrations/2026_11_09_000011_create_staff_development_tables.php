<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('staff_development_records')) {
            Schema::create('staff_development_records', function (Blueprint $table) {
                $table->id('record_id');
                $table->unsignedBigInteger('user_id'); // Staff member
                $table->unsignedBigInteger('manager_id')->nullable(); // Line manager / observer
                $table->string('category')->default('cpd'); // overview, performance, cpd, observation, meetings
                $table->string('title');
                $table->date('record_date');
                $table->text('feedback')->nullable();
                $table->string('evidence_ref')->nullable();
                $table->boolean('is_shared')->default(true);
                $table->text('private_notes')->nullable(); // Management only
                $table->string('status')->default('Completed');
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('manager_id')->references('id')->on('users')->onDelete('set null');
            });
        }

        if (!Schema::hasTable('staff_development_targets')) {
            Schema::create('staff_development_targets', function (Blueprint $table) {
                $table->id('target_id');
                $table->unsignedBigInteger('user_id');
                $table->text('target');
                $table->text('measure')->nullable();
                $table->text('reason')->nullable();
                $table->text('support_provided')->nullable();
                $table->date('deadline')->nullable();
                $table->string('status')->default('In Progress'); // In Progress, Achieved, Overdue
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_development_targets');
        Schema::dropIfExists('staff_development_records');
    }
};
