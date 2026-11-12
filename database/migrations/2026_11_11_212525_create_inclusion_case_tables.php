<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apprentice_markers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->string('code', 4);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['apprentice_id', 'code']);
        });
        Schema::create('support_concerns', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('raised_by')->constrained('users')->restrictOnDelete();
            $t->text('initial_note');
            $t->string('code', 4)->nullable();
            $t->string('other_code', 250)->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('acknowledged_at')->nullable();
            $t->foreignId('acknowledged_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('status', 20)->default('New');
            $t->text('next_action')->nullable();
            $t->date('action_due')->nullable();
            $t->date('next_review')->nullable();
            $t->json('followups')->nullable();
            $t->json('history')->nullable();
            $t->text('outcome')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('support_plans', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->json('concern_ids');
            $t->json('draft');
            $t->json('active_plan')->nullable();
            $t->json('history')->nullable();
            $t->json('reviews')->nullable();
            $t->json('activities')->nullable();
            $t->string('status', 20)->default('Draft');
            $t->text('closure_outcome')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('teaching_instructions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->text('draft_text');
            $t->text('private_note')->nullable();
            $t->json('recipient_ids');
            $t->json('release')->nullable();
            $t->json('acknowledgements')->nullable();
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teaching_instructions');
        Schema::dropIfExists('support_plans');
        Schema::dropIfExists('support_concerns');
        Schema::dropIfExists('apprentice_markers');
    }
};
