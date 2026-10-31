<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Undo straight after applying (Task #94). An undone application keeps the status
     * "withdrawn", so every count of real applicants still leaves it out, and undone_at
     * tells the student screens to treat it as if the student never applied.
     */
    public function up(): void
    {
        Schema::table('event_applications', function (Blueprint $table) {
            $table->timestamp('undone_at')->nullable()->after('withdrawn_at');
        });
    }

    public function down(): void
    {
        Schema::table('event_applications', function (Blueprint $table) {
            $table->dropColumn('undone_at');
        });
    }
};
