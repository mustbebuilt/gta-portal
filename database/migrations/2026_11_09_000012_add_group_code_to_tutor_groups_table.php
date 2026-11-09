<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('tutor_groups') && !Schema::hasColumn('tutor_groups', 'group_code')) {
            Schema::table('tutor_groups', function (Blueprint $table) {
                $table->string('group_code', 50)->nullable()->after('group_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tutor_groups') && Schema::hasColumn('tutor_groups', 'group_code')) {
            Schema::table('tutor_groups', function (Blueprint $table) {
                $table->dropColumn('group_code');
            });
        }
    }
};

