<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_tasks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->string('reference', 30);
            $t->unsignedTinyInteger('block');
            $t->string('title', 250);
            $t->string('theme', 250);
            $t->json('template');
            $t->json('answers')->nullable();
            $t->json('submissions')->nullable();
            $t->string('status', 30)->default('Draft');
            $t->date('due_date')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['apprentice_id', 'reference']);
        });
        Schema::create('task_evidence', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('learning_task_id')->constrained()->restrictOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('path', 500);
            $t->string('name', 255);
            $t->string('mime', 100);
            $t->string('sha256', 64);
            $t->unsignedBigInteger('bytes');
            $t->string('kind', 30);
            $t->timestamps();
        });
        Schema::create('learning_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->string('kind', 40);
            $t->unsignedBigInteger('record_id');
            $t->string('event', 40);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_events');
        Schema::dropIfExists('task_evidence');
        Schema::dropIfExists('learning_tasks');
    }
};
