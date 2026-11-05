<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('subject');
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('template_key', 64);
            $table->foreignId('application_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('to_hash', 64);
            $table->string('status', 12);
            $table->string('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        foreach (['notification_logs', 'notification_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
