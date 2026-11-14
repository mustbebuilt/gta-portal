<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_block_controls', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('apprentice_id')->unique()->constrained()->restrictOnDelete();
            $t->boolean('enabled')->default(true);
            $t->json('exceptions');
            $t->json('history');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_block_controls');
    }
};
