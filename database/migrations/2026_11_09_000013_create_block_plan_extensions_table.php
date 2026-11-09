<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('blocks')) {
            Schema::table('blocks', function (Blueprint $table) {
                if (!Schema::hasColumn('blocks', 'start_date')) {
                    $table->date('start_date')->nullable()->after('nominal_hours');
                }
                if (!Schema::hasColumn('blocks', 'end_date')) {
                    $table->date('end_date')->nullable()->after('start_date');
                }
                if (!Schema::hasColumn('blocks', 'configured')) {
                    $table->boolean('configured')->default(true)->after('end_date');
                }
            });
        }

        if (!Schema::hasTable('block_change_requests')) {
            Schema::create('block_change_requests', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('apprentice_id');
                $table->unsignedTinyInteger('block_num');
                $table->string('request_type', 50)->default('early'); // early or dates
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->text('reason');
                $table->string('status', 50)->default('Requested'); // Requested, Approved, Rejected
                $table->text('decision_reason')->nullable();
                $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->foreign('apprentice_id')->references('apprentice_id')->on('apprentice')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('block_change_requests');
        if (Schema::hasTable('blocks')) {
            Schema::table('blocks', function (Blueprint $table) {
                if (Schema::hasColumn('blocks', 'configured')) {
                    $table->dropColumn('configured');
                }
                if (Schema::hasColumn('blocks', 'end_date')) {
                    $table->dropColumn('end_date');
                }
                if (Schema::hasColumn('blocks', 'start_date')) {
                    $table->dropColumn('start_date');
                }
            });
        }
    }
};

