<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('teaching_instructions', function (Blueprint $table): void {
            $table->unsignedInteger('draft_revision')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teaching_instructions', function (Blueprint $table): void {
            $table->dropColumn('draft_revision');
        });
    }
};
