<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_questionnaire_forms', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('series_id')->index();
            $t->unsignedInteger('revision');
            $t->string('kind', 16);
            $t->string('title', 180);
            $t->text('introduction');
            $t->text('privacy_notice');
            $t->json('questions');
            $t->string('state', 16)->default('Draft');
            $t->char('content_hash', 64)->nullable();
            $t->foreignId('authored_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('published_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['series_id', 'revision']);
        });
        Schema::create('portal_questionnaire_assignments', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('form_id')->constrained('portal_questionnaire_forms')->restrictOnDelete();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $t->string('cycle_code', 64);
            $t->date('due_date')->nullable();
            $t->string('state', 20)->default('Assigned');
            $t->longText('draft_answers')->nullable();
            $t->unsignedInteger('submission_count')->default(0);
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['form_id', 'apprentice_id', 'cycle_code'], 'pq_assignment_cycle_unique');
            $t->index(['apprentice_id', 'recipient_id', 'state'], 'pq_assignment_person_state_idx');
            $t->index(['state', 'due_date']);
        });
        Schema::create('portal_questionnaire_submissions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('assignment_id')->constrained('portal_questionnaire_assignments')->restrictOnDelete();
            $t->unsignedInteger('revision');
            $t->longText('answer_payload');
            $t->char('payload_hash', 64);
            $t->timestamp('submitted_at');
            $t->string('state', 16)->default('Submitted');
            $t->string('followup', 16)->nullable();
            $t->foreignId('concern_id')->nullable()->constrained('support_concerns')->restrictOnDelete();
            $t->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('checked_at')->nullable();
            $t->text('private_note')->nullable();
            $t->text('shared_note')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['assignment_id', 'revision']);
            $t->timestamp('followup_resolved_at')->nullable();
            $t->foreignId('followup_resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->longText('followup_history')->nullable();
        });
        Schema::create('portal_questionnaire_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignUuid('assignment_id')->nullable()->constrained('portal_questionnaire_assignments')->restrictOnDelete();
            $t->foreignUuid('form_id')->constrained('portal_questionnaire_forms')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('event', 48);
            $t->unsignedInteger('record_version');
            $t->text('private_reason')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_questionnaire_events');
        Schema::dropIfExists('portal_questionnaire_submissions');
        Schema::dropIfExists('portal_questionnaire_assignments');
        Schema::dropIfExists('portal_questionnaire_forms');
    }
};
