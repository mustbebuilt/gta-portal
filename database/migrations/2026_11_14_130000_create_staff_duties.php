<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_duties', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->boolean('careers_adviser')->default(false);
            $table->boolean('mentor')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->json('history');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_duties');
    }
};
