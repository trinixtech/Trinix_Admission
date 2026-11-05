<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });

        Schema::create('admission_cycles', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('status', 16)->default('draft')->index();
            $table->timestamps();
        });

        Schema::create('course_offerings', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('admission_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_course_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sanctioned_intake');
            $table->string('status', 16)->default('draft');
            $table->timestamps();
            $table->unique(['admission_cycle_id', 'branch_course_id']);
        });

        Schema::create('admission_rounds', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('admission_cycle_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('type', 16);
            $table->string('name');
            $table->string('status', 16)->default('draft');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('close_reason')->nullable();
            $table->timestamps();
            $table->unique(['admission_cycle_id', 'number']);
        });

        Schema::create('admission_round_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_round_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_offering_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['admission_round_id', 'course_offering_id'], 'round_offerings_round_offering_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_round_offerings');
        Schema::dropIfExists('admission_rounds');
        Schema::dropIfExists('course_offerings');
        Schema::dropIfExists('admission_cycles');
        Schema::dropIfExists('academic_years');
    }
};
