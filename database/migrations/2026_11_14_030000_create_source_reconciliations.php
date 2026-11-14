<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('source_reconciliations',function(Blueprint $t){
            $t->id();$t->foreignId('apprentice_id')->constrained()->restrictOnDelete();$t->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $t->json('snapshot');$t->char('binding',64);$t->string('decision',32);$t->text('source_reference');$t->text('scope_note');$t->text('reason');$t->string('state',24)->default('Submitted');$t->unsignedInteger('version')->default(1);
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->restrictOnDelete();$t->text('review_note')->nullable();$t->timestamp('reviewed_at')->nullable();$t->timestamps();$t->index(['apprentice_id','state']);
        });
        Schema::create('source_reconciliation_events',function(Blueprint $t){$t->id();$t->foreignId('reconciliation_id')->constrained('source_reconciliations')->restrictOnDelete();$t->foreignId('actor_id')->constrained('users')->restrictOnDelete();$t->string('event',32);$t->text('note')->nullable();$t->timestamp('created_at');});
    }
    public function down(): void {Schema::dropIfExists('source_reconciliation_events');Schema::dropIfExists('source_reconciliations');}
};
