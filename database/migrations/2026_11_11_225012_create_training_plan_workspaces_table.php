<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_plan_workspaces', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->unique()->constrained()->restrictOnDelete();
            $t->string('source_hash', 64);
            $t->json('proposal')->nullable();
            $t->json('rpl')->nullable();
            $t->json('reviews')->nullable();
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_plan_workspaces');
    }
};
