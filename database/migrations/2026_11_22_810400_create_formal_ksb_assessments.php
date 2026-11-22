<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('formal_ksb_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apprentice_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->char('curriculum_key', 64);
            $table->string('code', 16);
            $table->string('outcome', 30);
            $table->date('assessed_on');
            $table->text('evidence_reference');
            $table->timestamps();
            $table->index(['apprentice_id', 'curriculum_key', 'assessed_on'], 'formal_ksb_curriculum_date');
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('formal_ksb_assessments')->exists()) {
            throw new RuntimeException('Preserve formal KSB assessment history before rollback.');
        }
        Schema::dropIfExists('formal_ksb_assessments');
    }
};
