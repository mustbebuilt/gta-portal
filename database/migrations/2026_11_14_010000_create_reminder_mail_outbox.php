<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('reminder_mail_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipient_id')->constrained('users')->restrictOnDelete();
            $table->char('fingerprint', 64)->unique();
            $table->json('snapshot');
            $table->string('state', 24)->default('prepared')->index();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('approver_auth_version')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('reminder_mail_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outbox_id')->constrained('reminder_mail_outbox')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 32);
            $table->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('reminder_mail_events');
        Schema::dropIfExists('reminder_mail_outbox');
    }
};
