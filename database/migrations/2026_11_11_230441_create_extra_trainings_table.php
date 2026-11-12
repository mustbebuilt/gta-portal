<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extra_trainings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('tutor_group_id')->constrained()->restrictOnDelete();
            $t->date('training_date');
            $t->string('start_time', 5);
            $t->string('end_time', 5);
            $t->unsignedInteger('excluded_minutes');
            $t->unsignedInteger('minutes');
            $t->json('parts');
            $t->string('topic', 250);
            $t->string('provider', 250);
            $t->text('learning');
            $t->text('evidence');
            $t->string('working', 10);
            $t->json('agreement')->nullable();
            $t->json('compensation')->nullable();
            $t->json('approval')->nullable();
            $t->json('file')->nullable();
            $t->json('history')->nullable();
            $t->timestamp('withdrawn_at')->nullable();
            $t->text('withdrawal_reason')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['apprentice_id', 'training_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extra_trainings');
    }
};
