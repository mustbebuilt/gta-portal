<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_topics', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->string('subject', 10);
            $t->string('level', 20);
            $t->string('topic', 200);
            $t->string('area', 200);
            $t->decimal('planned_hours', 7, 2);
            $t->string('status', 20)->default('Not Started');
            $t->date('completion_date')->nullable();
            $t->string('evidence_stage', 30)->default('Draft');
            $t->text('draft')->nullable();
            $t->json('files')->nullable();
            $t->json('submissions')->nullable();
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['apprentice_id', 'subject', 'level', 'topic'], 'unique_assigned_topic');
        });
        Schema::create('qualifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->string('subject', 10);
            $t->string('type', 40);
            $t->string('title', 250);
            $t->string('level', 40);
            $t->string('status', 30);
            $t->string('result', 100)->nullable();
            $t->date('start_date')->nullable();
            $t->date('achievement_date')->nullable();
            $t->text('notes')->nullable();
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qualifications');
        Schema::dropIfExists('training_topics');
    }
};
