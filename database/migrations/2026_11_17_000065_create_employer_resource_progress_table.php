<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('employer_resource_progress65', function (Blueprint $table) {
            $table->id();
            $table->string('user_key', 64);
            $table->string('organisation_key', 64);
            $table->string('content_version', 32);
            $table->string('course', 32);
            $table->string('page', 80);
            $table->unsignedInteger('score');
            $table->boolean('commitment')->default(false);
            $table->timestamp('completed_at');
            $table->unique(['user_key','organisation_key','content_version','course','page'], 'resource65_unique');
        });
    }
    public function down(): void { Schema::dropIfExists('employer_resource_progress65'); }
};
