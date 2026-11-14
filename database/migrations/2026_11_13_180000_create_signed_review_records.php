<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signed_review_records',function(Blueprint $t): void {
            $t->id();$t->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $t->foreignId('progress_review_draft_id')->constrained()->restrictOnDelete();$t->unsignedInteger('review_version');
            $t->date('meeting_date');$t->json('snapshot');$t->string('snapshot_hash',64);$t->json('file');
            $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();$t->foreignId('checked_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('checked_at')->nullable();$t->timestamp('withdrawn_at')->nullable();$t->json('history');$t->unsignedInteger('version')->default(1);$t->timestamps();
            $t->index(['apprentice_id','meeting_date']);$t->index(['progress_review_draft_id','withdrawn_at'],'review_parent_withdrawn_idx');
        });
        Schema::create('signed_review_events',function(Blueprint $t): void {
            $t->id();$t->foreignId('signed_review_record_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',30);$t->timestamp('created_at');
        });
    }
    public function down(): void {Schema::dropIfExists('signed_review_events');Schema::dropIfExists('signed_review_records');}
};
