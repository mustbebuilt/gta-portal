<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_groups', function (Blueprint $table): void {
            $table->json('weekly_register')->nullable();
        });
        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->date('regular_week')->nullable();
            $table->unique(['tutor_group_id', 'regular_week'], 'unique_group_regular_week');
        });
    }

    public function down(): void
    {
        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->dropUnique('unique_group_regular_week');
            $table->dropColumn('regular_week');
        });
        Schema::table('tutor_groups', function (Blueprint $table): void {
            $table->dropColumn('weekly_register');
        });
    }
};
