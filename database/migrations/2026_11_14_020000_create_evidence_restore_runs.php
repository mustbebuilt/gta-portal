<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('evidence_restore_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('copy_plan_id')->constrained('evidence_copy_plans')->restrictOnDelete();
            $table->string('reference', 1000);
            $table->json('manifest');
            $table->char('manifest_hash', 64);
            $table->string('state', 40)->default('Prepared')->index();
            $table->unsignedInteger('version')->default(1);
            $table->json('result')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('evidence_restore_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('run_id')->constrained('evidence_restore_runs')->restrictOnDelete();
            $table->string('event', 32);
            $table->json('summary');
            $table->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('evidence_restore_events');
        Schema::dropIfExists('evidence_restore_runs');
    }
};
