<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('weekly_retention_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apprentice_id')->constrained()->cascadeOnDelete();
            $table->date('week_of');
            $table->json('evidence');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['apprentice_id', 'week_of']);
            $table->index('week_of');
        });

        Schema::create('weekly_retention_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_retention_check_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email_state', 20)->default('pending');
            $table->unique(['weekly_retention_check_id', 'user_id'], 'weekly_retention_recipient_unique');
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('weekly_retention_checks')->exists()) {
            throw new RuntimeException('Weekly retention review and reminder history must be preserved.');
        }

        Schema::dropIfExists('weekly_retention_reminders');
        Schema::dropIfExists('weekly_retention_checks');
    }
};
