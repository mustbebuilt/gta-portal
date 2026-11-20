<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monthly_scorecards', function (Blueprint $t): void {
            $t->json('development')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('monthly_scorecards')->whereNotNull('development')->exists()) {
            throw new RuntimeException('Development evidence exists. Retain the column and roll back code only; review/export evidence before destructive removal.');
        }
        Schema::table('monthly_scorecards', function (Blueprint $t): void {
            $t->dropColumn('development');
        });
    }
};
