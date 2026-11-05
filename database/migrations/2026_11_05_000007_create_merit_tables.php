<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 5: merit formulas (versioned, approved) and merit lists (one per course and round, versioned). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merit_formulas', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_cycle_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status', 16)->default('draft');
            $table->string('name');
            $table->json('rounding');
            $table->json('tie_breaks');
            $table->json('inclusion');
            $table->string('source_reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'admission_cycle_id', 'version'], 'merit_formulas_version_unique');
        });

        Schema::create('merit_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merit_formula_id')->constrained()->restrictOnDelete();
            $table->string('key', 40);
            $table->string('label', 120);
            $table->string('source', 24);
            $table->string('source_ref', 64)->nullable();
            $table->string('method', 20);
            $table->json('params')->nullable();
            $table->decimal('weight', 10, 4);
            $table->decimal('cap', 10, 4)->nullable();
            $table->string('when_missing', 8)->default('exclude');
            $table->json('minimum')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['merit_formula_id', 'key'], 'merit_components_key_unique');
        });

        Schema::create('merit_lists', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('merit_formula_id')->constrained()->restrictOnDelete();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_round_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status', 16)->default('simulated')->index();
            $table->json('counts')->nullable();
            $table->char('inputs_hash', 64);
            $table->foreignId('run_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('run_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('stale_since')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'admission_round_id', 'version'], 'merit_lists_version_unique');
        });

        Schema::create('merit_list_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merit_list_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->boolean('ranked');
            $table->text('reason')->nullable();
            $table->decimal('total', 12, 4)->nullable();
            $table->unsignedInteger('overall_rank')->nullable();
            $table->unsignedInteger('category_rank')->nullable();
            $table->unsignedInteger('pwbd_rank')->nullable();
            $table->boolean('tie_shared')->default(false);
            $table->string('decided_by', 64)->nullable();
            $table->json('inputs');
            $table->boolean('manual')->default(false);
            $table->text('manual_reason')->nullable();
            $table->foreignId('manual_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['merit_list_id', 'application_id'], 'merit_entries_unique');
            $table->index(['merit_list_id', 'overall_rank'], 'merit_entries_rank');
        });

        Schema::create('merit_component_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merit_list_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('merit_component_id')->constrained()->restrictOnDelete();
            $table->decimal('raw_value', 14, 4)->nullable();
            $table->decimal('points', 12, 4)->nullable();
            $table->unique(['merit_list_entry_id', 'merit_component_id'], 'merit_scores_unique');
        });
    }

    public function down(): void
    {
        foreach (['merit_component_scores', 'merit_list_entries', 'merit_lists', 'merit_components', 'merit_formulas'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
