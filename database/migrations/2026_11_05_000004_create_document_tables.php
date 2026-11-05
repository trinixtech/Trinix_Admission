<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('allowed_types');
            $table->unsignedInteger('max_size_kb')->default(5120);
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('document_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->restrictOnDelete();
            $table->foreignId('admission_cycle_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->boolean('required')->default(true);
            $table->json('condition')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['course_id', 'admission_cycle_id', 'document_type_id'], 'document_requirements_unique');
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('application_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('status', 16)->default('received');
            $table->timestamps();
            $table->unique(['application_id', 'document_type_id']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->foreignId('application_document_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->string('source', 8);
            $table->foreignId('import_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('source_url')->nullable();
            $table->string('scan_status', 12)->default('pending')->index();
            $table->timestamp('scanned_at')->nullable();
            $table->string('scan_result')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
            $table->unique(['application_document_id', 'version']);
        });

        Schema::create('document_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->restrictOnDelete();
            $table->string('decision', 16);
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at');
        });

        Schema::create('file_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 12);
            $table->string('ip', 45)->nullable();
            $table->timestamp('occurred_at')->index();
        });
    }

    public function down(): void
    {
        foreach (['file_access_logs', 'document_verifications', 'document_versions', 'application_documents', 'document_requirements', 'document_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
