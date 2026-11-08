<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('standards', function (Blueprint $table) {
            $table->increments('standard_id');
            $table->string('standard_code', 50)->unique(); // e.g. ST0482
            $table->string('title', 255);
            $table->unsignedTinyInteger('level')->default(2);
            $table->unsignedSmallInteger('duration_months')->default(30);
            $table->decimal('total_otj_target', 8, 2)->default(663.00);
            $table->timestamps();
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->increments('block_id');
            $table->unsignedInteger('standard_id')->nullable();
            $table->unsignedTinyInteger('block_num'); // 1 to 10 (+11/12 gateway)
            $table->string('title', 255);
            $table->text('primary_themes')->nullable();
            $table->unsignedSmallInteger('nominal_hours')->default(30);
            $table->timestamps();

            $table->foreign('standard_id')->references('standard_id')->on('standards')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('standards');
    }
};

