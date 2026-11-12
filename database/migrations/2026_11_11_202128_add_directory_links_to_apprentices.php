<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('subject_scope', 10)->nullable();
            $t->unsignedInteger('record_version')->default(1);
        });
        Schema::create('employers', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40)->unique();
            $t->string('name', 200);
            $t->string('contact_name', 150)->nullable();
            $t->string('contact_email', 254)->nullable();
            $t->string('contact_phone', 50)->nullable();
            $t->string('size_band', 20)->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('record_version')->default(1);
            $t->timestamps();
        });
        Schema::create('programmes', function (Blueprint $t) {
            $t->id();
            $t->string('standard_code', 40);
            $t->string('version', 30);
            $t->string('name', 200);
            $t->unsignedTinyInteger('level');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('record_version')->default(1);
            $t->timestamps();
            $t->unique(['standard_code', 'version']);
        });
        Schema::create('tutor_groups', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 40)->unique();
            $t->string('name', 200);
            $t->foreignId('programme_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('tutor_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('record_version')->default(1);
            $t->timestamps();
        });
        Schema::table('apprentices', function (Blueprint $t) {
            $t->foreignId('employer_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('programme_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('tutor_group_id')->nullable()->constrained()->restrictOnDelete();
            foreach (['mentor_id', 'careers_adviser_id', 'maths_tutor_id', 'english_tutor_id'] as $field) {
                $t->foreignId($field)->nullable()->constrained('users')->restrictOnDelete();
            }
        });
        Schema::create('directory_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $t->string('kind', 20);
            $t->unsignedBigInteger('record_id');
            $t->string('event', 20);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_events');
        Schema::table('apprentices', function (Blueprint $t) {
            foreach (['employer_id', 'programme_id', 'tutor_group_id', 'mentor_id', 'careers_adviser_id', 'maths_tutor_id', 'english_tutor_id'] as $field) {
                $t->dropConstrainedForeignId($field);
            }
        });
        Schema::dropIfExists('tutor_groups');
        Schema::dropIfExists('programmes');
        Schema::dropIfExists('employers');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['subject_scope', 'record_version']));
    }
};
