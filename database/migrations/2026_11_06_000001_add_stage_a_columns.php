<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Stage A (D26): faculty details, applicants' GD/PI centre preferences, the admin's stage-result formula. Added columns only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('designation', 120)->nullable();
            $table->string('department', 120)->nullable();
        });
        Schema::table('applications', function (Blueprint $table) {
            $table->json('interview_centres')->nullable();
        });
        Schema::table('selection_stages', function (Blueprint $table) {
            $table->string('result_formula', 255)->default('AVG');
            $table->unsignedSmallInteger('formula_min_members')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('selection_stages', fn (Blueprint $t) => $t->dropColumn(['result_formula', 'formula_min_members']));
        Schema::table('applications', fn (Blueprint $t) => $t->dropColumn('interview_centres'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['designation', 'department']));
    }
};
