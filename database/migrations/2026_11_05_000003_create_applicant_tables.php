<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('external_source_id')->constrained()->restrictOnDelete();
            $table->string('full_name')->index();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('email')->nullable()->index();
            $table->string('mobile', 16)->nullable()->index();
            $table->string('alternate_mobile', 16)->nullable();
            $table->string('nationality', 64)->nullable();
            $table->string('domicile_state', 64)->nullable();
            $table->string('guardian_name')->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('pincode', 10)->nullable();
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('applicant_id')->constrained()->restrictOnDelete();
            $table->foreignId('external_source_id')->constrained()->restrictOnDelete();
            $table->string('portal_application_no', 64);
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_cycle_id')->constrained()->restrictOnDelete();
            $table->string('category', 16);
            $table->boolean('pwbd')->default(false);
            $table->string('pwbd_type', 64)->nullable();
            $table->unsignedSmallInteger('work_experience_months')->nullable();
            $table->json('extra')->nullable();
            $table->string('status', 24)->default('imported');
            $table->foreignId('first_import_batch_id')->nullable()->constrained('import_batches')->restrictOnDelete();
            $table->foreignId('last_import_batch_id')->nullable()->constrained('import_batches')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['external_source_id', 'portal_application_no', 'course_id', 'admission_cycle_id'], 'applications_identity_unique');
            $table->index('portal_application_no');
            $table->index(['course_id', 'admission_cycle_id', 'status']);
        });

        Schema::create('application_entrance_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->unique()->constrained()->restrictOnDelete();
            $table->string('exam', 16);
            $table->string('entrance_application_no', 64)->index();
            $table->decimal('score', 10, 4)->nullable();
            $table->decimal('percentile', 7, 4)->nullable();
            $table->json('sectional')->nullable();
            $table->timestamps();
        });

        Schema::create('application_academics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->string('level', 16);
            $table->string('board_or_university')->nullable();
            $table->string('qualification')->nullable();
            $table->unsignedSmallInteger('year_of_passing')->nullable();
            $table->string('marks_type', 12)->nullable();
            $table->decimal('marks', 6, 2)->nullable();
            $table->decimal('cgpa_scale', 4, 2)->nullable();
            $table->timestamps();
            $table->unique(['application_id', 'level']);
        });

        Schema::create('application_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('rank');
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['application_id', 'rank']);
            $table->unique(['application_id', 'branch_id']);
        });

        Schema::create('application_round_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_round_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('added_at');
            $table->timestamps();
            $table->unique(['application_id', 'admission_round_id'], 'round_participation_unique');
        });

        Schema::create('application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->index(['application_id', 'occurred_at']);
        });

        Schema::create('application_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->string('field', 64);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
            $table->index(['application_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        foreach (['application_corrections', 'application_status_history', 'application_round_participations', 'application_preferences',
            'application_academics', 'application_entrance_details', 'applications', 'applicants'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
