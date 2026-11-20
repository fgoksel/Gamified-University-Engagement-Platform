<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Reset all points" (Task #102, UC-6.4; 27 October, L3 and S4): one row per reset by the system
 * admin. The ledger is never edited: a reset only records the last ledger row that no longer
 * counts, and who reset the points and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_resets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reset_by_id')->constrained('users')->restrictOnDelete();
            // That ledger row and every earlier one no longer count.
            $table->unsignedBigInteger('last_transaction_id');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_resets');
    }
};
