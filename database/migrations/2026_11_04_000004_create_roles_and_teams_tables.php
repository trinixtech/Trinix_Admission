<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('requires_two_factor')->default(false);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('module', 32);
            $table->string('description');
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('permission_id')->constrained()->restrictOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('admission_cycle_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('role_conflicts', function (Blueprint $table) {
            $table->foreignId('role_a_id')->constrained('roles')->restrictOnDelete();
            $table->foreignId('role_b_id')->constrained('roles')->restrictOnDelete();
            $table->primary(['role_a_id', 'role_b_id']);
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->string('type', 32)->index();
            $table->string('name');
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('course_offering_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('admission_cycle_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->text('approval_powers')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('member_role', 16);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->foreignId('added_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['team_id', 'removed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('role_conflicts');
        Schema::dropIfExists('user_role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
