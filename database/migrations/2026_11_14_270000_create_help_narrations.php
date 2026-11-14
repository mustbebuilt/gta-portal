<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_narration_records', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('guide_id', 80)->index();
            $t->char('guide_hash', 64);
            $t->text('transcript');
            $t->text('file');
            $t->text('source_reference');
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->char('binding', 64);
            $t->string('state', 20)->default('Uploaded');
            $t->string('published_guide_key', 80)->nullable()->unique();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('publication')->nullable();
            $t->char('publication_hash', 64)->nullable();
            $t->longText('history');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('help_narration_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignUuid('record_id')->constrained('help_narration_records')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('event', 48);
            $t->unsignedInteger('record_version');
            $t->text('private_note')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_narration_events');
        Schema::dropIfExists('help_narration_records');
    }
};
