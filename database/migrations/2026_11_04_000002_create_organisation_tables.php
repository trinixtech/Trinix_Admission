<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('short_name', 64)->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('short_name', 64)->nullable();
            $table->string('level', 16);
            $table->unsignedSmallInteger('duration_months');
            $table->string('entrance_exam', 32)->nullable();
            $table->string('status', 16)->default('active')->index();
            $table->timestamps();
        });

        Schema::create('branch_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['branch_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_courses');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('institutions');
    }
};
