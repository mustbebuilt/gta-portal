<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_studies', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->json('fields');
            $t->boolean('privacy')->default(false);
            $t->string('status', 30)->default('Draft');
            $t->json('history')->nullable();
            $t->json('approved_copies')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('survey_receipts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->string('kind', 20);
            $t->date('submitted_date');
            $t->unsignedInteger('receipt_revision')->default(1);
            $t->json('checks')->nullable();
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['apprentice_id', 'kind', 'submitted_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_receipts');
        Schema::dropIfExists('case_studies');
    }
};
