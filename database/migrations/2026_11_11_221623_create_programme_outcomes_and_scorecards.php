<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('programme_outcomes', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->unique()->constrained()->restrictOnDelete();
            $t->json('context');
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('monthly_scorecards', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('tutor_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('training_session_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('month', 7);
            $t->json('scores');
            $t->text('comment')->nullable();
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['apprentice_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_scorecards');
        Schema::dropIfExists('programme_outcomes');
    }
};
