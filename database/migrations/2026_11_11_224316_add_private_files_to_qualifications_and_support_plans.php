<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['qualifications', 'support_plans'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->json('files')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['support_plans', 'qualifications'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('files');
            });
        }
    }
};
