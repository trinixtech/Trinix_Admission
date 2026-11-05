<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_sources', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('status', 16)->default('active');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('import_mapping_templates', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('external_source_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->json('column_map');
            $table->json('value_map')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('external_source_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_round_id')->constrained()->restrictOnDelete();
            // Optional: limit the batch to one course (needed for course-scoped staff).
            $table->foreignId('course_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('channel', 8);
            $table->string('status', 24)->index();
            $table->string('original_filename')->nullable();
            $table->string('file_path')->nullable();
            $table->char('file_sha256', 64)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->json('headers')->nullable();
            $table->json('column_map')->nullable();
            $table->json('value_map')->nullable();
            foreach (['total', 'processed', 'valid', 'error', 'new', 'update', 'unchanged', 'duplicate', 'excluded', 'written'] as $count) {
                $table->unsignedInteger("rows_{$count}")->default(0);
            }
            $table->text('status_message')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['admission_round_id', 'file_sha256']);
            $table->unique(['external_source_id', 'idempotency_key']);
        });

        Schema::create('import_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw');
            $table->json('mapped')->nullable();
            $table->string('classification', 16)->nullable();
            $table->boolean('excluded')->default(false);
            $table->json('diff')->nullable();
            $table->unsignedBigInteger('application_id')->nullable()->index();
            $table->timestamp('written_at')->nullable();
            $table->timestamps();
            $table->index(['import_batch_id', 'classification']);
            $table->index(['import_batch_id', 'row_number']);
        });

        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_record_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('field', 64)->nullable();
            $table->string('message', 500);
            $table->string('severity', 8)->default('error');
            $table->timestamps();
            $table->index(['import_batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_errors');
        Schema::dropIfExists('import_records');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('import_mapping_templates');
        Schema::dropIfExists('external_sources');
    }
};
