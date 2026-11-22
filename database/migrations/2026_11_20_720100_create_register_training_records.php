<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel's migration ledger makes normal migrate reruns safe. Do not mask schema drift.
        Schema::create('register_training_records', function (Blueprint $t): void {
            $t->foreignId('training_session_id')->primary()->constrained('training_sessions')->restrictOnDelete();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('register_version');
            $t->unsignedInteger('version')->default(1);
            $t->longText('details'); // encrypted:array; subject IDs, coverage, exceptions and confirmation
            $t->longText('history'); // encrypted:array; before/after, actor, time, correction reason
            $t->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('register_training_records') && \Illuminate\Support\Facades\DB::table('register_training_records')->exists()) {
            throw new \RuntimeException('Training records exist. Retain the table and roll back application code only; export and reconcile data before any separately authorised destructive removal.');
        }
        Schema::dropIfExists('register_training_records');
    }
};
