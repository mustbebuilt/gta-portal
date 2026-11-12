<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_discussions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('adviser_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->string('type', 20);
            $t->date('discussion_date');
            $t->text('goals');
            $t->text('options');
            $t->text('advice');
            $t->string('outcome', 40);
            $t->text('next_step');
            $t->string('referral', 250)->nullable();
            $t->json('actions')->nullable();
            $t->json('followups')->nullable();
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_discussions');
    }
};
