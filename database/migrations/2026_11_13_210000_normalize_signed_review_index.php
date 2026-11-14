<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table='signed_review_records';$old='signed_review_records_progress_review_draft_id_withdrawn_at_index';
        if(Schema::hasTable($table) && Schema::hasIndex($table,$old) && !Schema::hasIndex($table,'review_parent_withdrawn_idx')){
            Schema::table($table,fn(Blueprint $t)=>$t->renameIndex($old,'review_parent_withdrawn_idx'));
        }
    }
    // Forward-only name normalisation: restoring the old name would be invalid on MySQL.
    public function down(): void {}
};
