<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('individual_delivery_plans', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->unique()->constrained()->restrictOnDelete();
            $t->json('draft');
            $t->json('approved')->nullable();
            $t->boolean('active')->default(false);
            $t->json('history');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::table('progress_review_drafts', function (Blueprint $t): void {
            $t->string('state', 40)->default('Draft');
        });
    }

    public function down(): void
    {
        Schema::table('progress_review_drafts', fn (Blueprint $t) => $t->dropColumn('state'));
        Schema::dropIfExists('individual_delivery_plans');
    }
};
