<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_development_profiles', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('manager_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('payload');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('staff_development_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('event', 60);
            $t->json('reference')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_development_events');
        Schema::dropIfExists('staff_development_profiles');
    }
};
