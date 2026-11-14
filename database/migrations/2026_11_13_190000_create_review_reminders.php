<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_reminders', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('signed_review_record_id')->constrained()->restrictOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $t->json('source');
            $t->string('source_key',64)->unique();
            $t->timestamp('seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('review_reminder_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('review_reminder_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('event',30); $t->string('origin',30); $t->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('review_reminder_events');Schema::dropIfExists('review_reminders');
    }
};
