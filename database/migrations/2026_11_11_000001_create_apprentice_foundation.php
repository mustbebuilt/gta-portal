<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 40)->default('unassigned');
            $table->boolean('active')->default(true);
        });
        Schema::create('apprentices', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 254)->nullable();
            $table->string('programme', 200)->nullable();
            $table->date('start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->string('employer', 200)->nullable();
            $table->string('group_name', 100)->nullable();
            $table->foreignId('tutor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('apprentice_id')->constrained('apprentices')->restrictOnDelete();
            $table->string('event', 30);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('apprentices');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'active']));
    }
};
