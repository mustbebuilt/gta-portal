<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_copy_plans',function(Blueprint $t): void {
            $t->uuid('id')->primary();$t->string('source_disk',80);$t->string('target_disk',80);$t->string('reference',1000);$t->json('manifest');$t->string('manifest_hash',64);$t->string('state',30)->default('Planned');$t->unsignedInteger('version')->default(1);$t->json('result')->nullable();$t->timestamp('started_at')->nullable();$t->timestamp('finished_at')->nullable();$t->timestamps();
        });
        Schema::create('evidence_copy_events',function(Blueprint $t): void {
            $t->id();$t->uuid('plan_id');$t->foreign('plan_id','copy_event_plan_fk')->references('id')->on('evidence_copy_plans')->restrictOnDelete();$t->string('event',40);$t->string('origin',20)->default('console');$t->json('summary');$t->timestamp('created_at');
        });
    }
    public function down(): void {Schema::dropIfExists('evidence_copy_events');Schema::dropIfExists('evidence_copy_plans');}
};
