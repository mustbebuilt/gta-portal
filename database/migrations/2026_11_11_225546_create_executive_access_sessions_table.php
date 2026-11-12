<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_access_sessions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('reference')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->text('reason');
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
        });
        Schema::create('executive_access_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('executive_access_session_id')->constrained()->restrictOnDelete();
            $t->string('action', 100);
            $t->string('record_reference', 150)->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_access_events');
        Schema::dropIfExists('executive_access_sessions');
    }
};
