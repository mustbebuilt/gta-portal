<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('careers_prompts',function(Blueprint $t): void {
            $t->id(); $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $t->unsignedTinyInteger('block'); $t->date('start_date'); $t->string('plan_hash',64);
            $t->string('source_key',64)->unique(); $t->timestamp('seen_at')->nullable(); $t->timestamps();
        });
        Schema::create('careers_prompt_events',function(Blueprint $t): void {
            $t->id(); $t->foreignId('careers_prompt_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('event',30); $t->string('origin',30); $t->timestamp('created_at');
        });
        Schema::create('portal_reminder_runs',function(Blueprint $t): void {
            $t->id(); $t->json('counts'); $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_reminder_runs');Schema::dropIfExists('careers_prompt_events');Schema::dropIfExists('careers_prompts');
    }
};
