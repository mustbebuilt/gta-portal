<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_evidence', function (Blueprint $table): void {
            $table->string('disk', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_evidence', function (Blueprint $table): void {
            $table->dropColumn('disk');
        });
    }
};
