<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('account_onboarding_tokens', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 16);
            $table->char('token_hash', 64);
            $table->unsignedInteger('auth_version');
            $table->foreignId('issuer_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('issuer_auth_version')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('expires_at');
            $table->primary(['user_id', 'purpose']);
        });
    }
    public function down(): void { Schema::dropIfExists('account_onboarding_tokens'); }
};
