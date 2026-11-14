<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apprentice_alert_controls', function (Blueprint $t): void {
            $t->unsignedTinyInteger('id')->primary();
            $t->boolean('paused')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->json('history');
            $t->timestamps();
        });
        DB::table('apprentice_alert_controls')->insert(['id' => 1, 'paused' => false, 'version' => 1, 'history' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('apprentice_alert_deliveries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $t->string('channel', 8);
            $t->date('cycle_date');
            $t->json('source');
            $t->string('source_hash', 64);
            $t->string('recipient_hash', 64);
            $t->unsignedInteger('preference_revision');
            $t->unsignedInteger('auth_version');
            $t->string('config_hash', 64);
            $t->string('state', 24)->default('Prepared');
            $t->string('reason', 48)->nullable();
            $t->timestamp('reserved_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('provider_id', 96)->nullable();
            $t->timestamp('seen_at')->nullable();
            $t->json('reconciliation')->nullable();
            $t->json('history');
            $t->timestamps();
            $t->unique(['apprentice_id', 'recipient_id', 'channel', 'cycle_date'], 'apprentice_alert_cycle_channel');
            $t->index(['state', 'cycle_date']);
            $t->index('reserved_at');
            $t->index(['cycle_date', 'channel', 'recipient_hash'], 'alert_contact_daily');
            $t->index(['cycle_date', 'channel', 'recipient_id'], 'alert_recipient_daily');
            $t->index(['created_at', 'id'], 'alert_history_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apprentice_alert_deliveries');
        Schema::dropIfExists('apprentice_alert_controls');
    }
};
