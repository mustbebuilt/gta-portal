<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_bootstrap_states',function(Blueprint $t): void {
            $t->unsignedTinyInteger('id')->primary();
            $t->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('completed_at')->nullable();
        });
        DB::table('portal_bootstrap_states')->insert(['id'=>1]);
    }
    public function down(): void {Schema::dropIfExists('portal_bootstrap_states');}
};
