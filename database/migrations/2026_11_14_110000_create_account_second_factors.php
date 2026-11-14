<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_second_factors', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->text('secret');
            $table->timestamp('setup_expires_at')->nullable();
            $table->string('setup_binding', 64)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('last_step')->nullable();
            $table->json('recovery_hashes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('account_second_factors')->whereNotNull('confirmed_at')->exists()) {
            throw new \LogicException('Confirmed two-step accounts remain. Do not remove their authentication settings by rolling back this migration.');
        }
        Schema::dropIfExists('account_second_factors');
    }
};
