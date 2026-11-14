<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_import_batches',function(Blueprint $t): void {
            $t->id(); $t->string('kind',30); $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('applied_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('source_name',255); $t->text('source_reference'); $t->string('source_hash',64); $t->string('preview_hash',64);
            $t->json('rows'); $t->string('state',20)->default('Preview'); $t->unsignedInteger('version')->default(1);
            $t->timestamp('applied_at')->nullable(); $t->timestamps();
        });
        Schema::create('data_import_events',function(Blueprint $t): void {
            $t->id(); $t->foreignId('data_import_batch_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete(); $t->string('event',30); $t->timestamp('created_at');
        });
        Schema::create('historical_otj_records',function(Blueprint $t): void {
            $t->id(); $t->foreignId('data_import_batch_id')->constrained()->restrictOnDelete();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete(); $t->json('programme_identity');
            $t->date('training_date'); $t->string('start_time',5); $t->string('end_time',5); $t->unsignedInteger('excluded_minutes'); $t->unsignedInteger('minutes');
            $t->index(['apprentice_id','training_date']);
            $t->string('topic',1000); $t->text('evidence_reference'); $t->string('fingerprint',64)->unique();
            $t->timestamp('withdrawn_at')->nullable(); $t->json('history'); $t->unsignedInteger('version')->default(1); $t->timestamps();
        });
        Schema::create('management_packs',function(Blueprint $t): void {
            $t->id(); $t->foreignId('captured_by')->constrained('users')->restrictOnDelete();
            $t->string('title',180); $t->json('snapshot'); $t->string('snapshot_hash',64); $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_packs');Schema::dropIfExists('historical_otj_records');Schema::dropIfExists('data_import_events');Schema::dropIfExists('data_import_batches');
    }
};
