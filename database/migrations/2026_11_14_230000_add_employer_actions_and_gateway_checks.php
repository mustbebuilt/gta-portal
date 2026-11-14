<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employer_apprentice_grants', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('employer_id')->constrained()->restrictOnDelete();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->string('identity_hash', 64);
            $t->unsignedInteger('employer_version');
            $t->text('identity_reference');
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->json('history');
            $t->timestamps();
            $t->unique(['user_id', 'apprentice_id'], 'employer_grant_user_profile');
        });
        Schema::create('employer_portal_actions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('employer_apprentice_grant_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('kind', 32);
            $t->unsignedBigInteger('source_id');
            $t->json('source');
            $t->string('source_hash', 64);
            $t->string('title', 180);
            $t->text('detail');
            $t->date('due_date')->nullable();
            $t->string('state', 24)->default('Pending');
            $t->string('response', 32)->nullable();
            $t->text('response_note')->nullable();
            $t->foreignId('responded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('responded_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->json('history');
            $t->timestamps();
            $t->index(['employer_apprentice_grant_id', 'state'], 'employer_actions_grant_state');
        });
        Schema::create('gateway_readiness_plans', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('cohort', 180);
            $t->text('source_reference');
            $t->json('basis');
            $t->json('criteria');
            $t->string('source_hash', 64);
            $t->string('state', 24)->default('Draft');
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('approved_at')->nullable();
            $t->json('history');
            $t->timestamps();
            $t->index(['apprentice_id', 'state']);
        });
        Schema::create('gateway_readiness_checks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('gateway_readiness_plan_id')->constrained()->restrictOnDelete();
            $t->string('criterion_key', 16);
            $t->foreignId('checked_by')->constrained('users')->restrictOnDelete();
            $t->string('state', 16);
            $t->text('evidence_reference');
            $t->text('note');
            $t->date('checked_on');
            $t->string('binding_hash', 64);
            $t->unsignedInteger('version')->default(1);
            $t->json('history');
            $t->timestamps();
            $t->unique(['gateway_readiness_plan_id', 'criterion_key'], 'gateway_check_plan_key');
        });
    }

    public function down(): void
    {
        foreach (['gateway_readiness_checks', 'gateway_readiness_plans', 'employer_portal_actions', 'employer_apprentice_grants'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
