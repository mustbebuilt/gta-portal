<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('executive_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_entity_type', 100);
            $table->unsignedBigInteger('target_entity_id');
            $table->text('reason'); // Mandatory justification for emergency override
            $table->string('ip_address', 45);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('accessed_at');

            $table->index(['target_entity_type', 'target_entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_access_logs');
    }
};

