<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('staff_duties',function(Blueprint $table):void {
            $table->boolean('maths_tutor')->default(false);
            $table->boolean('english_tutor')->default(false);
        });
    }
    public function down(): void {
        Schema::table('staff_duties',fn(Blueprint $table)=>$table->dropColumn(['maths_tutor','english_tutor']));
    }
};
