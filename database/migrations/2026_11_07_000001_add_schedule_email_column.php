<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fix the schedule first, email it later: when each candidate was told their slot. Added column only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interview_candidates', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('interview_candidates', fn (Blueprint $t) => $t->dropColumn('notified_at'));
    }
};
