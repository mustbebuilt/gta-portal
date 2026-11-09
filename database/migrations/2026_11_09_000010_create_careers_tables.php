<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('career_resources')) {
            Schema::create('career_resources', function (Blueprint $table) {
                $table->id('resource_id');
                $table->string('title');
                $table->string('category')->default('General');
                $table->text('description');
                $table->string('link_url')->nullable();
                $table->string('target_stage')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('career_discussions')) {
            Schema::create('career_discussions', function (Blueprint $table) {
                $table->id('discussion_id');
                $table->unsignedBigInteger('apprentice_id');
                $table->unsignedBigInteger('adviser_id');
                $table->string('discussion_type')->default('progression'); // progression, employment
                $table->date('discussion_date');
                $table->text('goals');
                $table->text('options_discussed');
                $table->text('advice_given');
                $table->string('outcome')->nullable(); // Next step agreed, Exploring options, Specialist referral
                $table->string('referral_to')->nullable();
                $table->text('next_steps');
                $table->timestamps();

                $table->foreign('apprentice_id')->references('apprentice_id')->on('apprentice')->onDelete('cascade');
                $table->foreign('adviser_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('career_discussions');
        Schema::dropIfExists('career_resources');
    }
};
