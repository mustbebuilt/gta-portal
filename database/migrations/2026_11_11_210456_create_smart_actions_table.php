<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smart_actions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('learning_task_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->text('title');
            $t->string('owner', 50);
            $t->date('due_date')->nullable();
            $t->string('method', 30);
            $t->text('measure');
            $t->string('evidence_method', 100);
            $t->string('relevance', 40);
            $t->string('learning_reference', 250);
            $t->text('support');
            $t->json('smart_data');
            $t->string('status', 30)->default('To do');
            $t->text('evidence_note')->nullable();
            $t->text('feedback')->nullable();
            $t->date('completion_date')->nullable();
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['apprentice_id', 'learning_task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smart_actions');
    }
};
