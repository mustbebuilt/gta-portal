<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marking_reminders', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('learning_task_id')->constrained()->restrictOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $t->string('source_key', 64)->unique();
            $t->unsignedInteger('submission_version');
            $t->timestamp('submitted_at');
            $t->timestamp('seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('marking_reminder_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('marking_reminder_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('event', 40);
            $t->string('origin', 30);
            $t->timestamp('created_at');
        });
        Schema::table('learning_tasks', fn (Blueprint $t) => $t->index(['status', 'submitted_at'], 'learning_tasks_marking_queue'));
    }

    public function down(): void
    {
        Schema::table('learning_tasks', fn (Blueprint $t) => $t->dropIndex('learning_tasks_marking_queue'));
        Schema::dropIfExists('marking_reminder_events');
        Schema::dropIfExists('marking_reminders');
    }
};
