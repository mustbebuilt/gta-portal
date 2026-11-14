<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_preparation_briefs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('progress_review_draft_id')->constrained()->restrictOnDelete();
            $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $t->date('period_from');
            $t->date('period_to');
            $t->json('source');
            $t->string('source_hash', 64);
            $t->json('suggested');
            $t->json('applied')->nullable();
            $t->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('applied_at')->nullable();
            $t->unsignedInteger('applied_review_version')->nullable();
            $t->timestamps();
            $t->index(['progress_review_draft_id', 'created_at'], 'review_briefs_review_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_preparation_briefs');
    }
};
