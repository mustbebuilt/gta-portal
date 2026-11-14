<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_plans', function (Blueprint $table): void {
            $table->foreignId('review_owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('review_owner_revision')->default(0);
        });
        Schema::create('support_plan_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->json('source');
            $table->string('source_key', 64)->unique();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('support_plan_reminder_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reminder_id')->constrained('support_plan_reminders')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 40);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_plan_reminder_events');
        Schema::dropIfExists('support_plan_reminders');
        Schema::table('support_plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('review_owner_id');
            $table->dropColumn('review_owner_revision');
        });
    }
};
