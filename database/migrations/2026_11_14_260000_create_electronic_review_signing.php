<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_signing_authorities', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('employer_apprentice_grant_id')->constrained()->restrictOnDelete();
            $t->char('grant_binding', 64);
            $t->text('authority_reference');
            $t->date('expires_on');
            $t->boolean('active')->default(true);
            $t->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $t->longText('history');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique('employer_apprentice_grant_id', 'review_signing_grant_unique');
        });
        Schema::create('electronic_review_packets', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('progress_review_draft_id')->constrained()->restrictOnDelete();
            $t->foreignId('active_review_id')->nullable()->unique()->constrained('progress_review_drafts')->restrictOnDelete();
            $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $t->longText('snapshot');
            $t->char('snapshot_hash', 64);
            $t->string('state', 20)->default('Draft');
            $t->date('expires_on');
            $t->timestamp('released_at')->nullable();
            $t->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('checked_at')->nullable();
            $t->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('signed_review_record_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->longText('completion')->nullable();
            $t->char('completion_hash', 64)->nullable();
            $t->timestamp('withdrawn_at')->nullable();
            $t->foreignId('withdrawn_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['apprentice_id', 'state']);
        });
        Schema::create('electronic_review_signers', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('packet_id')->constrained('electronic_review_packets')->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('role', 16);
            $t->string('expected_name', 255);
            $t->char('identity_hash', 64);
            $t->unsignedInteger('auth_version');
            $t->string('state', 16)->default('Pending');
            $t->longText('attestation')->nullable();
            $t->char('attestation_hash', 64)->nullable();
            $t->timestamp('attested_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['packet_id', 'role']);
            $t->unique(['packet_id', 'user_id']);
            $t->index(['user_id', 'state']);
        });
        Schema::create('electronic_review_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignUuid('packet_id')->nullable()->constrained('electronic_review_packets')->restrictOnDelete();
            $t->foreignUuid('authority_id')->nullable()->constrained('review_signing_authorities')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('event', 48);
            $t->unsignedInteger('record_version');
            $t->text('private_note')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electronic_review_events');
        Schema::dropIfExists('electronic_review_signers');
        Schema::dropIfExists('electronic_review_packets');
        Schema::dropIfExists('review_signing_authorities');
    }
};
