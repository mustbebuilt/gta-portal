<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_marker_schedules71', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained('apprentices')->restrictOnDelete();
            $t->foreignId('support_concern_id')->nullable()->unique()->constrained('support_concerns')->restrictOnDelete();
            $t->string('profile_marker_code', 12)->nullable();
            $t->string('interval_key', 20)->default('monthly');
            $t->boolean('first_review_completed')->default(false);
            $t->date('last_formal_review_date')->nullable();
            $t->date('next_due_date')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->longText('history'); // encrypted:array
            $t->timestamps();
            $t->unique(['apprentice_id', 'profile_marker_code'], 'support_sched71_apprentice_marker_uq');
            $t->index(['apprentice_id', 'next_due_date'], 'support_sched71_apprentice_due_idx');
            $t->index(['next_due_date', 'first_review_completed'], 'support_sched71_due_state_idx');
        });

        Schema::create('support_shared_actions71', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained('apprentices')->restrictOnDelete();
            $t->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('support_concern_id')->nullable()->constrained('support_concerns')->restrictOnDelete();
            $t->foreignId('support_plan_id')->nullable()->constrained('support_plans')->restrictOnDelete();
            $t->uuid('request_uuid')->unique();
            $t->date('due_date');
            $t->string('state', 20)->default('Open');
            $t->timestamp('completed_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->longText('details'); // encrypted:array
            $t->longText('history'); // encrypted:array
            $t->timestamps();
            $t->index(['apprentice_id', 'state', 'due_date'], 'support_act71_apprentice_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_shared_actions71');
        Schema::dropIfExists('support_marker_schedules71');
    }
};
