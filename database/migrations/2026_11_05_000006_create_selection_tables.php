<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selection_stages', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_cycle_id')->constrained()->restrictOnDelete();
            $table->string('code', 16);
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('remark_rule')->nullable();
            $table->decimal('outlier_threshold', 6, 2)->nullable();
            // Admin settings for the flow between stages (e.g. GD panel, then a separate PI panel).
            $table->boolean('show_previous_marks')->default(false);
            $table->decimal('min_previous_average', 6, 2)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->unique(['course_id', 'admission_cycle_id', 'code'], 'selection_stages_code_unique');
        });

        Schema::create('evaluation_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('selection_stage_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->decimal('max_marks', 6, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('interview_panels', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('admission_round_id')->constrained()->restrictOnDelete();
            $table->foreignId('selection_stage_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('panel_date')->index();
            $table->string('mode', 12);
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('venue')->nullable();
            $table->string('meeting_link', 500)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('interview_panel_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_panel_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 12);
            $table->foreignId('added_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'removed_at']);
        });

        Schema::create('interview_slots', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('interview_panel_id')->constrained()->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->timestamps();
            $table->index(['interview_panel_id', 'starts_at']);
        });

        Schema::create('interview_candidates', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('interview_slot_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('selection_stage_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_round_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('scheduled');
            $table->boolean('active')->nullable()->default(true);
            $table->foreignId('scheduled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('result_locked_at')->nullable();
            $table->foreignId('result_locked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['application_id', 'selection_stage_id', 'admission_round_id', 'active'], 'interview_candidates_one_active');
        });

        Schema::create('panel_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_panel_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->timestamp('declared_at');
            $table->timestamps();
        });

        Schema::create('interview_evaluations', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('interview_candidate_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluator_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 12)->default('draft');
            $table->text('remarks')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
            $table->unique(['interview_candidate_id', 'evaluator_id'], 'interview_evaluations_unique');
        });

        Schema::create('interview_evaluation_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_evaluation_id')->constrained()->restrictOnDelete();
            $table->foreignId('evaluation_criterion_id')->constrained('evaluation_criteria')->restrictOnDelete();
            $table->decimal('marks', 6, 2)->nullable();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->unique(['interview_evaluation_id', 'evaluation_criterion_id'], 'interview_scores_unique');
        });

        Schema::create('evaluation_score_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interview_evaluation_id')->constrained()->restrictOnDelete();
            $table->string('event', 12);
            $table->json('snapshot');
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
        });

    }

    public function down(): void
    {
        foreach (['evaluation_score_history', 'interview_evaluation_scores', 'interview_evaluations', 'panel_conflicts',
            'interview_candidates', 'interview_slots', 'interview_panel_members', 'interview_panels', 'evaluation_criteria', 'selection_stages',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
