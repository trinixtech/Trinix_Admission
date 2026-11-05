<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 6 §14 (D28): slot order rules per stage. Added columns only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('selection_stages', function (Blueprint $table) {
            $table->json('slot_order')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('selection_stages', fn (Blueprint $t) => $t->dropColumn('slot_order'));
    }
};
