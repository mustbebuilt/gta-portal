<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('apprentice', function (Blueprint $table) {
            $table->string('achievement_status', 50)->nullable()->default('Unknown')->after('status');
            $table->date('achievement_date')->nullable()->after('achievement_status');
            $table->string('withdrawal_reason', 150)->nullable()->after('achievement_date');
            $table->string('withdrawal_reason_basis', 100)->nullable()->after('withdrawal_reason');
            $table->string('apprentice_voice', 100)->nullable()->after('withdrawal_reason_basis');
            $table->string('outcome_source_ref', 100)->nullable()->after('apprentice_voice');
            $table->string('destination_type', 150)->nullable()->after('outcome_source_ref');
            $table->date('destination_date')->nullable()->after('destination_type');
            $table->string('destination_basis', 100)->nullable()->after('destination_date');
            $table->string('destination_ref', 100)->nullable()->after('destination_basis');
            $table->string('employer_headcount_band', 50)->nullable()->default('Unknown')->after('destination_ref');
            $table->date('headcount_reference_date')->nullable()->after('employer_headcount_band');
            $table->string('additional_needs_recorded', 20)->nullable()->default('Unknown')->after('headcount_reference_date');
            $table->string('ehcp_recorded', 20)->nullable()->default('Unknown')->after('additional_needs_recorded');
            $table->string('context_source_ref', 100)->nullable()->after('ehcp_recorded');
        });
    }

    public function down(): void
    {
        Schema::table('apprentice', function (Blueprint $table) {
            $table->dropColumn([
                'achievement_status',
                'achievement_date',
                'withdrawal_reason',
                'withdrawal_reason_basis',
                'apprentice_voice',
                'outcome_source_ref',
                'destination_type',
                'destination_date',
                'destination_basis',
                'destination_ref',
                'employer_headcount_band',
                'headcount_reference_date',
                'additional_needs_recorded',
                'ehcp_recorded',
                'context_source_ref',
            ]);
        });
    }
};

